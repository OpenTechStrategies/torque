import csv
import hashlib
import io
import os
import pathlib
from django.db import models
from django.conf import settings


class Spreadsheet(models.Model):
    """ An uploaded CSV file """

    name = models.CharField(max_length=255, unique=True)
    object_name = models.CharField(max_length=255)
    key_column = models.TextField()

    def clean_rows(self, config):
        # return a reduced list of rows based on permissions defined in config
        # (SheetConfig instance)
        new_rows = []
        for row in config.valid_ids.all():
            new_rows.append(row.to_dict(config))
        return new_rows

    @classmethod
    def from_csv(cls, name, object_name, key_column, file):
        file_text = io.StringIO(file.read().decode())
        reader = csv.DictReader(file_text)
        sheet = cls(name=name, object_name=object_name, key_column=key_column)
        sheet.save()

        cols = {}
        rows = []
        cells = []
        for row_number, line in enumerate(reader):
            if row_number == 0:
                # Generate columns
                for col_name, col_type in line.items():
                    col = Column(
                        name=col_name,
                        type=col_type,
                        sheet=sheet,
                    )
                    col.save()
                    cols[col_name] = col
                continue

            row = Row(
                sheet=sheet,
                key=line[sheet.key_column],
                row_number=row_number,
            )
            row.save()
            rows.append(row)
            for col_name, cell_value in line.items():
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

    def create_search_index(self, sheet):
        sc_rows = []
        for row_dict in sheet.clean_rows(self):
            row = Row.objects.get(row_number=row_dict["row_number"], sheet=self.sheet)
            if not SearchCacheRow.objects.filter(row=row, sheet_config=self).exists():
                sc_rows.append(
                    SearchCacheRow(
                        row=row,
                        sheet=self.sheet,
                        wiki_key=self.wiki_key,
                        group=self.group,
                        sheet_config=self,
                        data=" ".join(list(map(str, row_dict.values()))),
                    )
                )

        SearchCacheRow.objects.bulk_create(sc_rows)


class Row(models.Model):
    """ A single row in a spreadsheet """

    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="rows"
    )

    key = models.TextField()
    row_number = models.PositiveIntegerField()
    sheet_config = models.ManyToManyField(SheetConfig, related_name="valid_ids")

    def to_dict(self, config):
        new_row = {"row_number": self.row_number, "key": self.key}
        for cell in self.cells.filter(
            column__in=config.valid_columns.all()
        ).select_related("column"):
            cell_value = cell.latest_value

            if cell.column.type == "list":
                cell_value = cell_value.split("\n")

            new_row[cell.column.name] = cell_value
        return new_row

    def __getitem__(self, key):
        return self.data[key]

    def items(self):
        return self.data.items()

    def clone(self):
        return Row(sheet=self.sheet, key=self.key, row_number=self.row_number)

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a row can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(
                fields=["sheet", "row_number"], name="unique_row_number"
            ),
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
    column = models.ForeignKey(Column, on_delete=models.CASCADE, related_name="column")
    original_value = models.TextField(null=True)
    latest_value = models.TextField(null=True)
    row = models.ForeignKey(Row, on_delete=models.CASCADE, related_name="cells")


class CellEdit(models.Model):
    cell = models.ForeignKey(Cell, on_delete=models.CASCADE, related_name="edits")
    value = models.CharField(max_length=255)
    message = models.CharField(max_length=255, null=True)
    edit_timestamp = models.DateTimeField(auto_now=True)
    approval_timestamp = models.DateTimeField(null=True)
    config = models.ForeignKey(SheetConfig, on_delete=models.CASCADE)
    approval_code = models.CharField(max_length=255, null=True)


class Template(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="templates"
    )
    wiki_key = models.TextField(null=True)
    type = models.TextField()  # enumeration?
    name = models.TextField()
    is_default = models.BooleanField(default=False)
    template_file = models.FileField(upload_to="templates/", null=False, blank=False)

    def get_file_contents(self):
        return b"".join(self.template_file.open().readlines()).decode("utf-8")


class TableOfContents(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name="tables_of_contents",
    )
    name = models.TextField()
    json_file = models.TextField()
    template = models.ForeignKey(
        Template, on_delete=models.CASCADE, related_name="tocs"
    )


class Attachment(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="attachments", default=None
    )
    name = models.TextField()
    object_id = models.TextField()
    permissions_column = models.TextField()  # Foreign key permission
    file = models.FileField(upload_to="attachments")


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


class PermissionGroup(models.Model):
    name = models.CharField(max_length=255)
    object_name = models.CharField(max_length=255)
    columns = models.ManyToManyField(Column)
    # key_column = models.ForeignKey(Column, on_delete=models.CASCADE)
