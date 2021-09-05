import hashlib
import io
import os
import pathlib
import json
from datetime import datetime
from django.db import models
from django.conf import settings

from django.contrib.postgres.search import SearchVector
from django.contrib.postgres.search import SearchVectorField
from django.contrib.postgres.indexes import GinIndex

from jinja2 import Template as JinjaTemplate


class Spreadsheet(models.Model):
    """An uploaded CSV file"""

    name = models.CharField(max_length=255, unique=True)
    object_name = models.CharField(max_length=255)
    key_field = models.TextField()
    last_updated = models.DateTimeField(auto_now=True)

    def clean_documents(self, config):
        # return a reduced list of documents based on permissions defined in config
        # (SheetConfig instance)
        new_documents = {}
        for value in (
            Value.objects.filter(
                document__in=config.valid_ids.all(), field__in=config.valid_fields.all()
            )
            .prefetch_related("field")
            .prefetch_related("document")
            .all()
        ):
            if value.document.id not in new_documents:
                new_documents[value.document.id] = {"key": value.document.key}
            new_documents[value.document.id][value.field.name] = value.to_python()
        return new_documents.values()

    @classmethod
    def from_json(cls, name, object_name, key_field, file):
        file_text = file.read().decode()
        data = json.loads(file_text)
        if Spreadsheet.objects.filter(name=name).exists():
            sheet = Spreadsheet.objects.get(name=name)
        else:
            sheet = cls(name=name, object_name=object_name, key_field=key_field)
            sheet.save()

        fields = {}
        documents = []
        values = []
        sheet_values = {}
        for value in (
            Value.objects.filter(document__in=Document.objects.filter(sheet=sheet))
            .prefetch_related("field")
            .prefetch_related("document")
        ):
            if value.document not in sheet_values:
                sheet_values[value.document] = {}
            if value.field not in sheet_values[value.document]:
                sheet_values[value.document][value.field] = value

        for document_number, document_in in enumerate(data):
            if document_number == 0:
                # Generate fields, but only on the first proposal
                for field_name in document_in.keys():
                    field, created = Field.objects.update_or_create(
                        name=field_name,
                        sheet=sheet,
                    )
                    field.save()
                    fields[field_name] = field

            db_document, created = Document.objects.update_or_create(
                sheet=sheet,
                key=document_in[sheet.key_field],
            )
            db_document.save()
            documents.append(db_document)
            for field_name, value_value in document_in.items():
                jsoned_value_value = json.dumps(value_value)
                if (
                    db_document in sheet_values
                    and fields[field_name] in sheet_values[db_document]
                ):
                    value = sheet_values[db_document][fields[field_name]]
                    # Only update for values whose value has changed
                    if value.original != jsoned_value_value:
                        sheet.last_updated = datetime.now
                        value.original = jsoned_value_value
                        value.latest = jsoned_value_value
                        value.save()
                else:
                    sheet.last_updated = datetime.now
                    value = Value(
                        field=fields[field_name],
                        original=jsoned_value_value,
                        latest=jsoned_value_value,
                        db_document=db_document,
                    )
                    values.append(value)

        Value.objects.bulk_create(values)

        # In case last_updated got set
        sheet.save()
        return sheet, documents


class Wiki(models.Model):
    """Represents a Wiki that uses this torque instance.  Identified
    by the wiki_key, which has the corresponding variable
    $wgTorqueDataConnectWikiKey in the mediawiki extension.

    Not that this does not connect to any spreadsheet, because a wiki
    can be connected to multiple spreadsheets through SheetConfigs"""

    wiki_key = models.TextField()
    server = models.TextField(null=True)
    script_path = models.TextField(null=True)
    username = models.TextField(null=True)
    password = models.TextField(null=True)


class SheetConfig(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="configs",
    )
    wiki = models.ForeignKey(
        Wiki, on_delete=models.CASCADE, related_name="configs", null=True
    )
    group = models.TextField()

    # This field holds a hash of the valid ids/valid fields for this group
    # The reason is to quickly check to see if this group has been created
    # identically before now.  Updating a group is an expensive operation
    # due to the search cache needing to be re-created andd re-indexed, so
    # this is a quick way to see if we actually need to do that.
    search_cache_sha = models.CharField(max_length=255, default="")

    # This is the second part of the above field.  When resetting the config,
    # we need to note which groups should be removed at the end in the case
    # that they were removed from the configuration.
    #
    # This is highly NOT threadsafe, and will probably cause annoying problems
    # if two people are messing around with the configurations at the same time.
    #
    # Fortunately, that is highly unlikely, and the only real downside is that
    # the search cache has to be re-indexed, which is not a catastrophic error.
    in_config = models.BooleanField(default=False)

    search_cache_dirty = models.BooleanField(default=False)

    def rebuild_search_index(self):
        SearchCacheDocument.objects.filter(sheet_config=self).delete()
        sc_documents = []
        for document_dict in self.sheet.clean_documents(self):
            document = Document.objects.get(key=document_dict["key"], sheet=self.sheet)
            if not SearchCacheDocument.objects.filter(
                document=document, sheet_config=self
            ).exists():
                sc_documents.append(
                    SearchCacheDocument(
                        document=document,
                        sheet=self.sheet,
                        wiki=self.wiki,
                        group=self.group,
                        sheet_config=self,
                        data=" ".join(list(map(str, document_dict.values()))),
                    )
                )

        SearchCacheDocument.objects.bulk_create(sc_documents)
        SearchCacheDocument.objects.filter(sheet_config=self).update(
            data_vector=SearchVector("data")
        )


class Document(models.Model):
    """A single document in a spreadsheet"""

    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="documents"
    )
    key = models.TextField()
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_ids")

    def to_dict(self, config):
        new_document = {"key": self.key}
        valid_fields = config.valid_fields.all()
        for value in self.values.filter(field__in=valid_fields).select_related("field"):
            new_document[value.field.name] = value.to_python()

        return new_document

    def __getitem__(self, key):
        return self.data[key]

    def items(self):
        return self.data.items()

    def clone(self):
        return Document(sheet=self.sheet, key=self.key)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a document can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["sheet", "key"], name="unique_key"),
        ]


class Field(models.Model):
    name = models.CharField(max_length=255)
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="fields",
    )
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_fields")


class Value(models.Model):
    field = models.ForeignKey(Field, on_delete=models.CASCADE, related_name="values")
    original = models.TextField(null=True)
    latest = models.TextField(null=True)
    document = models.ForeignKey(
        Document, on_delete=models.CASCADE, related_name="values"
    )

    def to_python(self):
        return json.loads(self.latest)


class ValueEdit(models.Model):
    value = models.ForeignKey(Value, on_delete=models.CASCADE, related_name="edits")
    updated = models.TextField()
    message = models.CharField(max_length=255, null=True)
    edit_timestamp = models.DateTimeField(auto_now=True)
    approval_timestamp = models.DateTimeField(null=True)
    sheet = models.ForeignKey(Spreadsheet, on_delete=models.CASCADE)
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    approval_code = models.CharField(max_length=255, null=True)


class Template(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="templates"
    )
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    type = models.TextField(null=True)  # enumeration?
    name = models.TextField()
    is_default = models.BooleanField(default=False)
    template_file = models.FileField(upload_to="templates/", null=False, blank=False)

    def get_file_contents(self):
        return b"".join(self.template_file.open().readlines()).decode("utf-8")

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["sheet", "type", "name"], name="unique_template"
            ),
        ]


class TableOfContents(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="tables_of_contents",
    )
    name = models.TextField()
    json_file = models.TextField()
    template = models.OneToOneField(
        Template, on_delete=models.CASCADE, primary_key=True
    )
    raw = models.BooleanField(default=False)

    def render_to_mwiki(self, sheet_config):
        sheet = sheet_config.sheet
        documents = sheet.clean_documents(sheet_config)

        data = json.loads(self.json_file)
        data[sheet.name] = {
            document[sheet.key_field]: document for document in documents
        }

        toc_templates = Template.objects.filter(
            sheet=sheet,
            wiki=sheet_config.wiki,
            type="TOC",
        )
        line_template = toc_templates.get(is_default=True)

        line_template_contents = line_template.template_file.read().decode("utf-8")
        template_contents = self.template.template_file.read().decode("utf-8")

        data["toc_lines"] = {
            document[sheet.key_field]: JinjaTemplate(line_template_contents).render(
                {sheet.object_name: document}
            )
            for document in documents
        }
        return JinjaTemplate(template_contents).render(data)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a document can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["sheet", "name"], name="unique_toc"),
        ]


class Attachment(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    name = models.TextField()
    document = models.ForeignKey(
        Document, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    permissions_field = models.ForeignKey(
        Field, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    file = models.FileField(upload_to="attachments")

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["sheet", "document", "name"], name="unique_attachment"
            ),
        ]


class User(models.Model):
    username = models.TextField()


class Permission(models.Model):
    permission_type = models.CharField(max_length=255)


class SearchCacheDocument(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
    )
    sheet_config = models.ForeignKey(SheetConfig, on_delete=models.CASCADE)
    document = models.ForeignKey(Document, on_delete=models.CASCADE)
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    group = models.TextField()
    data = models.TextField()
    data_vector = SearchVectorField(null=True)
    dirty = models.BooleanField(default=False)

    class Meta:
        indexes = (GinIndex(fields=["data_vector"]),)


class TableOfContentsCache(models.Model):
    sheet_config = models.ForeignKey(
        SheetConfig, on_delete=models.CASCADE, related_name="cached_tocs"
    )
    toc = models.ForeignKey(TableOfContents, on_delete=models.CASCADE)
    dirty = models.BooleanField(default=True)
    rendered_html = models.TextField(null=True)

    def rebuild(self):
        import mwclient

        if self.sheet_config.wiki.server:
            if self.toc.raw:
                self.rendered_html = self.toc.render_to_mwiki(self.sheet_config)
                self.save()
            else:
                (scheme, server) = self.sheet_config.wiki.server.split("://")
                site = mwclient.Site(
                    server, self.sheet_config.wiki.script_path + "/", scheme=scheme
                )
                site.login(
                    self.sheet_config.wiki.username, self.sheet_config.wiki.password
                )

                rendered_data = self.toc.render_to_mwiki(self.sheet_config)
                self.rendered_html = site.api(
                    "parse", text=rendered_data, contentmodel="wikitext", prop="text"
                )["parse"]["text"]["*"]
                self.save()
        else:
            self.rendered_html = ""
            self.save()

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["sheet_config", "toc"], name="unique_toc_cache"
            ),
        ]
