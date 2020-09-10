from pathlib import Path
from django.core.management import BaseCommand
from torquedata.db.models import Spreadsheet, TableOfContents


class Command(BaseCommand):
    requires_migrations_checks = True

    def add_arguments(self, parser):
        parser.add_argument('sheet')
        parser.add_argument('json_file', type=Path)
        parser.add_argument('template_file', type=Path)

    def handle(self, *args, **options):
        try:
            sheet = Spreadsheet.objects.get(name=options['sheet'])
        except Spreadsheet.DoesNotExist:
            self.stderr.write(f"Sheet {options['sheet']} does not exist in the torquedata database.")
            return

        json_file = options['json_file']
        template_file = options['template_file']
        if not json_file.exists():
            self.stderr.write(f'Path "{json_file}" does not exist.')
        if not json_file.is_file():
            self.stderr.write(f'Path "{json_file}" is not a file.')
        if not template_file.exists():
            self.stderr.write(f'Path "{template_file}" does not exist.')
        if not template_file.is_file():
            self.stderr.write(f'Path "{template_file}" is not a file.')

        toc = TableOfContents(
            sheet=sheet,
            json_file=json_file,
            template_file=template_file
        )
        toc.save()
