from torquedata import app, data, sheet_config, load_sheet, permissions, attachment_config
from jinja2 import Template
from flask import request, send_file, abort
from werkzeug.utils import secure_filename

import json
import os
import re
import io
import pickle

def cull_invalid_columns(o, valid_fields):
    return {k:v for (k,v) in o.items() if (k in valid_fields)}

def cull_invalid_proposals(group, proposals):
    if group not in permissions.keys():
        return []
    elif "valid_ids" in permissions[group].keys():
        valid_ids = permissions[group]["valid_ids"];
        return [ p for p in proposals if (p[sheet_config["proposals"]["key_column"]] in valid_ids) ]
    else:
        return proposals

@app.route('/api/<sheet_name>.<fmt>')
def sheet(sheet_name, fmt):
    group = request.args.get("group")

    if fmt == "json":
        valid_proposals = cull_invalid_proposals(group, data[sheet_name].values())
        return json.dumps({sheet_name: [cull_invalid_columns(o, permissions[group]["columns"]) for o in valid_proposals ]})
    else:
        raise Exception("Only json format valid for full list")

@app.route('/api/<sheet_name>/toc/<toc_name>.<fmt>')
def sheet_toc(sheet_name, toc_name, fmt):
    group = request.args.get("group")

    valid_proposals = cull_invalid_proposals(group, list(data[sheet_name].values()))

    if fmt == "mwiki":
        toc_str = ""
        with open(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "tocs", toc_name + ".j2")) as f:
            template_str = f.read()
        with open(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "tocs", toc_name + ".json")) as f:
            template_data = json.loads(f.read())
        template_data[sheet_name] = { p["Review Number"]:p for p in valid_proposals }

        return Template(template_str).render(template_data)
    else:
        raise Exception("Only mwiki format valid for toc")

@app.route('/api/<sheet_name>/id/<key>.<fmt>')
def row(sheet_name, key, fmt):
    group = request.args.get("group")

    valid_id = False

    if group in permissions.keys():
        if "valid_ids" not in permissions[group].keys():
            valid_id = True
        else:
            valid_id = (key in permissions[group]["valid_ids"])

    if not valid_id:
        if fmt == "json":
            return json.dumps({"error": "Invalid " + sheet_config[sheet_name]["key_column"]})
        else:
            abort(403, "Invalid " + sheet_config[sheet_name]["key_column"]);

    row = data[sheet_name][key]

    if "columns" in permissions[group]:
        row = cull_invalid_columns(row, permissions[group]["columns"])

    if fmt == "json":
        return json.dumps(row)
    elif fmt == "mwiki":
        config = sheet_config[sheet_name]
        mwiki_template = Template(permissions[group]["template"])

        return mwiki_template.render({config["object_name"]: row})
    else:
        raise Exception("Invalid format: " + fmt)

@app.route('/api/<sheet_name>/attachment/<key>/<attachment>')
def attachment(sheet_name, key, attachment):
    group = request.args.get("group")

    import urllib.parse
    attachment = urllib.parse.unquote_plus(attachment)

    valid_id = False

    if group in permissions.keys():
        if "valid_ids" not in permissions[group].keys():
            valid_id = True
        else:
            valid_id = (key in permissions[group]["valid_ids"])

    if not valid_id:
        return "Invalid " + sheet_config[sheet_name]["key_column"]

    attachment_name = secure_filename(attachment)
    if not attachment_name in attachment_config.keys():
        return "Invalid attachment: " + attachment_name

    if attachment_config[attachment_name]["object_id"] != key:
        return "Invalid " + sheet_config[sheet_name]["key_column"]

    attachment_permissions_column = attachment_config[attachment_name]["permissions_column"]
    if attachment_permissions_column not in permissions[group]["columns"]:
        return "Invalid attachment: " + attachment_name

    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "attachments", attachment_name), "rb") as file:
        return send_file(io.BytesIO(file.read()), attachment_filename = attachment_name)

@app.route('/upload/sheet', methods=['POST'])
def upload_sheet():
    if 'data_file' not in request.files:
        raise Exception("Must have file in data upload")
    if 'object_name' not in request.form:
        raise Exception("Must have the object_name")
    if 'sheet_name' not in request.form:
        raise Exception("Must have the sheet_name")
    if 'key_column' not in request.form:
        raise Exception("Must have the key_column name")

    file = request.files['data_file']
    if file.filename == '':
        raise Exception("File must have a filename associated with it")
    if file:
        sheet_name = secure_filename(request.form['sheet_name'])

        try:
            os.mkdir(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name))
        except FileExistsError:
            pass

        try:
            os.mkdir(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "tocs"))
        except FileExistsError:
            pass

        try:
            os.mkdir(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "attachments"))
        except FileExistsError:
            pass

        file.save(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, sheet_name + ".csv"))

        sheet_config[sheet_name] = {}
        sheet_config[sheet_name]["object_name"] = request.form['object_name']
        sheet_config[sheet_name]["sheet_name"] = request.form['sheet_name']
        sheet_config[sheet_name]["key_column"] = request.form['key_column']
        with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "sheets"), 'w') as f:
            sheet_config.write(f)

        load_sheet(sheet_name)

    return ""

@app.route('/config/group', methods=['POST'])
def set_group_config():
    new_config = request.json

    if 'group' not in new_config:
        raise Exception("Must have a group name")

    group_name = new_config['group']
    if group_name not in permissions.keys():
        permissions[group_name] = {}

    if 'valid_ids' in new_config:
        permissions[group_name]['valid_ids'] = new_config['valid_ids']

    if 'template' in new_config:
        permissions[group_name]['template'] = new_config['template']

    if 'columns' in new_config:
        permissions[group_name]['columns'] = new_config['columns']


    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "permissions"), 'wb') as f:
        pickle.dump(permissions, f)

    return ''


@app.route('/upload/toc', methods=['POST'])
def upload_toc():
    from werkzeug.utils import secure_filename

    if 'json' not in request.files:
        raise Exception("Must have json file")
    if 'template' not in request.files:
        raise Exception("Must have template file")
    if 'sheet_name' not in request.form:
        raise Exception("Must have the sheet_name")
    if 'toc_name' not in request.form:
        raise Exception("Must have the toc_name")

    json_file = request.files['json']
    template_file = request.files['template']
    sheet_name = secure_filename(request.form['sheet_name'])
    toc_name = secure_filename(request.form['toc_name'])

    if json_file.filename == '':
        raise Exception("json_file must have a filename associated with it")
    if template_file.filename == '':
        raise Exception("template_file must have a filename associated with it")
    if sheet_name not in sheet_config.keys():
        raise Exception(sheet_name + " is not an existing sheet")
    if json_file and template_file:
        secure_sheet_name = secure_filename(request.form['sheet_name'])

        json_file.save(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "tocs", toc_name + ".json"))
        template_file.save(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "tocs", toc_name + ".j2"))

    return ""

@app.route('/upload/attachment', methods=['POST'])
def upload_attachment():
    from werkzeug.utils import secure_filename

    if 'attachment' not in request.files:
        raise Exception("Must have file in data upload")
    if 'attachment_name' not in request.form:
        raise Exception("Must have the attachment_name")
    if 'sheet_name' not in request.form:
        raise Exception("Must have the sheet_name")
    if 'permissions_column' not in request.form:
        raise Exception("Must have the permissions_column")
    if 'object_id' not in request.form:
        raise Exception("Must have the object_id name")

    attachment = request.files['attachment']
    if attachment.filename == '':
        raise Exception("attachment must have a filename associated with it")

    sheet_name = secure_filename(request.form['sheet_name'])
    if sheet_name not in sheet_config.keys():
        raise Exception(sheet_name + " is not an existing sheet")

    attachment_name = secure_filename(request.form['attachment_name'])
    permissions_column = request.form['permissions_column']
    object_id = request.form['object_id']

    attachment_config[attachment_name] = {
            'permissions_column': permissions_column,
            'object_id': object_id
            }

    with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "attachment_config"), 'wb') as f:
        pickle.dump(attachment_config, f)

    attachment.save(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, "attachments", attachment_name))

    return ""
