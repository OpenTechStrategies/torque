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

from torque import utils

jinja_env = utils.get_jinja_env()


class Collection(models.Model):
    """An uploaded CSV file"""

    name = models.CharField(max_length=255, unique=True)
    object_name = models.CharField(max_length=255)
    key_field = models.TextField()
    last_updated = models.DateTimeField(auto_now=True)

    def clean_documents(self, config):
        # return a reduced list of documents based on permissions defined in config
        # (WikiConfig instance)
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
        if Collection.objects.filter(name=name).exists():
            collection = Collection.objects.get(name=name)
        else:
            collection = cls(name=name)

        collection.key_field = key_field
        collection.object_name = object_name
        collection.save()

        fields = {}
        documents = []
        values = []
        collection_values = {}
        for value in (
            Value.objects.filter(
                document__in=Document.objects.filter(collection=collection)
            )
            .prefetch_related("field")
            .prefetch_related("document")
        ):
            if value.document not in collection_values:
                collection_values[value.document] = {}
            if value.field not in collection_values[value.document]:
                collection_values[value.document][value.field] = value

        collection.fields.update(attached=False)

        for document_number, document_in in enumerate(data):
            if document_number == 0:
                # Generate fields, but only on the first proposal
                for field_name in document_in.keys():
                    field, created = Field.objects.update_or_create(
                        name=field_name,
                        collection=collection,
                    )
                    field.attached = True
                    field.save()
                    fields[field_name] = field

            db_document, created = Document.objects.update_or_create(
                collection=collection,
                key=document_in[collection.key_field],
            )
            db_document.save()
            documents.append(db_document)
            for field_name, value_value in document_in.items():
                jsoned_value_value = json.dumps(value_value)
                if (
                    db_document in collection_values
                    and fields[field_name] in collection_values[db_document]
                ):
                    value = collection_values[db_document][fields[field_name]]
                    # Only update for values whose value has changed
                    if value.original != jsoned_value_value:
                        collection.last_updated = datetime.now
                        value.original = jsoned_value_value
                        value.latest = jsoned_value_value
                        value.save()
                else:
                    collection.last_updated = datetime.now
                    value = Value(
                        field=fields[field_name],
                        original=jsoned_value_value,
                        latest=jsoned_value_value,
                        document=db_document,
                    )
                    values.append(value)

        Value.objects.bulk_create(values)

        # In case last_updated got set
        collection.save()
        return collection, documents


class Wiki(models.Model):
    """Represents a Wiki that uses this torque instance.  Identified
    by the wiki_key, which has the corresponding variable
    $wgTorqueWikiKey in the mediawiki extension.

    Not that this does not connect to any collection, because a wiki
    can be connected to multiple collections through WikiConfigs"""

    wiki_key = models.TextField()
    server = models.TextField(null=True)
    script_path = models.TextField(null=True)
    username = models.TextField(null=True)
    password = models.TextField(null=True)


class WikiConfig(models.Model):
    collection = models.ForeignKey(
        Collection,
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

    cache_dirty = models.BooleanField(default=False)

    def rebuild_search_index(self):
        from django.conf import settings

        SearchCacheDocument.objects.filter(wiki_config=self).delete()
        sc_documents = []
        for document_dict in self.collection.clean_documents(self):
            document = Document.objects.get(
                key=document_dict["key"], collection=self.collection
            )
            filtered_data = {}
            for filter in settings.TORQUE_FILTERS:
                filtered_data[filter.name()] = filter.document_value(document_dict)

            if not SearchCacheDocument.objects.filter(
                document=document, wiki_config=self
            ).exists():
                sc_documents.append(
                    SearchCacheDocument(
                        document=document,
                        collection=self.collection,
                        wiki=self.wiki,
                        group=self.group,
                        wiki_config=self,
                        filtered_data=filtered_data,
                        data=" ".join(list(map(str, document_dict.values()))),
                    )
                )

        SearchCacheDocument.objects.bulk_create(sc_documents)
        SearchCacheDocument.objects.filter(wiki_config=self).update(
            data_vector=SearchVector("data")
        )

    def rebuild_template_cache(self):
        TemplateCacheDocument.objects.filter(wiki_config=self).delete()
        template_documents = []
        if Template.objects.filter(
            wiki=self.wiki, type="CSV", is_default=True
        ).exists():
            csv_template = Template.objects.get(
                wiki=self.wiki, type="CSV", is_default=True
            )
            jinja_template = jinja_env.from_string(
                csv_template.template_file.read().decode("utf-8")
            )
            for document_dict in self.collection.clean_documents(self):
                document = Document.objects.get(
                    key=document_dict["key"], collection=self.collection
                )

                if not TemplateCacheDocument.objects.filter(
                    document=document, wiki_config=self, template=csv_template
                ).exists():
                    template_documents.append(
                        TemplateCacheDocument(
                            document=document,
                            wiki_config=self,
                            template=csv_template,
                            rendered_text=jinja_template.render(
                                {self.collection.object_name: document_dict}
                            ),
                        )
                    )

            TemplateCacheDocument.objects.bulk_create(template_documents)


class Document(models.Model):
    """A single document in a collection"""

    collection = models.ForeignKey(
        Collection, on_delete=models.CASCADE, related_name="documents"
    )
    key = models.TextField()
    wiki_config = models.ManyToManyField(WikiConfig, related_name="valid_ids")

    def to_dict(self, config):
        new_document = {"key": self.key}
        valid_fields = config.valid_fields.all()
        for value in self.values.filter(field__in=valid_fields).select_related("field"):
            new_document[value.field.name] = value.to_python()

        return new_document

    def __getitem__(self, key):
        try:
            return self.values.get(field__name=key).to_python()
        except Value.DoesNotExist:
            return None

    def clone(self):
        return Document(collection=self.collection, key=self.key)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a document can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["collection", "key"], name="unique_key"),
        ]


class Field(models.Model):
    name = models.CharField(max_length=255)
    collection = models.ForeignKey(
        Collection,
        on_delete=models.CASCADE,
        related_name="fields",
    )
    wiki_config = models.ManyToManyField(WikiConfig, related_name="valid_fields")

    # Attached here represents fields that are actually attached to a collection.
    #
    # When uploading a collection, if fields have been dropped off, then they are
    # no longer actively attached.  Instead of just removing them right away, and
    # losing any Value or ValueEdits associated, we just mark them as unattached,
    # and then we can later clean them up if we want.
    attached = models.BooleanField(default=True)


class Value(models.Model):
    field = models.ForeignKey(Field, on_delete=models.CASCADE, related_name="values")
    original = models.TextField(null=True)
    latest = models.TextField(null=True)
    document = models.ForeignKey(
        Document, on_delete=models.CASCADE, related_name="values"
    )

    def to_python(self):
        # For legacy purposes, we return the string representation
        # if it's not valid json in the database.  Because some old
        # data may still be in the database that isn't overwritten,
        # we want to be able to soldier on.
        try:
            return json.loads(self.latest)
        except json.JSONDecodeError:
            if self.latest:
                return self.latest
            else:
                return ""


class ValueEdit(models.Model):
    value = models.ForeignKey(Value, on_delete=models.CASCADE, related_name="edits")
    updated = models.TextField()
    message = models.CharField(max_length=255, null=True)
    edit_timestamp = models.DateTimeField(auto_now=True)
    approval_timestamp = models.DateTimeField(null=True)
    collection = models.ForeignKey(Collection, on_delete=models.CASCADE)
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    approval_code = models.CharField(max_length=255, null=True)


class Template(models.Model):
    collection = models.ForeignKey(
        Collection, on_delete=models.CASCADE, related_name="templates"
    )
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    type = models.TextField(null=True)  # enumeration?
    name = models.TextField()
    is_default = models.BooleanField(default=False)
    template_file = models.FileField(upload_to="templates/", null=False, blank=False)

    # When resetting the config, we need to note which templates should be removed at
    # the end in the case that they were removed from the configuration.
    #
    # This is highly NOT threadsafe, and will probably cause annoying problems
    # if two people are messing around with the configurations at the same time.
    #
    # Fortunately, that is highly unlikely, and the only real downside is that
    # the tempate cache needs to be reindexed, which is not the worst
    in_config = models.BooleanField(default=False)

    def get_file_contents(self):
        return b"".join(self.template_file.open().readlines()).decode("utf-8")

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["collection", "wiki", "type", "name"], name="unique_template"
            ),
        ]


class TableOfContents(models.Model):
    collection = models.ForeignKey(
        Collection,
        on_delete=models.CASCADE,
        related_name="tables_of_contents",
    )
    name = models.TextField()
    json_file = models.TextField()
    template = models.OneToOneField(
        Template, on_delete=models.CASCADE, primary_key=True
    )
    raw = models.BooleanField(default=False)

    def render_to_mwiki(self, wiki_config):
        collection = wiki_config.collection
        documents = collection.clean_documents(wiki_config)

        data = json.loads(self.json_file)
        data[collection.name] = {
            document[collection.key_field]: document for document in documents
        }

        toc_templates = Template.objects.filter(
            collection=collection,
            wiki=wiki_config.wiki,
            type="TOC",
        )
        line_template = toc_templates.get(is_default=True)

        line_template_contents = line_template.template_file.read().decode("utf-8")
        template_contents = self.template.template_file.read().decode("utf-8")

        data["toc_lines"] = {
            document[collection.key_field]: jinja_env.from_string(
                line_template_contents
            ).render({collection.object_name: document})
            for document in documents
        }
        return jinja_env.from_string(template_contents).render(data)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a document can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["collection", "name"], name="unique_toc"),
        ]


class Attachment(models.Model):
    collection = models.ForeignKey(
        Collection, on_delete=models.CASCADE, related_name="attachments", default=None
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
                fields=["collection", "document", "name"], name="unique_attachment"
            ),
        ]


class User(models.Model):
    username = models.TextField()


class Permission(models.Model):
    permission_type = models.CharField(max_length=255)


class SearchCacheDocument(models.Model):
    collection = models.ForeignKey(
        Collection,
        on_delete=models.CASCADE,
    )
    wiki_config = models.ForeignKey(WikiConfig, on_delete=models.CASCADE)
    document = models.ForeignKey(Document, on_delete=models.CASCADE)
    wiki = models.ForeignKey(Wiki, on_delete=models.CASCADE, null=True)
    group = models.TextField()
    data = models.TextField()
    filtered_data = models.JSONField(null=True)
    data_vector = SearchVectorField(null=True)
    dirty = models.BooleanField(default=False)

    class Meta:
        indexes = (GinIndex(fields=["data_vector"]),)


class TableOfContentsCache(models.Model):
    wiki_config = models.ForeignKey(
        WikiConfig, on_delete=models.CASCADE, related_name="cached_tocs"
    )
    toc = models.ForeignKey(TableOfContents, on_delete=models.CASCADE)
    dirty = models.BooleanField(default=True)
    rendered_html = models.TextField(null=True)

    def rebuild(self):
        import mwclient

        if self.wiki_config.wiki.server:
            if self.toc.raw:
                self.rendered_html = self.toc.render_to_mwiki(self.wiki_config)
                self.save()
            else:
                (scheme, server) = self.wiki_config.wiki.server.split("://")
                site = mwclient.Site(
                    server, self.wiki_config.wiki.script_path + "/", scheme=scheme
                )
                site.login(
                    self.wiki_config.wiki.username, self.wiki_config.wiki.password
                )

                rendered_data = self.toc.render_to_mwiki(self.wiki_config)
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
                fields=["wiki_config", "toc"], name="unique_toc_cache"
            ),
        ]


class CsvSpecification(models.Model):
    fields = models.JSONField()
    documents = models.ManyToManyField(Document)
    name = models.TextField()
    filename = models.TextField()


class TemplateCacheDocument(models.Model):
    document = models.ForeignKey(Document, on_delete=models.CASCADE)
    wiki_config = models.ForeignKey(WikiConfig, on_delete=models.CASCADE)
    template = models.ForeignKey(Template, on_delete=models.CASCADE)
    rendered_text = models.TextField(null=True)
