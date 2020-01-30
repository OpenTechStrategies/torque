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
             "Location Of Current Solution Country",
             "Project Website or Social Media Page",
             "Application Level",
             "Competition Domain",
        ]

# More temporary stuff that will get moved to configuration
top100 = [
        "6988", "6802", "6990", "7392", "1085", "385", "729", "3410", "873", "458", "822", "5674", "1716",
        "7701", "7794", "8336", "1113", "3516", "6342", "425", "4715", "9234", "3673", "5856", "6452", "624",
        "3122", "8901", "1006", "8766", "2554", "8075", "1276", "29", "6002", "2601", "54", "958", "9217",
        "2031", "8876", "3483", "6000", "960", "7091", "8871", "3681", "3376", "8277", "5665", "3773", "3499",
        "4457", "3993", "6581", "52", "8782", "827", "1952", "8376", "8914", "7738", "6798", "940", "1779",
        "5886", "912", "7045", "2058", "6329", "2042", "6961", "8161", "649", "613", "650", "433", "5848",
        "3660", "1219", "6312", "405", "5562", "1814", "6740", "4740", "1196", "878", "4394", "932", "891",
        "7886", "8838", "8120", "723", "112", "1899", "6939", "9338", "8893",
        ]

groups = {}

groups["bureaucrat"] = {
        "valid_proposal_ids": top100,
        #"columns": json_fields
        }

groups["torqueapi"] = {
        "valid_proposal_ids": top100,
        "columns": json_fields
        }

def cull_invalid_columns(o, valid_fields):
    return {k:v for (k,v) in o.items() if (k in valid_fields)}

@app.route('/api/<sheet_name>')
def sheet(sheet_name):
    group = request.args.get("group")

    valid_proposal_ids = []

    if group in groups.keys():
        valid_proposal_ids = groups[group]["valid_proposal_ids"];

    valid_proposals = [ p for p in data[sheet_name].values() if (p[sheet_config[sheet_name]["key_column"]] in valid_proposal_ids) ]

    print(group)
    return json.dumps({sheet_name: [cull_invalid_columns(o, json_fields) for o in valid_proposals ]})

@app.route('/api/<sheet_name>/<key>.<fmt>')
def formatted_row(sheet_name, key, fmt):
    group = request.args.get("group")

    valid_proposal_ids = []

    if group in groups.keys():
        valid_proposal_ids = groups[group]["valid_proposal_ids"];

    if key not in valid_proposal_ids:
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

        return mwiki_template.render({config["singular"]: transformed_row})
    else:
        raise Exception("Invalid format: " + fmt)

@app.route('/api/<sheet_name>/<key>')
def row(sheet_name, key):
    return formatted_row(sheet_name, key, "json")

@app.route('/data/upload', methods=['POST'])
def upload_sheet():
    from werkzeug.utils import secure_filename

    if 'data_file' not in request.files:
        raise Exception("Must have file in data upload")
    if 'singular' not in request.form:
        raise Exception("Must have the singular name")
    if 'plural' not in request.form:
        raise Exception("Must have the plural name")
    if 'key_column' not in request.form:
        raise Exception("Must have the key_column name")

    file = request.files['data_file']
    if file.filename == '':
        raise Exception("File must have a filename associated with it")
    if file:
        plural = secure_filename(request.form['plural'])
        file.save(os.path.join(app.config['SPREADSHEET_FOLDER'], plural + ".csv"))

        sheet_config[plural] = {}
        sheet_config[plural]["singular"] = request.form['singular']
        sheet_config[plural]["plural"] = request.form['plural']
        sheet_config[plural]["key_column"] = request.form['key_column']
        with open(os.path.join(app.config['SPREADSHEET_FOLDER'], "sheets"), 'w') as f:
            sheet_config.write(f)

        load_sheet(plural)

    return ""
