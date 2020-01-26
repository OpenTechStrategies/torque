from torquedata import app, data
from jinja2 import Template

import json

#with open('mwiki.j2') as template_file: mwiki_template_str = template_file.read()
mwiki_template_str = "{{ proposal['Review Number'] }}"

@app.route('/proposals')
def proposals():
    return json.dumps(list(data.values()))

@app.route('/proposals/<application_id>.<fmt>')
def formatted_proposal(application_id, fmt):
    if fmt == "json":
        return json.dumps(data[application_id])
    elif fmt == "mwiki":
        mwiki_template = Template(mwiki_template_str)
        return mwiki_template.render({"proposal": data[application_id]})
    else:
        raise Exception("Invalid format: " + fmt)

@app.route('/proposals/<application_id>')
def proposal(application_id):
    return formatted_proposal(application_id, "json")
