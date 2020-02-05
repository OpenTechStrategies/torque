from torquedata import app, data, sheet_config, load_sheet
from jinja2 import Template
from flask import request

import json
import os
import re

with open('mwiki.j2') as template_file: mwiki_template_str = template_file.read()

# This is a temporary fix for while we get authorization up and running
json_fields = [
             "Organization Legal Name",
             "City",
             "State",
             "Country",
             "Principal Organization Website or Social Media",
             "Identification Number of Principal Organization",
             "Identification Number of Principal Organization ein",
             "Primary Contact First Name",
             "Primary Contact Last Name",
             "Primary Contact Title",
             "Primary Contact Email",
             "Review Number",
             "Project Title",
             "Project Description",
             "Executive Summary",
             "Problem Statement",
             "Solution Overview",
             "Youtube Video",
             "Location Of Future Work Country",
             "Location Of Future Work2 Country",
             "Location Of Future Work3 Country",
             "Location Of Future Work4 Country",
             "Location Of Future Work5 Country",
             "Location Of Current Solution Country",
             "Location Of Current Solution2 Country",
             "Location Of Current Solution3 Country",
             "Location Of Current Solution4 Country",
             "Location Of Current Solution5 Country",
             "Project Website or Social Media Page",
             "Application Level",
             "Competition Domain",
        ]

# More temporary stuff that will get moved to configuration
if "proposals" in data.keys():
    top100 = [
            p["Review Number"] for p in data["proposals"].values() if (int(p["Wise Head Overall Score Rank Normalized"]) < 101)
            ]
    wisehead_ranked_proposals = [
            p["Review Number"] for p in data["proposals"].values() if (int(p["Wise Head Overall Score Rank Normalized"]) != 9999)
            ]
else:
    top100 = []
    valid = []

groups = {}

groups["bureaucrat"] = {
        #"valid_proposal_ids": top100,
        #"columns": json_fields
        }

groups["torqueapi"] = {
        "valid_proposal_ids": wisehead_ranked_proposals,
        "columns": json_fields
        }

def cull_invalid_columns(o, valid_fields):
    return {k:v for (k,v) in o.items() if (k in valid_fields)}

def cull_invalid_proposals(group, proposals):
    if group not in groups.keys():
        return []
    elif "valid_proposal_ids" in groups[group].keys():
        valid_proposal_ids = groups[group]["valid_proposal_ids"];
        return [ p for p in proposals if (p[sheet_config["proposals"]["key_column"]] in valid_proposal_ids) ]
    else:
        return proposals

@app.route('/api/<sheet_name>.<fmt>')
def sheet(sheet_name, fmt):
    group = request.args.get("group")

    if fmt == "json":
        valid_proposals = cull_invalid_proposals(group, data[sheet_name].values())
        return json.dumps({sheet_name: [cull_invalid_columns(o, json_fields) for o in valid_proposals ]})
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

    if group in groups.keys():
        valid_proposal_ids = []

        if "valid_proposal_ids" not in groups[group].keys():
            valid_id = True
        else:
            valid_id = (key not in valid_proposal_ids)

    if not valid_id:
        if fmt == "json":
            return json.dumps({"error": "Invalid " + sheet_config[sheet_name]["key_column"]})
        else:
            return  "Invalid " + sheet_config[sheet_name]["key_column"];

    row = data[sheet_name][key]

    if "columns" in groups[group]:
        row = cull_invalid_columns(row, groups[group]["columns"])

    if fmt == "json":
        return json.dumps(row)
    elif fmt == "mwiki":
        config = sheet_config[sheet_name]
        mwiki_template = Template(mwiki_template_str)

        transformed_row = {}
        for key,value in row.items():
            if key == "Attachments":
                transformed_row[key] = []
                for attachment in value:
                    attachment_file_display = re.sub("^\\d*_", "", attachment)
                    attachment_file_display = re.sub("\.pdf$", "", attachment_file_display)
                    if len(attachment_file_display) > 33:
                        attachment_file_display = \
                                attachment_file_display[0:15] + \
                                "..." + \
                                attachment_file_display[(len(attachment_file_display)-15):]
                    attachment_file_display = "[[Media:" + attachment + "|" + attachment_file_display + "]]\n"
                    transformed_row[key].append(attachment_file_display)
            else:
                transformed_row[key] = value

        return mwiki_template.render({config["object_name"]: transformed_row})
    else:
        raise Exception("Invalid format: " + fmt)

@app.route('/upload/sheet', methods=['POST'])
def upload_sheet():
    from werkzeug.utils import secure_filename

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

        file.save(os.path.join(app.config['SPREADSHEET_FOLDER'], sheet_name, sheet_name + ".csv"))

        sheet_config[sheet_name] = {}
        sheet_config[sheet_name]["object_name"] = request.form['object_name']
        sheet_config[sheet_name]["sheet_name"] = request.form['sheet_name']
        sheet_config[sheet_name]["key_column"] = request.form['key_column']
        with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "sheets"), 'w') as f:
            sheet_config.write(f)

        load_sheet(sheet_name)

    return ""

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
