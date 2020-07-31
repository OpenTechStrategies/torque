from flask import Flask

import csv
import configparser
import os
import pickle
import json
from whoosh import index
from whoosh.index import create_in
from whoosh.fields import *

app = Flask(__name__)

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

try:
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "users"), 'rb') as f:
        users = pickle.load(f)
except Exception:
    users = {}

try:
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "edits"), 'rb') as f:
        edits = pickle.load(f)
        load_edits = False
except Exception:
    edits = {}
    load_edits = True

loaded_edits = {}
data = {}
indices = {}


def cull_invalid_columns(o, valid_fields):
    return {k:v for (k, v) in o.items() if (k in valid_fields)}


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


def update_row_with_edits(row, sheet_name, key):
    row_edits = edits[sheet_name][key]

    for k in row:
        if len(row_edits[k]['edits']) > 0:
            row[k] = row_edits[k]['edits'][-1]['new_value']

    return row


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
    data[sheet_name] = {}
    loaded_edits = {}
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
        edit_o = {}
        for (field, column_type, cell) in zip(header, column_types, row):
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
        loaded_edits[o[sheet_config[sheet_name]["key_column"]]] = {}

        for k, v in o.items():
            loaded_edits[o[sheet_config[sheet_name]["key_column"]]][k] = {
                "original": v,
                "edits": []
            }
    
    if load_edits:
        edits[sheet_name] = loaded_edits

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