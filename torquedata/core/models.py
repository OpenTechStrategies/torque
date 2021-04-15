import csv
import hashlib
import io
import os
import pathlib
import json
from django.db import models
from django.conf import settings

from django.contrib.postgres.search import SearchVector
from django.contrib.postgres.search import SearchVectorField
from django.contrib.postgres.indexes import GinIndex


class Spreadsheet(models.Model):
    """ An uploaded CSV file """

    name = models.CharField(max_length=255, unique=True)
    object_name = models.CharField(max_length=255)
    key_column = models.TextField()

    def clean_rows(self, config):
        # return a reduced list of rows based on permissions defined in config
        # (SheetConfig instance)
        new_rows = {}
        for cell in (
            Cell.objects.filter(
                row__in=config.valid_ids.all(), column__in=config.valid_columns.all()
            )
            .prefetch_related("column")
            .prefetch_related("row")
            .all()
        ):
            if cell.row.id not in new_rows:
                new_rows[cell.row.id] = {"key": cell.row.key}
            new_rows[cell.row.id][cell.column.name] = cell.formatted_value()
        return new_rows.values()

    @classmethod
    def from_csv(cls, name, object_name, key_column, file):
        file_text = io.StringIO(file.read().decode())
        reader = csv.DictReader(file_text)
        if Spreadsheet.objects.filter(name=name).exists():
            sheet = Spreadsheet.objects.get(name=name)
        else:
            sheet = cls(name=name, object_name=object_name, key_column=key_column)
            sheet.save()

        cols = {}
        rows = []
        cells = []
        sheet_cells = {}
        for cell in (
            Cell.objects.filter(row__in=Row.objects.filter(sheet=sheet))
            .prefetch_related("column")
            .prefetch_related("row")
        ):
            if cell.row not in sheet_cells:
                sheet_cells[cell.row] = {}
            if cell.column not in sheet_cells[cell.row]:
                sheet_cells[cell.row][cell.column] = cell

        for row_number, line in enumerate(reader):
            if row_number == 0:
                # Generate columns
                for col_name, col_type in line.items():
                    col, created = Column.objects.update_or_create(
                        name=col_name,
                        type=col_type,
                        sheet=sheet,
                    )
                    col.save()
                    cols[col_name] = col
                continue

            row, created = Row.objects.update_or_create(
                sheet=sheet,
                key=line[sheet.key_column],
            )
            row.save()
            rows.append(row)
            for col_name, cell_value in line.items():
                # found_cell = None
                if row in sheet_cells and cols[col_name] in sheet_cells[row]:
                    cell = sheet_cells[row][cols[col_name]]
                    # Only update for cells whose value has changed
                    if cell.original_value != cell_value:
                        cell.original_value = cell_value
                        cell.latest_value = cell_value
                        cell.save()
                else:
                    cell = Cell(
                        column=cols[col_name],
                        original_value=cell_value,
                        latest_value=cell_value,
                        row=row,
                    )
                    cells.append(cell)

        Cell.objects.bulk_create(cells)
        return sheet, rows


class SheetConfig(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="configs",
    )
    wiki_key = models.TextField()
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

    def delete_search_index(self):
        SearchCacheRow.objects.filter(sheet_config=self).delete()

    def create_search_index(self, sheet):
        sc_rows = []
        for row_dict in sheet.clean_rows(self):
            row = Row.objects.get(key=row_dict["key"], sheet=sheet)
            if not SearchCacheRow.objects.filter(row=row, sheet_config=self).exists():
                sc_rows.append(
                    SearchCacheRow(
                        row=row,
                        sheet=sheet,
                        wiki_key=self.wiki_key,
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
    """ A single row in a spreadsheet """

    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="rows"
    )
    key = models.TextField()
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_ids")

    def to_dict(self, config):
        new_row = {"key": self.key}
        valid_columns = config.valid_columns.all()
        for cell in self.cells.filter(column__in=valid_columns).select_related(
            "column"
        ):
            new_row[cell.column.name] = cell.formatted_value()

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
    type = models.CharField(max_length=255)
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="columns",
    )
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_columns")


class Cell(models.Model):
    column = models.ForeignKey(Column, on_delete=models.CASCADE, related_name="cells")
    original_value = models.TextField(null=True)
    latest_value = models.TextField(null=True)
    row = models.ForeignKey(Row, on_delete=models.CASCADE, related_name="cells")

    def formatted_value(self):
        cell_value = self.latest_value

        if self.column.type == "list":
            cell_value = cell_value.split("\n")
        elif self.column.type == "json":
            if cell_value == "":
                cell_value = {}
            else:
                cell_value = json.loads(cell_value)

        return cell_value


class CellEdit(models.Model):
    cell = models.ForeignKey(Cell, on_delete=models.CASCADE, related_name="edits")
    value = models.TextField()
    message = models.CharField(max_length=255, null=True)
    edit_timestamp = models.DateTimeField(auto_now=True)
    approval_timestamp = models.DateTimeField(null=True)
    sheet = models.ForeignKey(Spreadsheet, on_delete=models.CASCADE)
    wiki_key = models.TextField(null=True)
    approval_code = models.CharField(max_length=255, null=True)


class Template(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="templates"
    )
    wiki_key = models.TextField(null=True)
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
    wiki_key = models.TextField()
    group = models.TextField()
    data = models.TextField()
    data_vector = SearchVectorField(null=True)

    class Meta:
        indexes = (GinIndex(fields=["data_vector"]),)


class PermissionGroup(models.Model):
    name = models.CharField(max_length=255)
    object_name = models.CharField(max_length=255)
    columns = models.ManyToManyField(Column)
    # key_column = models.ForeignKey(Column, on_delete=models.CASCADE)
