from flask import Flask

import csv

app = Flask(__name__)

try:
    app.config.from_object('config')
except:
    pass

reader = csv.reader(open(app.config.get("SOURCE_DATA_LOCATION"), encoding='utf-8'),  delimiter=',', quotechar='"')
data = []

header = next(reader)

fields = [
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

cols = [ header.index(field) if field in header else -1 for field in fields ]

for row in reader:
    proposal = {}
    for field, col in zip(fields, cols):
        proposal[field] = row[col] if col != -1 else ""

    if proposal["Application Level"] != "Invalid":
        data.append(proposal)

from torquedata import routes
