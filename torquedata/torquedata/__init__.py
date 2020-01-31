from flask import Flask

import csv
import configparser
import os

app = Flask(__name__)

try:
    app.config.from_object('config')
except:
    pass

sheet_config = configparser.ConfigParser()
sheet_config.read(os.path.join(app.config['SPREADSHEET_FOLDER'], "sheets"))

data = {}
def load_sheet(sheet_name):
    data[sheet_name] = {}
    reader = csv.reader(
            open(os.path.join(app.config.get("SPREADSHEET_FOLDER"), sheet_name, sheet_name + ".csv"), encoding='utf-8'),
            delimiter=',',
            quotechar='"'
            )
    header = next(reader)
    for row in reader:
        o = {}
        for (field, cell) in zip(header, row):
            if field == 'Attachments':
                cell = cell.strip().split("\n")
            if field == 'Sustainable Development Goals':
                cell = cell.strip().split("\n")
            if field == 'Priority Populations':
                cell = cell.strip().split("\n")
            o[field] = cell
        data[sheet_name][o[sheet_config[sheet_name]["key_column"]]] = o

for sheet_name in sheet_config.sections():
    if sheet_name is "DEFAULT":
        continue

    load_sheet(sheet_name)

from torquedata import routes
