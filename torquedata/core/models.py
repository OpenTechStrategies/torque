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
    key_column = models.TextField()
    last_updated = models.DateTimeField(auto_now=True)

    def clean_rows(self, config):
        # return a reduced list of rows based on permissions defined in config
        # (SheetConfig instance)
        new_rows = {}
        for value in (
            Value.objects.filter(
                row__in=config.valid_ids.all(), column__in=config.valid_columns.all()
            )
            .prefetch_related("column")
            .prefetch_related("row")
            .all()
        ):
            if value.row.id not in new_rows:
                new_rows[value.row.id] = {"key": value.row.key}
            new_rows[value.row.id][value.column.name] = value.to_python()
        return new_rows.values()

    @classmethod
    def from_json(cls, name, object_name, key_column, file):
        file_text = file.read().decode()
        data = json.loads(file_text)
        if Spreadsheet.objects.filter(name=name).exists():
            sheet = Spreadsheet.objects.get(name=name)
        else:
            sheet = cls(name=name, object_name=object_name, key_column=key_column)
            sheet.save()

        cols = {}
        rows = []
        values = []
        sheet_values = {}
        for value in (
            Value.objects.filter(row__in=Row.objects.filter(sheet=sheet))
            .prefetch_related("column")
            .prefetch_related("row")
        ):
            if value.row not in sheet_values:
                sheet_values[value.row] = {}
            if value.column not in sheet_values[value.row]:
                sheet_values[value.row][value.column] = value

        for row_number, row_in in enumerate(data):
            if row_number == 0:
                # Generate columns, but only on the first proposal
                for col_name in row_in.keys():
                    col, created = Column.objects.update_or_create(
                        name=col_name,
                        sheet=sheet,
                    )
                    col.save()
                    cols[col_name] = col

            db_row, created = Row.objects.update_or_create(
                sheet=sheet,
                key=row_in[sheet.key_column],
            )
            db_row.save()
            rows.append(db_row)
            for col_name, value_value in row_in.items():
                jsoned_value_value = json.dumps(value_value)
                if db_row in sheet_values and cols[col_name] in sheet_values[db_row]:
                    value = sheet_values[db_row][cols[col_name]]
                    # Only update for values whose value has changed
                    if value.original != jsoned_value_value:
                        sheet.last_updated = datetime.now
                        value.original = jsoned_value_value
                        value.latest = jsoned_value_value
                        value.save()
                else:
                    sheet.last_updated = datetime.now
                    value = Value(
                        column=cols[col_name],
                        original=jsoned_value_value,
                        latest=jsoned_value_value,
                        db_row=db_row,
                    )
                    values.append(value)

        Value.objects.bulk_create(values)

        # In case last_updated got set
        sheet.save()
        return sheet, rows


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

    # This field holds a hash of the valid ids/valid columns for this group
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
        SearchCacheRow.objects.filter(sheet_config=self).delete()
        sc_rows = []
        for row_dict in self.sheet.clean_rows(self):
            row = Row.objects.get(key=row_dict["key"], sheet=self.sheet)
            if not SearchCacheRow.objects.filter(row=row, sheet_config=self).exists():
                sc_rows.append(
                    SearchCacheRow(
                        row=row,
                        sheet=self.sheet,
                        wiki=self.wiki,
                        group=self.group,
                        sheet_config=self,
                        data=" ".join(list(map(str, row_dict.values()))),
                    )
                )

        SearchCacheRow.objects.bulk_create(sc_rows)
        SearchCacheRow.objects.filter(sheet_config=self).update(
            data_vector=SearchVector("data")
        )


class Row(models.Model):
    """A single row in a spreadsheet"""

    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="rows"
    )
    key = models.TextField()
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_ids")

    def to_dict(self, config):
        new_row = {"key": self.key}
        valid_columns = config.valid_columns.all()
        for value in self.values.filter(column__in=valid_columns).select_related(
            "column"
        ):
            new_row[value.column.name] = value.to_python()

        return new_row

    def __getitem__(self, key):
        return self.data[key]

    def items(self):
        return self.data.items()

    def clone(self):
        return Row(sheet=self.sheet, key=self.key)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a row can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["sheet", "key"], name="unique_key"),
        ]


class Column(models.Model):
    name = models.CharField(max_length=255)
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="columns",
    )
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_columns")


class Value(models.Model):
    column = models.ForeignKey(Column, on_delete=models.CASCADE, related_name="values")
    original = models.TextField(null=True)
    latest = models.TextField(null=True)
    row = models.ForeignKey(Row, on_delete=models.CASCADE, related_name="values")

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
        rows = sheet.clean_rows(sheet_config)

        data = json.loads(self.json_file)
        data[sheet.name] = {row[sheet.key_column]: row for row in rows}

        toc_templates = Template.objects.filter(
            sheet=sheet,
            wiki=sheet_config.wiki,
            type="TOC",
        )
        line_template = toc_templates.get(is_default=True)

        line_template_contents = line_template.template_file.read().decode("utf-8")
        template_contents = self.template.template_file.read().decode("utf-8")

        data["toc_lines"] = {
            row[sheet.key_column]: JinjaTemplate(line_template_contents).render(
                {sheet.object_name: row}
            )
            for row in rows
        }
        return JinjaTemplate(template_contents).render(data)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a row can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(fields=["sheet", "name"], name="unique_toc"),
        ]


class Attachment(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    name = models.TextField()
    row = models.ForeignKey(
        Row, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    permissions_column = models.ForeignKey(
        Column, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    file = models.FileField(upload_to="attachments")

    class Meta:
        constraints = [
            models.UniqueConstraint(
                fields=["sheet", "row", "name"], name="unique_attachment"
            ),
        ]


class User(models.Model):
    username = models.TextField()


class Permission(models.Model):
    permission_type = models.CharField(max_length=255)


class SearchCacheRow(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
    )
    sheet_config = models.ForeignKey(SheetConfig, on_delete=models.CASCADE)
    row = models.ForeignKey(Row, on_delete=models.CASCADE)
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
