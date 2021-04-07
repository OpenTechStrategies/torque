import pickle
from core import models

# needs to run with --script-args <editsfilelocation> <competition1> <competition2> ...
#
# See https://django-extensions.readthedocs.io/en/latest/runscript.html#passing-arguments
def run(*args):
    if len(args) == 0:
        print("Need location of edits file")
        exit(1)

    with open(args[0], "rb") as f:
        edits = pickle.load(f)

    for competition in args[1:]:
        sheet = models.Spreadsheet.objects.get(name=competition)
        comp_edits = edits[competition]
        for row_key, key_group in comp_edits.items():
            for column_name, edit_list in key_group.items():
                if edit_list["edits"]:
                    row = models.Row.objects.get(sheet=sheet, key=row_key)
                    cell = row.cells.get(column__name=column_name)

                    for edit in edit_list["edits"]:
                        edit_record = models.CellEdit(
                            sheet=sheet,
                            cell=cell,
                            value=edit["new_value"],
                            edit_timestamp=edit["edit_timestamp"],
                            wiki_key=edit["wiki_key"],
                            message="")
                        edit_record.save()
                        cell.latest_value = edit["new_value"]
                        cell.save()
