# Generated by Django 3.2 on 2021-05-31 19:08

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("core", "0012_searchcacherow_dirty"),
    ]

    operations = [
        migrations.AddField(
            model_name="spreadsheet",
            name="last_updated",
            field=models.DateTimeField(auto_now=True),
        ),
    ]
