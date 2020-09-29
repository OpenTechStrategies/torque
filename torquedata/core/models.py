import csv
from collections import UserDict
from django.db import models


class Spreadsheet(models.Model):
    """ An uploaded CSV file """

    name = models.CharField(max_length=255)
    object_name = models.CharField(max_length=255)
    columns = models.JSONField()
    key_column = models.TextField()

    def clean_rows(self, config):
        # return a reduced list of rows based on permissions defined in config
        # (SheetConfig instance)
        new_rows = []
        for row in self.rows.filter(key__in=config.valid_ids):
            new_row = row.clone()
            new_row.data = {
                k: v for k, v in new_row.items() if k in config.valid_columns
            }
            new_rows.append(new_row)
        return new_rows

    @classmethod
    def from_csv(cls, name, object_name, key_column, file):
        reader = csv.DictReader(file)
        sheet = cls(
            name=name,
            object_name=object_name,
            key_column=key_column,
            columns=reader.fieldnames,
        )

        rows = []
        for row_number, line in enumerate(reader):
            row = Row(
                sheet=sheet,
                key=line[sheet.key_column],
                row_number=row_number,
                data=line,
            )
            rows.append(row)
        return sheet, rows


class SheetConfig(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="configs",
    )
    wiki_key = models.TextField()
    group = models.TextField()

    valid_ids = models.JSONField()
    valid_columns = models.JSONField()


class Row(models.Model):
    """ A single row in a spreadsheet """

    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="rows",
    )

    key = models.TextField()
    row_number = models.PositiveIntegerField()
    data = models.JSONField()

    def __getitem__(self, key):
        return self.data[key]

    def items(self):
        return self.data.items()

    def clone(self):
        return Row(
            sheet=self.sheet, key=self.key, row_number=self.row_number, data=self.data
        )

    class Meta:
        constraints = [
            # enforced on save()
            # useful for making sure any copies of a row can't be written to
            # the database (would probably create some awful bugs)
            models.UniqueConstraint(
                fields=["sheet", "row_number"], name="unique_row_number"
            ),
        ]


class Template(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="templates"
    )
    wiki_key = models.TextField()
    type = models.TextField()
    name = models.TextField()
    is_default = models.BooleanField(default=False)
    template_file = models.TextField()


class TableOfContents(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="tablesofcontents",
    )
    name = models.TextField()
    json_file = models.TextField()
    template_file = models.TextField()


class Attachment(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet, on_delete=models.CASCADE, related_name="attachments",
    )
    name = models.TextField()
    object_id = models.TextField()
    permissions_column = models.TextField()
    file = models.FileField()


class User(models.Model):
    username = models.TextField()
