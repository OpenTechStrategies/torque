# Generated by Django 3.1.7 on 2021-04-06 19:09

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ("core", "0007_auto_20210406_1738"),
    ]

    operations = [
        migrations.AlterField(
            model_name="celledit",
            name="value",
            field=models.TextField(),
        ),
    ]
