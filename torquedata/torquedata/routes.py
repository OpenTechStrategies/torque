from torquedata import app, data, sheet_config, load_sheet
from jinja2 import Template
from flask import request

import json
import os

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

def cull_for_json(o):
    return {k:v for (k,v) in o.items() if (k in json_fields)}

@app.route('/api/<sheet_name>')
def sheet(sheet_name):
    return json.dumps({sheet_name: [cull_for_json(o) for o in list(data[sheet_name].values())]})

@app.route('/api/<sheet_name>/<key>.<fmt>')
def formatted_row(sheet_name, key, fmt):
    if fmt == "json":
        return json.dumps(cull_for_json(data[sheet_name][key]))
    elif fmt == "mwiki":
        config = sheet_config[sheet_name]
        mwiki_template = Template(mwiki_template_str)
        return mwiki_template.render({config["singular"]: data[sheet_name][key]})
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
