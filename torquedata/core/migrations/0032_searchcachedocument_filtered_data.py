# Generated by Django 3.2.7 on 2021-10-17 13:28

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("core", "0031_auto_20210930_1328"),
    ]

    operations = [
        migrations.AddField(
            model_name="searchcachedocument",
            name="filtered_data",
            field=models.JSONField(null=True),
        ),
    ]