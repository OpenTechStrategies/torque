# Generated by Django 3.2 on 2021-07-19 18:38

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("core", "0013_spreadsheet_last_updated"),
    ]

    operations = [
        migrations.AddField(
            model_name="tableofcontentscache",
            name="rendered_html",
            field=models.TextField(null=True),
        ),
    ]