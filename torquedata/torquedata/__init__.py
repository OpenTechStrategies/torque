from flask import Flask

import csv

app = Flask(__name__)

try:
    app.config.from_object('config')
except:
    pass

reader = csv.reader(open(app.config.get("SOURCE_DATA_LOCATION"), encoding='utf-8'),  delimiter=',', quotechar='"')
data = {}

header = next(reader)

# The next three json_* are temp placeholders until permissions are up and running
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
json_cols = [ header.index(field) if field in header else -1 for field in json_fields ] 
json_proposals = {}

for row in reader:
    proposal = {}
    for field in header:
        proposal[field] = row[header.index(field)]
    data[proposal["Review Number"]] = proposal

    # Similar to above, placeholder while permissions getting written
    json_proposal = {}
    for json_field, json_col in zip(json_fields, json_cols):
        json_proposal[json_field] = row[json_col] if json_col != -1 else ""

    if json_proposal["Application Level"] != "Invalid":
        json_proposals[json_proposal["Review Number"]] = json_proposal

from torquedata import routes
