# Generated by Django 3.2.5 on 2021-07-30 17:21

from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('core', '0019_auto_20210721_1720'),
    ]

    operations = [
        migrations.AddField(
            model_name='tableofcontents',
            name='raw',
            field=models.BooleanField(default=False),
        ),
    ]