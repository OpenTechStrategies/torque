from flask import Flask

import csv
import configparser
import os
import pickle
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
    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "attachment_config"), 'rb') as f:
        attachment_config = pickle.load(f)
except Exception:
    attachment_config = {}

data = {}
indices = {}
def cull_invalid_columns(o, valid_fields):
    return {k:v for (k,v) in o.items() if (k in valid_fields)}

def cull_invalid_objects(group, sheet_name):
    if group not in permissions.keys():
        return []
    elif "valid_ids" in permissions[group].keys():
        valid_ids = permissions[group]["valid_ids"];
        return [ o for o in data[sheet_name].values() if (o[sheet_config[sheet_name]["key_column"]] in valid_ids) ]
    else:
        return list(data[sheet_name].values())

def permissions_sha(group):
    import hashlib

    return hashlib.sha224(
        str(permissions[group]["valid_ids"]).encode('utf-8') +
        str(permissions[group]["columns"]).encode('utf-8')
    ).hexdigest()

def index_search(group, sheet_name):
    sha = permissions_sha(group)
    dir = os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "indices", sha)

    if(index.exists_in(dir)):
        print("Index already exists for " + sheet_name + " / " + group + " (or comparable)")
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
    for o in cull_invalid_objects(group, sheet_name):
        writer.add_document(
                key=o[sheet_config[sheet_name]["key_column"]],
                content=" ".join([str(c) for c in cull_invalid_columns(o, permissions[group]["columns"]).values()])
                )
    writer.commit()

    indices[sha] = ix

    return ""


def load_sheet(sheet_name):
    data[sheet_name] = {}
    reader = csv.reader(
            open(os.path.join(app.config.get("SPREADSHEET_FOLDER"), sheet_name, sheet_name + ".csv"), encoding='utf-8'),
            delimiter=',',
            quotechar='"'
            )
    header = next(reader)
    column_types = next(reader)
    for row in reader:
        o = {}
        for (field, column_type, cell) in zip(header, column_types, row):
            if column_type == 'list':
                cell = cell.strip().split("\n")
            o[field] = cell
        data[sheet_name][o[sheet_config[sheet_name]["key_column"]]] = o

    for group in permissions.keys():
        sha = permissions_sha(group)
        dir = os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "indices", sha)
        index_search(group, sheet_name)

for sheet_name in sheet_config.sections():
    if sheet_name is "DEFAULT":
        continue

    load_sheet(sheet_name)

from torquedata import routes
