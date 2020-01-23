from torquedata import app, data

import json

@app.route('/proposals')
def proposals():
    return json.dumps(list(data.values()))

@app.route('/proposals/<application_id>')
def proposal(application_id):
    return json.dumps(data[application_id])
