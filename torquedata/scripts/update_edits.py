from core import models
from core import views

from scripts.update_edits_config import collections, column_mapping, group

# This script identifies all edits on unattached fields after a deployment of a collection
#
# This is useful for when a collection has undergone changes in its structure, but the edits
# didn't come along for the ride.  Sometimes it's simple renames, but also sometimes things are
# split apart or combined.
#
# This script reruns all edits that are in the database for columns as if they were done against
# the original column.
#
# Before running, must copy update_edits_config.py.tmpl to update_edits_config and update
# it.

unrun_fields = set()
def run():
    for edit in models.ValueEdit.objects.order_by('edit_timestamp').all():
        value = edit.value
        field = value.field
        document = value.document
        collection = field.collection
        wiki_key = collections[collection.name]
        wiki = models.Wiki.objects.get(wiki_key=wiki_key)

        if not field.attached:
            if field.name in column_mapping:
                print("Updating edit %s" % field.name)
                views.edit_record(collection.name, value.document.key, group, wiki, column_mapping[field.name], edit.updated)
                edit.delete()
            else:
                print("Adding field %s to unrun" % field.name)
                unrun_fields.add(field.name)

    print(unrun_fields)
