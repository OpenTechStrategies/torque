import json
import urllib.parse
from werkzeug.utils import secure_filename
from datetime import datetime
from django.core.files.base import ContentFile
from django.db.models import Q
from django.http import HttpResponse, JsonResponse, FileResponse
from django.views.decorators.http import require_http_methods
from django.views.decorators.csrf import csrf_exempt
from jinja2 import Template as JinjaTemplate
from core import models


def search(q, sheet_configs):
    results = (
        models.SearchCacheRow.objects.filter(
            sheet__in=sheet_configs.values_list("sheet", flat=True),
            wiki_key__in=sheet_configs.values_list("wiki_key", flat=True),
            group__in=sheet_configs.values_list("group", flat=True),
            sheet_config__in=sheet_configs,
            data__search=q,
        )
        .select_related("row")
        .select_related("sheet")
    )

    resp = f"== {results.count()} results for '{q}' == \n\n"

    for result in results:
        template = JinjaTemplate(
            models.Template.objects.get(
                name="Search", sheet=result.sheet
            ).get_file_contents()
        )
        resp += template.render(
            {result.sheet.object_name: result.row.to_dict(result.sheet_config)}
        )
        resp += "\n\n"

    return HttpResponse(resp)


def search_global(request):
    q = request.GET["q"]
    groups = request.GET["groups"].split(",")
    wiki_keys = request.GET["wiki_keys"].split(",")
    sheet_names = request.GET["sheet_names"].split(",")
    configs = models.SheetConfig.all()
    for group, wiki_key, sheet_name in zip(groups, wiki_keys, sheet_names):
        configs.filter(sheet__name=sheet_name, wiki_key=wiki_key, group=group)
    return search(q, configs)


def search_sheet(request, sheet_name):
    q = request.GET["q"]
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]
    config = models.SheetConfig.objects.filter(
        sheet__name=sheet_name, wiki_key=wiki_key, group=group
    )
    return search(q, config)


def edit_record(request, sheet_name, row_number):
    post_fields = json.loads(request.body)
    group = post_fields["group"]
    wiki_key = post_fields["wiki_key"]
    new_values = json.loads(post_fields["new_values"])
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    config = models.SheetConfig.objects.get(sheet=sheet, wiki_key=wiki_key, group=group)
    row = models.Row.objects.get(sheet=sheet, row_number=row_number)

    for field, val in new_values.items():
        cell = row.cells.get(column__name=field)
        cell.latest_value = val
        cell.save()
        edit_record = models.CellEdit(
            config=config, cell=cell, value=val, message="", edit_timestamp=datetime.now
        )
        edit_record.save()

    return HttpResponse(201)


def get_sheet(request, sheet_name, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    sheet = models.Spreadsheet.objects.get(sheet=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki_key=wiki_key,
        group=group,
    )

    return JsonResponse({sheet.name: sheet.clean_rows(sheet_config)})


def get_global_view_toc(request):
    group = request.GET["group"]
    wiki_keys = request.GET["wiki_keys"].split(",")
    sheet_names = request.GET["sheet_names"].split(",")
    configs = models.SheetConfig.all()

    global_toc = None  # TODO: load globalview TOC
    data = json.loads(global_toc.json_file)

    for wiki_key, sheet_name in zip(wiki_keys, sheet_names):
        configs.filter(sheet__name=sheet_name, wiki_key=wiki_key, group=group)

    # For each sheet, we load in the associated rows
    for config in configs:
        rows = config.sheet.clean_rows(config)
        template = models.Template.objects.get(
            sheet=config.sheet, type="toc", is_default=True
        ).select_related("toc")

        # For each row - we store the template rendering in toc_lines
        data[config.sheet.name] = {row[config.sheet.key_column]: row for row in rows}
        data["toc_lines"][config.sheet_name] = {
            {
                row[sheet.key_column]: JinjaTemplate(template.template_file).render(
                    {sheet.object_name: row}
                )
                for row in rows
            }
        }

    return HttpResponse(JinjaTemplate(global_toc.template.template_file).render(data))


def get_toc(request, sheet_name, toc_name, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    if group == "":
        return HttpResponse(status=403)

    try:
        sheet_config = models.SheetConfig.objects.get(
            sheet=sheet,
            wiki_key=wiki_key,
            group=group,
        )
    except:
        return HttpResponse(status=403)

    toc = models.TableOfContents.objects.get(sheet=sheet, name=toc_name)

    rows = sheet.clean_rows(sheet_config)

    data = json.loads(toc.json_file)
    data[sheet.name] = {row[sheet.key_column]: row for row in rows}

    toc_templates = models.Template.objects.filter(
        sheet=sheet,
        wiki_key=wiki_key,
        type="toc",
    )
    try:
        template = toc_templates.get(name=request.GET.get("view"))
    except models.Template.DoesNotExist:
        template = toc_templates.get(is_default=True)

    data["toc_lines"] = {
        row[sheet.key_column]: JinjaTemplate(template.template_file).render(
            {sheet.object_name: row}
        )
        for row in rows
    }

    return HttpResponse(JinjaTemplate(toc.template_file).render(data))


def get_row(group, wiki_key, key, fmt, sheet_name, view=None):
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki_key=wiki_key,
        group=group,
    )
    row = models.Row.objects.get(key=key, sheet=sheet).to_dict(sheet_config)

    if fmt == "json":
        return JsonResponse(row)
    elif fmt == "mwiki":
        templates = models.Template.objects.filter(
            sheet=sheet,
            wiki_key=wiki_key,
            type="View",
        )
        if view is not None:
            template = templates.get(name=view)
        else:
            template = templates.get(is_default=True)

        rendered_template = JinjaTemplate(template.get_file_contents()).render(
            {sheet.object_name: row}
        )
        return HttpResponse(rendered_template)
    elif fmt == "dict":
        return row
    else:
        raise Exception(f"Invalid format {fmt}")


def get_row_view(request, sheet_name, key, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    return get_row(group, wiki_key, key, fmt, sheet_name, request.GET.get("view", None))


def get_cell_view(request, sheet_name, key, field):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    row = get_row(group, wiki_key, key, "dict", sheet_name, None)
    return JsonResponse({"field": row[field]})


def get_attachment(request, sheet_name, key, attachment):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]
    attachment_name = secure_filename(urllib.parse.unquote_plus(attachment))

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki_key=wiki_key,
        group=group,
    )
    attachment = models.Attachment.objects.get(name=attachment_name, object_id=key)

    if attachment.permissions_column not in sheet_config.valid_columns:
        raise Exception("Not permitted to see this attachment.")

    return FileResponse(attachment.file.open("rb"))


def reset_config(request, sheet_name, wiki_key):
    models.SheetConfig.objects.filter(
        sheet__name=sheet_name, wiki_key=wiki_key
    ).delete()
    models.Template.objects.filter(sheet__name=sheet_name, wiki_key=wiki_key).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_group_config(request, sheet_name, wiki_key):
    new_config = json.loads(request.body)
    sheet = models.Spreadsheet.objects.get(name=sheet_name)

    try:
        config = models.SheetConfig.objects.get(
            sheet__name=sheet_name, wiki_key=wiki_key, group=new_config["group"]
        )
    except models.SheetConfig.DoesNotExist:
        # create if does not exist
        config = models.SheetConfig(
            sheet=sheet, wiki_key=wiki_key, group=new_config["group"]
        )

    config.save()

    valid_rows = models.Row.objects.filter(key__in=new_config.get("valid_ids"))
    valid_columns = models.Column.objects.filter(name__in=new_config.get("columns"))
    config.valid_ids.add(*valid_rows)
    config.valid_columns.add(*valid_columns)

    config.create_search_index(sheet)

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_template_config(request, sheet_name, wiki_key):
    new_config = json.loads(request.body)

    conf_name = new_config["name"]
    conf_type = new_config["type"]
    try:
        config = models.Template.objects.get(
            sheet__name=sheet_name,
            wiki_key=wiki_key,
            type=conf_type,
            name=conf_name,
        )
    except models.Template.DoesNotExist:
        # create if does not exist
        sheet = models.Spreadsheet.objects.get(name=sheet_name)
        config = models.Template(
            sheet=sheet,
            wiki_key=wiki_key,
            type=conf_type,
            name=conf_name,
            # set as default if first template of this type
            is_default=not models.Template.objects.filter(
                sheet__name=sheet.name,
                wiki_key=wiki_key,
                type=conf_type,
            ).exists(),
        )

    config.template_file.save(
        f"{wiki_key}-{conf_name}", ContentFile(new_config["template"])
    )
    config.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_sheet(request):
    with request.FILES["data_file"].open(mode="rt") as f:
        sheet, rows = models.Spreadsheet.from_csv(
            name=request.POST["sheet_name"],
            object_name=request.POST["object_name"],
            key_column=request.POST["key_column"],
            file=f,
        )
    sheet.save()
    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_toc(request):
    sheet = models.Spreadsheet.objects.get(name=request.POST["sheet_name"])
    template = models.Template(
        sheet=sheet,
        type="toc",
        name=request.POST["toc_name"],
        template_file=request.FILES["template"],
    )
    template.save()
    toc = models.TableOfContents(
        sheet=sheet,
        name=request.POST["toc_name"],
        json_file=request.FILES["json"].read().decode("utf-8"),
        template=template,
    )
    toc.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_attachment(request):
    sheet = models.Spreadsheet.objects.get(name=request.POST["sheet_name"])
    attachment = models.Attachment(
        sheet=sheet,
        name=request.POST["attachment_name"],
        object_id=request.POST["object_id"],
        permissions_column=request.POST["permissions_column"],
        file=request.FILES["attachment"],
    )
    attachment.save()

    return HttpResponse(status=200)


def user_by_username(request, username):
    # create user if doesn't exist
    try:
        user = models.User.objects.get(username=username)
    except models.User.DoesNotExist:
        user = models.User(username=username)
        user.save()

    return JsonResponse({"username": user.username, "id": user.pk})
