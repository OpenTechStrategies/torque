from torquedata import app, data

import json

@app.route('/')
@app.route('/index')

def index():
    return json.dumps(data)
