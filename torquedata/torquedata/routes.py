from datetime import datetime
from torquedata import *
from jinja2 import Template
from flask import request, send_file, abort
from werkzeug.utils import secure_filename

import threading
import flask
import json
import os
import re
import io
import pickle

import shutil
import whoosh
from whoosh.qparser import QueryParser


@app.route("/search/<sheet_name>")
def search(sheet_name):
    q = request.args.get("q")
    group = request.args.get("group")
    wiki_key = request.args.get("wiki_key")
    sha = permissions_sha(sheet_name, wiki_key, group)

    if sha in indices:
        ix = indices[sha]
        with ix.searcher() as searcher:
            parser = QueryParser("content", ix.schema)
            query = parser.parse(q)
            # 2000 is arbitrarily large enough, and we must have a limit declared
            results = searcher.search(query, limit=2000)

            search_templates = templates[sheet_name][wiki_key]["Search"]
            search_template = search_templates["templates"][search_templates["default"]]
            template = Template(search_template)

            config = sheet_config[sheet_name]

            resp = ""

            if results.scored_length() == 0:
                return "There were no results matching the query."
            else:
                resp = "== %d results for '%s' ==\n\n" % (results.scored_length(), q)
                for r in results:
                    o = data[sheet_name][r["key"]]
                    culled_o = cull_invalid_columns(
                        data[sheet_name][r["key"]],
                        permissions[sheet_name][wiki_key][group]["columns"],
                    )
                    resp += template.render({config["object_name"]: culled_o})
                    resp += "\n\n"

            return resp
    else:
        return ""


@app.route("/api/<sheet_name>.<fmt>")
def sheet(sheet_name, fmt):
    group = request.args.get("group")
    wiki_key = request.args.get("wiki_key")

    if fmt == "json":
        valid_objects = cull_invalid_objects(group, sheet_name, wiki_key)
        return json.dumps(
            {
                sheet_name: [
                    cull_invalid_columns(
                        o, permissions[sheet_name][wiki_key][group]["columns"]
                    )
                    for o in valid_objects
                ]
            }
        )
    else:
        raise Exception("Only json format valid for full list")


@app.route("/api/<sheet_name>/toc/<toc_name>.<fmt>")
def sheet_toc(sheet_name, toc_name, fmt):
    group = request.args.get("group")
    wiki_key = request.args.get("wiki_key")

    if not group:
        abort(403, "Group " + group + " invalid")

    if not "TOC" in templates[sheet_name][wiki_key]:
        abort(403, "No TOC defined for " + sheet_name + " for wiki " + wiki_key)

    valid_objects = cull_invalid_objects(group, sheet_name, wiki_key)

    if fmt == "mwiki":
        toc_str = ""
        config = sheet_config[sheet_name]
        with open(
            os.path.join(
                app.config["SPREADSHEET_FOLDER"], sheet_name, "tocs", toc_name + ".j2"
            )
        ) as f:
            template_str = f.read()
        with open(
            os.path.join(
                app.config["SPREADSHEET_FOLDER"], sheet_name, "tocs", toc_name + ".json"
            )
        ) as f:
            template_data = json.loads(f.read())
        template_data[sheet_name] = {o[config["key_column"]]: o for o in valid_objects}

        toc_templates = templates[sheet_name][wiki_key]["TOC"]

        toc_template = toc_templates["templates"][toc_templates["default"]]

        # Because TorqueDataConnect will sometimes override the global view and send it along,
        # and because that view may not be a valid TOC view, only use the view argument to override
        # the default if it's valid.
        if (
            request.args.get("view")
            and request.args.get("view") in toc_templates["templates"]
        ):
            toc_template = toc_templates["templates"][request.args.get("view")]

        template = Template(toc_template)
        template_data["toc_lines"] = {
            o[config["key_column"]]: template.render(
                {
                    config["object_name"]: cull_invalid_columns(
                        o, permissions[sheet_name][wiki_key][group]["columns"]
                    )
                }
            )
            for o in valid_objects
        }

        return Template(template_str).render(template_data)
    else:
        raise Exception("Only mwiki format valid for toc")


def is_valid_id(group, wiki_key, key, sheet_name):
    if (
        wiki_key in permissions[sheet_name].keys()
        and group in permissions[sheet_name][wiki_key].keys()
    ):
        if "valid_ids" not in permissions[sheet_name][wiki_key][group].keys():
            return True
        else:
            return key in permissions[sheet_name][wiki_key][group]["valid_ids"]
    return False


def get_row(group, wiki_key, key, fmt, sheet_name, view=None):
    if not is_valid_id(group, wiki_key, key, sheet_name):
        if fmt == "json":
            return json.dumps(
                {"error": "Invalid " + sheet_config[sheet_name]["key_column"]}
            )
        else:
            abort(403, "Invalid " + sheet_config[sheet_name]["key_column"])

    row = data[sheet_name][key]

    if "columns" in permissions[sheet_name][wiki_key][group]:
        row = cull_invalid_columns(
            row, permissions[sheet_name][wiki_key][group]["columns"]
        )

    row = update_row_with_edits(row, sheet_name, key)

    if fmt == "json":
        return json.dumps(row)
    elif fmt == "mwiki":
        mwiki_templates = templates[sheet_name][wiki_key]["View"]
        chosen_view = view or mwiki_templates["default"]
        config = sheet_config[sheet_name]
        mwiki_template = mwiki_templates["templates"][chosen_view]
        template = Template(mwiki_template)

        return template.render({config["object_name"]: row})
    else:
        raise Exception("Invalid format: " + fmt)


@app.route("/api/<sheet_name>/edit-record/<key>", methods=["POST"])
def edit_record(sheet_name, key):
    group = request.json.get("group")
    wiki_key = request.json.get("wiki_key")
    new_values = json.loads(request.json.get("new_values"))

    if not is_valid_id(group, wiki_key, key, sheet_name):
        abort(403)

    for field, val in new_values.items():
        edit = {
            "new_value": val,
            "edit_message": None,
            "edit_timestamp": datetime.datetime.now(),
            "editor": group,
            "approver": None,
            "approval_code": None,
            "approval_timestamp": None,
            "wiki_key": wiki_key,
        }
        edits[sheet_name][key][field]["edits"].append(edit)

    with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "edits"), "wb") as f:
        pickle.dump(edits, f)

    return get_row(group, wiki_key, key, "mwiki", sheet_name)


@app.route("/api/<sheet_name>/id/<key>.<fmt>")
def row(sheet_name, key, fmt):
    group = request.args.get("group")
    wiki_key = request.args.get("wiki_key")

    return get_row(
        group, wiki_key, key, fmt, sheet_name, request.args.get("view", None)
    )


@app.route("/api/<sheet_name>/attachment/<key>/<attachment>")
def attachment(sheet_name, key, attachment):
    group = request.args.get("group")
    wiki_key = request.args.get("wiki_key")

    import urllib.parse

    attachment = urllib.parse.unquote_plus(attachment)

    valid_id = False

    if (
        wiki_key in permissions[sheet_name].keys()
        and group in permissions[sheet_name][wiki_key].keys()
    ):
        if "valid_ids" not in permissions[sheet_name][wiki_key][group].keys():
            valid_id = True
        else:
            valid_id = key in permissions[sheet_name][wiki_key][group]["valid_ids"]

    if not valid_id:
        return "Invalid " + sheet_config[sheet_name]["key_column"]

    attachment_name = secure_filename(attachment)
    if not attachment_name in attachment_config.keys():
        return "Invalid attachment: " + attachment_name

    if attachment_config[attachment_name]["object_id"] != key:
        return "Invalid " + sheet_config[sheet_name]["key_column"]

    attachment_permissions_column = attachment_config[attachment_name][
        "permissions_column"
    ]
    if (
        attachment_permissions_column
        not in permissions[sheet_name][wiki_key][group]["columns"]
    ):
        return "Invalid attachment: " + attachment_name

    with open(
        os.path.join(
            app.config["SPREADSHEET_FOLDER"], sheet_name, "attachments", attachment_name
        ),
        "rb",
    ) as file:
        return send_file(io.BytesIO(file.read()), attachment_filename=attachment_name)


@app.route("/upload/sheet", methods=["POST"])
def upload_sheet():
    if "data_file" not in request.files:
        raise Exception("Must have file in data upload")
    if "object_name" not in request.form:
        raise Exception("Must have the object_name")
    if "sheet_name" not in request.form:
        raise Exception("Must have the sheet_name")
    if "key_column" not in request.form:
        raise Exception("Must have the key_column name")

    file = request.files["data_file"]
    if file.filename == "":
        raise Exception("File must have a filename associated with it")
    if file:
        sheet_name = secure_filename(request.form["sheet_name"])

        try:
            os.mkdir(os.path.join(app.config["SPREADSHEET_FOLDER"], sheet_name))
        except FileExistsError:
            pass

        try:
            os.mkdir(os.path.join(app.config["SPREADSHEET_FOLDER"], sheet_name, "tocs"))
        except FileExistsError:
            pass

        try:
            dir = os.path.join(app.config["SPREADSHEET_FOLDER"], sheet_name, "indices")
            shutil.rmtree(dir, True)
            os.mkdir(dir)
        except FileExistsError:
            pass

        try:
            os.mkdir(
                os.path.join(
                    app.config["SPREADSHEET_FOLDER"], sheet_name, "attachments"
                )
            )
        except FileExistsError:
            pass

        file.save(
            os.path.join(
                app.config["SPREADSHEET_FOLDER"], sheet_name, sheet_name + ".csv"
            )
        )

        sheet_config[sheet_name] = {}
        sheet_config[sheet_name]["object_name"] = request.form["object_name"]
        sheet_config[sheet_name]["sheet_name"] = request.form["sheet_name"]
        sheet_config[sheet_name]["key_column"] = request.form["key_column"]
        with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "sheets"), "w") as f:
            sheet_config.write(f)

        load_sheet(sheet_name)

    return ""


@app.route("/config/<sheet_name>/<wiki_key>/reset")
def reset_config(sheet_name, wiki_key):
    global templates, permissions
    permissions[sheet_name][wiki_key] = {}
    templates[sheet_name][wiki_key] = {}
    with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "permissions"), "wb") as f:
        pickle.dump(permissions, f)

    with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "templates"), "wb") as f:
        pickle.dump(templates, f)

    return ""


@app.route("/config/<sheet_name>/<wiki_key>/group", methods=["POST"])
def set_group_config(sheet_name, wiki_key):
    global permissions
    new_config = request.json

    if sheet_name not in permissions.keys():
        permissions[sheet_name] = {}

    if wiki_key not in permissions[sheet_name].keys():
        permissions[sheet_name][wiki_key] = {}

    if "group" not in new_config:
        raise Exception("Must have a group name")

    group_name = new_config["group"]
    if group_name not in permissions[sheet_name][wiki_key].keys():
        permissions[sheet_name][wiki_key][group_name] = {}

    if "valid_ids" in new_config:
        permissions[sheet_name][wiki_key][group_name]["valid_ids"] = new_config[
            "valid_ids"
        ]

    if "columns" in new_config:
        permissions[sheet_name][wiki_key][group_name]["columns"] = new_config["columns"]

    with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "permissions"), "wb") as f:
        pickle.dump(permissions, f)

    index_search(group_name, sheet_name, wiki_key)

    return ""


@app.route("/config/<sheet_name>/<wiki_key>/template", methods=["POST"])
def set_template_config(sheet_name, wiki_key):
    new_config = request.json

    if "name" not in new_config:
        raise Exception("Must have a template name")

    if "type" not in new_config:
        raise Exception("Must have a template type")

    if sheet_name not in templates.keys():
        templates[sheet_name] = {}

    if wiki_key not in templates[sheet_name].keys():
        templates[sheet_name][wiki_key] = {}

    template_type = new_config["type"]
    if template_type not in templates[sheet_name][wiki_key]:
        templates[sheet_name][wiki_key][template_type] = {
            "default": None,
            "templates": {},
        }

    name = new_config["name"]
    if name not in templates[sheet_name][wiki_key][template_type]["templates"]:
        # We just make the first one we get the default for the type
        if not templates[sheet_name][wiki_key][template_type]["default"]:
            templates[sheet_name][wiki_key][template_type]["default"] = name
        templates[sheet_name][wiki_key][template_type]["templates"][name] = new_config[
            "template"
        ]

    with open(os.path.join(app.config["SPREADSHEET_FOLDER"], "templates"), "wb") as f:
        pickle.dump(templates, f)

    return ""


@app.route("/upload/toc", methods=["POST"])
def upload_toc():
    from werkzeug.utils import secure_filename

    if "json" not in request.files:
        raise Exception("Must have json file")
    if "template" not in request.files:
        raise Exception("Must have template file")
    if "sheet_name" not in request.form:
        raise Exception("Must have the sheet_name")
    if "toc_name" not in request.form:
        raise Exception("Must have the toc_name")

    json_file = request.files["json"]
    template_file = request.files["template"]
    sheet_name = secure_filename(request.form["sheet_name"])
    toc_name = secure_filename(request.form["toc_name"])

    if json_file.filename == "":
        raise Exception("json_file must have a filename associated with it")
    if template_file.filename == "":
        raise Exception("template_file must have a filename associated with it")
    if sheet_name not in sheet_config.keys():
        raise Exception(sheet_name + " is not an existing sheet")
    if json_file and template_file:
        secure_sheet_name = secure_filename(request.form["sheet_name"])

        json_file.save(
            os.path.join(
                app.config["SPREADSHEET_FOLDER"], sheet_name, "tocs", toc_name + ".json"
            )
        )
        template_file.save(
            os.path.join(
                app.config["SPREADSHEET_FOLDER"], sheet_name, "tocs", toc_name + ".j2"
            )
        )

    return ""


@app.route("/upload/attachment", methods=["POST"])
def upload_attachment():
    from werkzeug.utils import secure_filename

    if "attachment" not in request.files:
        raise Exception("Must have file in data upload")
    if "attachment_name" not in request.form:
        raise Exception("Must have the attachment_name")
    if "sheet_name" not in request.form:
        raise Exception("Must have the sheet_name")
    if "permissions_column" not in request.form:
        raise Exception("Must have the permissions_column")
    if "object_id" not in request.form:
        raise Exception("Must have the object_id name")

    attachment = request.files["attachment"]
    if attachment.filename == "":
        raise Exception("attachment must have a filename associated with it")

    sheet_name = secure_filename(request.form["sheet_name"])
    if sheet_name not in sheet_config.keys():
        raise Exception(sheet_name + " is not an existing sheet")

    attachment_name = secure_filename(request.form["attachment_name"])
    permissions_column = request.form["permissions_column"]
    object_id = request.form["object_id"]

    attachment_config[attachment_name] = {
        "permissions_column": permissions_column,
        "object_id": object_id,
    }

    with open(
        os.path.join(app.config["SPREADSHEET_FOLDER"], "attachment_config"), "wb"
    ) as f:
        pickle.dump(attachment_config, f)

    attachment.save(
        os.path.join(
            app.config["SPREADSHEET_FOLDER"], sheet_name, "attachments", attachment_name
        )
    )

    return ""


# We need a lock because we have incrementing ids.
# This should really be in a database....
userlock = threading.Lock()


@app.route("/users/username/<username>")
def find_user_by_username(username):
    if username not in users.keys():
        with userlock:
            # We start on 14 because then no one is user 13
            users[username] = {"username": username, "id": len(users.items()) + 14}

            # With help from https://stackoverflow.com/questions/2333872/atomic-writing-to-file-with-python
            filepath = os.path.join(app.config["SPREADSHEET_FOLDER"], "users")
            tmppath = filepath + "~"
            with open(tmppath, "wb") as f:
                pickle.dump(users, f)
                f.flush()
                os.fsync(f.fileno())
            os.rename(tmppath, filepath)

    return json.dumps(users[username])
