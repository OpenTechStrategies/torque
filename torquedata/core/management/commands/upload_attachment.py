from pathlib import Path
from django.core.management import BaseCommand
from core.models import Spreadsheet, Attachment


class Command(BaseCommand):
    requires_migration_checks = True

    def add_arguments(self, parser):
        parser.add_argument("--sheet")
        parser.add_argument("--object_id")
        parser.add_argument("--permissions_column")
        parser.add_argument("--name")
        parser.add_argument("files", nargs="+", type=Path)
        # I realize this is inconsistent with the other two commands, which
        # only let you upload a single file at a time, but I don't think
        # it matters too much

    def handle(self, *args, **options):
        try:
            sheet = Spreadsheet.objects.get(name=options["sheet"])
        except Spreadsheet.DoesNotExist:
            self.stderr.write(
                f"Sheet {options['sheet']} does not exist in the torquedata database."
            )
            return

        for p in options["files"]:
            if not p.exists():
                self.stderr.write(f'Path "{p}" does not exist.')
                continue
            if not p.is_file():
                self.stderr.write(f'Path "{p}" is not a file.')
            with p.open() as f:
                new_attachment = Attachment(
                    sheet=sheet,
                    name=options["name"],
                    object_id=options["object_id"],
                    permissions_column=options["permissions_column"],
                    file=f,
                )
                new_attachment.save()
