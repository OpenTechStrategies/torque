import json
import urllib.parse
from werkzeug.utils import secure_filename
from datetime import datetime
from django.core.files.base import ContentFile
from django.db.models import Q, F
from django.db import transaction
from django.http import HttpResponse, JsonResponse, FileResponse
from django.views.decorators.http import require_http_methods
from django.views.decorators.csrf import csrf_exempt
from django.contrib.postgres.search import SearchVector
from jinja2 import Template as JinjaTemplate
from core import models
from django.contrib.postgres.search import SearchQuery, SearchRank, SearchVector
import magic
import config


def get_wiki(request, sheet_name):
    wiki_key = request.GET["wiki_key"]

    if "wiki_keys" in request.GET:
        wiki_keys = request.GET["wiki_keys"].split(",")
        sheet_names = request.GET["sheet_names"].split(",")
        mapping = dict(zip(sheet_names, wiki_keys))

        if sheet_name in mapping:
            wiki_key = mapping[sheet_name]

    return models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]


def search(q, offset, template_config, sheet_configs, fmt, multi):
    results = (
        models.SearchCacheRow.objects.filter(
            sheet__in=sheet_configs.values_list("sheet", flat=True),
            wiki__wiki_key__in=sheet_configs.values_list("wiki__wiki_key", flat=True),
            group__in=sheet_configs.values_list("group", flat=True),
            sheet_config__in=sheet_configs,
            data_vector=q,
        )
        .select_related("row")
        .select_related("sheet")
        .annotate(rank=SearchRank(F("data_vector"), SearchQuery(q)))
        .order_by("-rank")
    )

    if fmt == "mwiki":
        resp = ""

        for result in results[offset : (offset + 20)]:
            template = JinjaTemplate(
                models.Template.objects.get(
                    name="Search", sheet=template_config.sheet
                ).get_file_contents()
            )
            resp += template.render(
                {
                    template_config.sheet.object_name: result.row.to_dict(
                        result.sheet_config
                    )
                }
            )
            resp += "\n\n"

        return HttpResponse(str(results.count()) + " " + resp)
    elif fmt == "json":
        response = [
            "/%s/%s/%s/%s"
            % (
                config.SHEETS_ALIAS or "sheets",
                result.sheet.name,
                config.ROWS_ALIAS or "rows",
                result.row.key,
            )
            for result in results
        ]

        return JsonResponse(response, safe=False)
    else:
        raise Exception(f"Invalid format {fmt}")


def search_global(request, fmt):
    q = request.GET["q"]
    offset = int(request.GET["offset"])
    group = request.GET["group"]
    global_wiki_key = request.GET["wiki_key"]
    global_sheet_name = request.GET["sheet_name"]
    wiki_keys = request.GET["wiki_keys"].split(",")
    sheet_names = request.GET["sheet_names"].split(",")
    global_config = models.SheetConfig.objects.get(
        sheet__name=global_sheet_name, wiki__wiki_key=global_wiki_key, group=group
    )
    configs = models.SheetConfig.objects.filter(
        sheet__name__in=sheet_names, wiki__wiki_key__in=wiki_keys, group=group
    ).all()
    return search(q, offset, global_config, configs, fmt, True)


def search_sheet(request, sheet_name, fmt):
    q = request.GET["q"]
    offset = int(request.GET["offset"])
    group = request.GET["group"]
    wiki = get_wiki(request, sheet_name)
    configs = models.SheetConfig.objects.filter(
        sheet__name=sheet_name, wiki=wiki, group=group
    )
    return search(q, offset, configs.first(), configs, fmt, False)


def edit_record(sheet_name, key, group, wiki, field, new_value):
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    row = models.Row.objects.get(sheet=sheet, key=key)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki=wiki,
        group=group,
    )

    if field in [col.name for col in sheet_config.valid_columns.all()]:
        cell = row.cells.get(column__name=field)
        cell.latest_value = new_value
        cell.save()
        edit_record = models.CellEdit(
            sheet=sheet,
            cell=cell,
            value=new_value,
            message="",
            edit_timestamp=datetime.now,
            wiki=wiki,
        )
        edit_record.save()

    models.TableOfContentsCache.objects.filter(
        toc__in=sheet.tables_of_contents.all()
    ).update(dirty=True)
    models.SearchCacheRow.objects.filter(row=row).update(dirty=True)

    sheet.last_updated = datetime.now
    sheet.save()


def get_sheets(request, fmt):
    sheet_names = [x for x in request.GET["sheet_names"].split(",") if x]

    return JsonResponse(sheet_names, safe=False)


def get_sheet(request, sheet_name, fmt):
    if fmt == "json":
        response = {"name": sheet_name}

        sheet = models.Spreadsheet.objects.get(name=sheet_name)

        if "group" in request.GET:
            group = request.GET["group"]
            wiki = get_wiki(request, sheet_name)
            sheet_config = models.SheetConfig.objects.get(
                sheet=sheet,
                wiki=wiki,
                group=group,
            )

            response["fields"] = [
                column.name for column in sheet_config.valid_columns.all()
            ]
        else:
            response["fields"] = [column.name for column in sheet.columns.all()]

        response["last_updated"] = sheet.last_updated.isoformat()

        return JsonResponse(response)
    else:
        raise Exception(f"Invalid format {fmt}")


def get_toc(request, sheet_name, toc_name, fmt):
    group = request.GET["group"]
    wiki = get_wiki(request, sheet_name)
    sheet = models.Spreadsheet.objects.get(name=sheet_name)

    try:
        sheet_config = models.SheetConfig.objects.get(
            sheet=sheet,
            wiki=wiki,
            group=group,
        )
    except:
        return HttpResponse(status=403)

    toc = models.TableOfContents.objects.get(sheet=sheet, name=toc_name)

    if group == "":
        return HttpResponse(status=403)

    if fmt == "mwiki":
        return HttpResponse(toc.render_to_mwiki(sheet_config))
    elif fmt == "html":
        cached_toc = sheet_config.cached_tocs.get(toc=toc)

        return HttpResponse(cached_toc.rendered_html)
    else:
        raise Exception(f"Invalid format {fmt}")


def get_rows(request, sheet_name, fmt):
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    group = request.GET["group"]
    wiki = get_wiki(request, sheet_name)

    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki=wiki,
        group=group,
    )

    if fmt == "json":
        return JsonResponse(
            [row.key for row in sheet_config.valid_ids.all()], safe=False
        )
    else:
        raise Exception(f"Invalid format {fmt}")


def get_row(group, wiki, key, fmt, sheet_name, view=None):
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki=wiki,
        group=group,
    )
    row = models.Row.objects.get(key=key, sheet=sheet).to_dict(sheet_config)

    if fmt == "json":
        return JsonResponse(row)
    elif fmt == "mwiki":
        templates = models.Template.objects.filter(
            sheet=sheet,
            wiki=wiki,
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
    elif fmt == "html":
        # Return the empty string because we don't have a cached version, and the
        # TDC extension will read that and attempt to get the mwiki version.
        return HttpResponse("")
    else:
        raise Exception(f"Invalid format {fmt}")


def get_row_view(request, sheet_name, key, fmt):
    group = request.GET["group"]
    wiki = get_wiki(request, sheet_name)
    return get_row(group, wiki, key, fmt, sheet_name, request.GET.get("view", None))


def field(request, sheet_name, key, field, fmt):
    field = urllib.parse.unquote_plus(field)
    if request.method == "GET":
        group = request.GET["group"]
        wiki = get_wiki(request, sheet_name)
        row = get_row(group, wiki, key, "dict", sheet_name, None)
        return JsonResponse(row[field], safe=False)
    elif request.method == "POST":
        post_fields = json.loads(request.body)
        group = post_fields["group"]
        wiki = models.Wiki.objects.get(wiki_key=post_fields["wiki_key"])
        new_value = post_fields["new_value"]
        edit_record(sheet_name, key, group, wiki, field, new_value)
        return HttpResponse(201)


def get_attachment(request, sheet_name, key, attachment):
    group = request.GET["group"]
    wiki = get_wiki(request, sheet_name)
    attachment_name = secure_filename(urllib.parse.unquote_plus(attachment))

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    sheet_config = models.SheetConfig.objects.get(
        sheet=sheet,
        wiki=wiki,
        group=group,
    )
    row = sheet.rows.get(key=key)
    attachment = models.Attachment.objects.get(name=attachment_name, row=row)

    if not sheet_config.valid_columns.filter(
        id=attachment.permissions_column.id
    ).exists():
        raise Exception("Not permitted to see this attachment.")

    content_type = magic.from_buffer(attachment.file.open("rb").read(1024), mime=True)
    return FileResponse(
        attachment.file.open("rb"), filename=attachment_name, content_type=content_type
    )


def reset_config(request, sheet_name, wiki_key):
    wiki = models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]
    wiki.username = None
    wiki.password = None
    wiki.script_path = None
    wiki.server = None
    wiki.save()

    models.SheetConfig.objects.filter(sheet__name=sheet_name, wiki=wiki).update(
        in_config=False
    )
    models.Template.objects.filter(sheet__name=sheet_name, wiki=wiki).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
# Even though sheet_name isn't user here, we add it so that the urls
# all nicely line up with the other config requests
def set_wiki_config(request, sheet_name, wiki_key):
    wiki = models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]
    wiki.username = request.POST["username"]
    wiki.password = request.POST["password"]
    wiki.script_path = request.POST["script_path"]
    wiki.server = request.POST["server"]
    wiki.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_group_config(request, sheet_name, wiki_key):
    import hashlib

    new_config = json.loads(request.body)
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    wiki = models.Wiki.objects.get(wiki_key=wiki_key)

    try:
        config = models.SheetConfig.objects.get(
            sheet=sheet, wiki=wiki, group=new_config["group"]
        )
    except models.SheetConfig.DoesNotExist:
        config = None

    permissions_sha = hashlib.sha224(
        sheet_name.encode("utf-8")
        + str(new_config.get("valid_ids")).encode("utf-8")
        + str(new_config.get("columns")).encode("utf-8")
    ).hexdigest()

    if config is None or permissions_sha != config.search_cache_sha:
        if config is not None:
            config.valid_ids.clear()
            config.valid_columns.clear()
        else:
            config = models.SheetConfig(
                sheet=sheet, wiki=wiki, group=new_config["group"]
            )
            config.save()

            for toc in sheet.tables_of_contents.all():
                (cache, created) = models.TableOfContentsCache.objects.update_or_create(
                    toc=toc, sheet_config=config
                )
                cache.dirty = True
                cache.save()

        config.search_cache_sha = permissions_sha

        valid_rows = models.Row.objects.filter(
            sheet=sheet, key__in=new_config.get("valid_ids")
        )
        valid_columns = models.Column.objects.filter(name__in=new_config.get("columns"))
        config.save()
        config.valid_ids.add(*valid_rows)
        config.valid_columns.add(*valid_columns)
        config.search_cache_dirty = True

    config.in_config = True
    config.save()

    return HttpResponse(status=200)


def complete_config(request, sheet_name, wiki_key):
    models.SheetConfig.objects.filter(
        sheet__name=sheet_name, wiki__wiki_key=wiki_key, in_config=False
    ).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_template_config(request, sheet_name, wiki_key):
    new_config = json.loads(request.body)

    conf_name = new_config["name"]
    conf_type = new_config["type"]

    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    wiki = models.Wiki.objects.get(wiki_key=wiki_key)
    try:
        config = models.Template.objects.get(
            sheet=sheet,
            wiki=wiki,
            type=conf_type,
            name=conf_name,
        )
    except models.Template.DoesNotExist:
        # create if does not exist
        config = models.Template(
            sheet=sheet,
            wiki=wiki,
            type=conf_type,
            name=conf_name,
            # set as default if first template of this type
            is_default=not models.Template.objects.filter(
                sheet__name=sheet.name,
                wiki=wiki,
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

    # Regenerate search caches in case data has changed.  We assume that the
    # cache is invalid, making uploading a sheet be a very expensive operation,
    # but that's probably better than attempting to analyze cache invalidation
    # and failing.

    for config in models.SheetConfig.objects.filter(sheet=sheet):
        config.search_cache_dirty = True
        config.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_toc(request):
    sheet = models.Spreadsheet.objects.get(name=request.POST["sheet_name"])
    (template, created) = models.Template.objects.update_or_create(
        sheet=sheet,
        type="uploaded_template",
        name=request.POST["toc_name"],
    )
    template.template_file = request.FILES["template"]
    template.save()
    json_file = request.FILES["json"].read().decode("utf-8")
    (toc, created) = models.TableOfContents.objects.update_or_create(
        sheet=sheet,
        name=request.POST["toc_name"],
        defaults={
            "json_file": json_file,
            "template": template,
        },
    )
    # Have to repeat this because we need to have it when we create, if
    # we do create (above), but we also need to set it in the case that
    # the TOC already exists in the database
    toc.json_file = json_file
    toc.template = template
    toc.raw = bool(request.POST["raw"])
    toc.save()

    for config in sheet.configs.all():
        (cache, created) = models.TableOfContentsCache.objects.update_or_create(
            toc=toc,
            sheet_config=config,
        )
        cache.dirty = True
        cache.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_attachment(request):
    sheet = models.Spreadsheet.objects.get(name=request.POST["sheet_name"])
    permissions_column = models.Column.objects.get(
        sheet=sheet, name=request.POST["permissions_column"]
    )
    row = sheet.rows.get(key=request.POST["object_id"])
    (attachment, changed) = models.Attachment.objects.update_or_create(
        sheet=sheet,
        name=secure_filename(request.POST["attachment_name"]),
        row=row,
        permissions_column=permissions_column,
    )
    attachment.file = request.FILES["attachment"]
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
