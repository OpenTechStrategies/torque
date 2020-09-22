from pathlib import Path
from django.core.management import BaseCommand
from core.models import Spreadsheet, Row


class Command(BaseCommand):
    requires_migrations_checks = True

    def add_arguments(self, parser):
        parser.add_argument('file', type=Path)
        parser.add_argument('--name', required=True)
        parser.add_argument('--obj-name', required=True)
        parser.add_argument('--key-column', required=True)

    def handle(self, *args, **options):
        p = options['file']
        if not p.exists():
            self.stderr.write(f'Path "{p}" does not exist.')
        if not p.is_file():
            self.stderr.write(f'Path "{p}" is not a file.')

        with p.open() as f:
            sheet, rows = Spreadsheet.from_csv(
                name=options['name'],
                object_name=options['obj_name'],
                key_column=options['key_column'],
                file=f,
            )
        sheet.save()
        for row in rows:
            row.save()