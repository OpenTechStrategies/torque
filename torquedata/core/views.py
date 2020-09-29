import json
import urllib.parse
from werkzeug.utils import secure_filename
from django.http import HttpResponse, JsonResponse, FileResponse
from django.views.decorators.http import require_http_methods
from jinja2 import Template as JinjaTemplate
from core import models


def search(request, sheet_name):
    raise NotImplementedError


def get_sheet(request, sheet_name, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    sheet = models.Spreadsheet.objects.get(sheet=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet, wiki_key=wiki_key, group_name=group,
    )

    return JsonResponse({sheet.name: sheet.clean_rows(sheet_config)})


def get_toc(request, sheet_name, toc_name, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet, wiki_key=wiki_key, group_name=group,
    )
    toc = models.TableOfContents.objects.get(sheet=sheet, name=toc_name)

    rows = sheet.clean_rows(sheet_config)

    data = json.loads(toc.json_file)
    data[sheet.name] = {row[sheet.key_column]: row for row in rows}

    toc_templates = models.Template.objects.get(
        sheet=sheet, wiki_key=wiki_key, type="TOC",
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


def get_row(request, sheet_name, key, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet, wiki_key=wiki_key, group_name=group,
    )
    # todo: fails if row not found, but not obvious why from the error msg
    # you would get
    row = [row for row in sheet.clean_rows(sheet_config) if row.key == key][0]

    if fmt == "json":
        return JsonResponse(row)
    elif fmt == "mwiki":
        templates = models.Template.objects.filter(
            sheet=sheet, wiki_key=wiki_key, type="View",
        )
        if "view" in request.GET:
            template = templates.get(name=request.GET["view"])
        else:
            template = templates.get(is_default=True)
        return HttpResponse(
            JinjaTemplate(template.template_file).render({sheet.object_name: row})
        )
    else:
        raise Exception(f"Invalid format {fmt}")


def get_attachment(request, sheet_name, key, attachment):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]
    attachment_name = secure_filename(urllib.parse.unquote_plus(attachment))

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet, wiki_key=wiki_key, group_name=group,
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


@require_http_methods(["POST"])
def set_group_config(request, sheet_name, wiki_key):
    new_config = json.loads(request.body)

    try:
        config = models.SheetConfig.objects.get(
            sheet__name=sheet_name, wiki_key=wiki_key, group_name=new_config["group"]
        )
    except models.SheetConfig.DoesNotExist:
        # create if does not exist
        sheet = models.Spreadsheet.objects.get(name=sheet_name)
        config = models.SheetConfig(
            sheet=sheet, wiki_key=wiki_key, group_name=new_config["group"]
        )

    config.valid_ids = new_config.get("valid_ids")
    config.valid_columns = new_config.get("columns")

    config.save()

    return HttpResponse(status=200)


@require_http_methods(["POST"])
def set_template_config(request, sheet_name, wiki_key):
    new_config = json.loads(request.body)

    try:
        config = models.Template.objects.get(
            sheet__name=sheet_name,
            wiki_key=wiki_key,
            type=new_config["type"],
            name=new_config["name"],
        )
    except models.Template.DoesNotExist:
        # create if does not exist
        sheet = models.Spreadsheet.objects.get(name=sheet_name)
        config = models.Template(
            sheet=sheet,
            wiki_key=wiki_key,
            type=new_config["type"],
            name=new_config["name"],
            # set as default if first template of this type
            is_default=models.Template.objects.filter(
                sheet__name=sheet.name, wiki_key=wiki_key, type=new_config["type"],
            ).count()
            == 0,
        )

    config.template = new_config["template"]

    config.save()

    return HttpResponse(status=200)


@require_http_methods(["POST"])
def upload_sheet(request):
    with request.FILES["data_file"].open() as f:
        sheet, rows = models.Spreadsheet.from_csv(
            name=request.POST["sheet_name"],
            object_name=request.POST["object_name"],
            key_column=request.POST["key_column"],
            file=f,
        )
    sheet.save()
    for row in rows:
        row.save()

    return HttpResponse(status=200)


@require_http_methods(["POST"])
def upload_toc(request):
    sheet = models.Spreadsheet.objects.get(name=request.POST["sheet_name"])
    toc = models.TableOfContents(
        sheet=sheet,
        name=request.POST["toc_name"],
        json_file=request.FILES["json"].read(),
        template_file=request.FILES["template"].read(),
    )
    toc.save()

    return HttpResponse(status=200)


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

