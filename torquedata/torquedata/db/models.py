import csv
from django.db import models


class Spreadsheet(models.Model):
    """ An uploaded CSV file """
    name = models.CharField(max_length=255)
    object_name = models.CharField(max_length=255)
    _columns = models.TextField()
    key_column = models.TextField()

    @property
    def columns(self):
        return csv.reader([self._columns]).__next__()


class Row(models.Model):
    """ A single row in a spreadsheet """
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name='rows',
    )

    key = models.TextField()
    row_number = models.PositiveIntegerField()
    _raw = models.TextField()

    def __iter__(self):
        return csv.reader([self._raw]).__next__()

    def as_dict(self):
        return {k: v for k, v in zip(self.sheet.columns, self)}

    class Meta:
        constraints = [
            models.UniqueConstraint(fields=['sheet', 'row_number'], name='unique_row_number'),
        ]


class TableOfContents(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name='templates',
    )
    json_file = models.FileField()
    template_file = models.FileField()


class Attachment(models.Model):
    sheet = models.ForeignKey(
        Spreadsheet,
        on_delete=models.CASCADE,
        related_name='attachments',
    )
    file = models.FileField()


class User(models.Model):
    username = models.CharField(max_length=255)
