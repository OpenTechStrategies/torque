from flask import Flask

import csv
import configparser
import os
import pickle
import json
from dataclasses import dataclass
from collections import UserDict
from whoosh import index
from whoosh.index import create_in
from whoosh.fields import *

app = Flask(__name__)

# explanation: since this class inherits from UserDict, it behaves pretty much
# exactly like a dict which means old code doesn't break but new code can use
# the methods attached to this class
class Spreadsheet(UserDict):
    @classmethod
    def read(cls, name):
        """Load an existing spreadsheet"""
        raise NotImplementedError

    @classmethod
    def write(cls):
        """Save spreadsheet to filesystem"""
        raise NotImplementedError

    def __init__(self, name, object_name, key_column, columns=[]):
        self.name = name
        self.object_name = object_name
        self.key_column = key_column
        self.columns = columns

        super().__init__()

    def apply_permissions(self, permissions):
        """
        Return a new spreadsheet with only data that is allowed by a given
        permissions object
        """
        new_data = {}
        # cull invalid rows, columns
        for key, entry in self.data.items():
            if 'valid_ids' in permissions and key not in permissions['valid_ids']:
                continue
            if 'columns' in permissions:
                new_entry = {}
                for column, value in entry.items():
                    if column not in permissions['columns']:
                        continue
                    new_entry[column] = value
                new_data[key] = new_entry
            else:
                new_data[key] = entry

        new_sheet = Spreadsheet(
            name=self.name,
            object_name=self.object_name,
            key_column=self.key_column,
            columns=permissions.get('columns', None) or self.columns
        )
        new_sheet.data = new_data
        return new_sheet

    def json(self):
        return json.dumps({self.name: self.data.values()})

try:
    app.config.from_object('config')
except:
    pass

sheet_config = configparser.ConfigParser()
sheet_config.read(os.path.join(app.config['SPREADSHEET_FOLDER'], "sheets"))

try:
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "permissions"), 'rb') as f:
        permissions = pickle.load(f)
except Exception:
    permissions = {}

try:
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "templates"), 'rb') as f:
        templates = pickle.load(f)
except Exception:
    templates = {}

try:
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "attachment_config"), 'rb') as f:
        attachment_config = pickle.load(f)
except Exception:
    attachment_config = {}

data = {}
indices = {}
def cull_invalid_columns(o, valid_fields):
    return {k:v for (k,v) in o.items() if (k in valid_fields)}

def cull_invalid_objects(group, sheet_name, wiki_key):
    if sheet_name not in permissions.keys():
        return []
    if wiki_key not in permissions[sheet_name].keys():
        return []
    if group not in permissions[sheet_name][wiki_key].keys():
        return []
    elif "valid_ids" in permissions[sheet_name][wiki_key][group].keys():
        valid_ids = permissions[sheet_name][wiki_key][group]["valid_ids"];
        return [ o for o in data[sheet_name].values() if (o[sheet_config[sheet_name]["key_column"]] in valid_ids) ]
    else:
        return list(data[sheet_name].values())

def permissions_sha(sheet_name, wiki_key, group):
    import hashlib

    return hashlib.sha224(
        sheet_name.encode('utf-8') +
        str(permissions[sheet_name][wiki_key][group]["valid_ids"]).encode('utf-8') +
        str(permissions[sheet_name][wiki_key][group]["columns"]).encode('utf-8')
    ).hexdigest()

def index_search(group, sheet_name, wiki_key):
    sha = permissions_sha(sheet_name, wiki_key, group)
    dir = os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "indices", sha)

    if(index.exists_in(dir)):
        print("Index already exists for " + sheet_name + " / " + wiki_key + " / " + group + " (or comparable)")
        ix = index.open_dir(dir)
        indices[sha] = ix
        return

    try:
        os.mkdir(dir)
    except FileExistsError:
        pass

    print("Reindexing for " + sheet_name + " / " + group)
    schema = Schema(key=ID(stored=True, unique=True), content=TEXT)
    ix = create_in(dir, schema)
    writer = ix.writer()
    for o in cull_invalid_objects(group, sheet_name, wiki_key):
        writer.add_document(
                key=o[sheet_config[sheet_name]["key_column"]],
                content=" ".join([str(c) for c in cull_invalid_columns(o, permissions[sheet_name][wiki_key][group]["columns"]).values()])
                )
    writer.commit()

    indices[sha] = ix

    return ""


def load_sheet(sheet_name):
    data[sheet_name] = Spreadsheet(
        name=sheet_name,
        object_name=sheet_config[sheet_name]['object_name'],
        key_column=sheet_config[sheet_name]['key_column'],
        columns=[]
    )
    reader = csv.reader(
            open(os.path.join(app.config.get("SPREADSHEET_FOLDER"), sheet_name, sheet_name + ".csv"), encoding='utf-8'),
            delimiter=',',
            quotechar='"'
            )

    if sheet_name not in templates:
        templates[sheet_name] = {}

    if sheet_name not in permissions:
        permissions[sheet_name] = {}

    header = next(reader)
    column_types = next(reader)
    for row in reader:
        o = {}
        for (field, column_type, cell) in zip(header, column_types, row):
            data[sheet_name].columns.append(field)
            if column_type == 'list':
                # This may be reversed as a decision at some point, but the empty cell
                # from the csv comes through as the empty string, meaning that the user
                # probably wants the list to be empty as well.
                if cell == '':
                    cell = []
                else:
                    cell = cell.strip().split("\n")
            elif column_type == 'json':
                if cell == '':
                    cell = {}
                else:
                    cell = json.loads(cell)
            o[field] = cell
        data[sheet_name][o[sheet_config[sheet_name]["key_column"]]] = o

    for wiki_key in permissions[sheet_name].keys():
        for group in permissions[sheet_name][wiki_key].keys():
            sha = permissions_sha(sheet_name, wiki_key, group)
            dir = os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, wiki_key, "indices", sha)
            index_search(group, sheet_name, wiki_key)

for sheet_name in sheet_config.sections():
    if sheet_name is "DEFAULT":
        continue

    load_sheet(sheet_name)

from torquedata import routes
