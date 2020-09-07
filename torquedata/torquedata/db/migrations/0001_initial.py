# Generated by Django 3.1.1 on 2020-09-07 12:38

from django.db import migrations, models
import django.db.models.deletion


class Migration(migrations.Migration):

    initial = True

    dependencies = [
    ]

    operations = [
        migrations.CreateModel(
            name='Spreadsheet',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('name', models.CharField(max_length=255)),
                ('object_name', models.CharField(max_length=255)),
                ('_columns', models.TextField()),
                ('key_column', models.TextField()),
            ],
        ),
        migrations.CreateModel(
            name='User',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('username', models.CharField(max_length=255)),
            ],
        ),
        migrations.CreateModel(
            name='TableOfContents',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('json_file', models.FileField(upload_to='')),
                ('template_file', models.FileField(upload_to='')),
                ('sheet', models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name='templates', to='db.spreadsheet')),
            ],
        ),
        migrations.CreateModel(
            name='Row',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('key', models.TextField()),
                ('row_number', models.PositiveIntegerField()),
                ('_raw', models.TextField()),
                ('sheet', models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name='rows', to='db.spreadsheet')),
            ],
        ),
        migrations.CreateModel(
            name='Attachment',
            fields=[
                ('id', models.AutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('file', models.FileField(upload_to='')),
                ('sheet', models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name='attachments', to='db.spreadsheet')),
            ],
        ),
        migrations.AddConstraint(
            model_name='row',
            constraint=models.UniqueConstraint(fields=('sheet', 'row_number'), name='unique_row_number'),
        ),
    ]
