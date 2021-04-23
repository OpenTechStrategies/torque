import json
import urllib.parse
from werkzeug.utils import secure_filename
from datetime import datetime
from django.core.files.base import ContentFile
from django.db.models import Q
from django.db import transaction
from django.http import HttpResponse, JsonResponse, FileResponse
from django.views.decorators.http import require_http_methods
from django.views.decorators.csrf import csrf_exempt
from django.contrib.postgres.search import SearchVector
from jinja2 import Template as JinjaTemplate
from core import models
from django.contrib.postgres.search import SearchQuery, SearchRank, SearchVector
import magic


def search(q, template_config, sheet_configs):
    results = (
        models.SearchCacheRow.objects.filter(
            sheet__in=sheet_configs.values_list("sheet", flat=True),
            wiki_key__in=sheet_configs.values_list("wiki_key", flat=True),
            group__in=sheet_configs.values_list("group", flat=True),
            sheet_config__in=sheet_configs,
            data_vector=q,
        )
        .select_related("row")
        .select_related("sheet")
        .annotate(rank=SearchRank(SearchVector("data"), SearchQuery(q)))
        .order_by("-rank")
    )

    addendum = ""
    if results.count() > 100:
        addendum = " (showing top 100)"
    resp = f"== {results.count()} results for '{q}'{addendum} == \n\n"

    for result in results[:100]:
        template = JinjaTemplate(
            models.Template.objects.get(
                name="Search", sheet=template_config.sheet
            ).get_file_contents()
        )
        resp += template.render(
            {template_config.sheet.object_name: result.row.to_dict(result.sheet_config)}
        )
        resp += "\n\n"

    return HttpResponse(resp)


def search_global(request):
    q = request.GET["q"]
    group = request.GET["group"]
    global_wiki_key = request.GET["wiki_key"]
    global_sheet_name = request.GET["sheet_name"]
    wiki_keys = request.GET["wiki_keys"].split(",")
    sheet_names = request.GET["sheet_names"].split(",")
    global_config = models.SheetConfig.objects.get(
        sheet__name=global_sheet_name, wiki_key=global_wiki_key, group=group
    )
    configs = models.SheetConfig.objects.all()
    for wiki_key, sheet_name in zip(wiki_keys, sheet_names):
        configs.filter(sheet__name=sheet_name, wiki_key=wiki_key, group=group)
    return search(q, global_config, configs)


def search_sheet(request, sheet_name):
    q = request.GET["q"]
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]
    configs = models.SheetConfig.objects.filter(
        sheet__name=sheet_name, wiki_key=wiki_key, group=group
    )
    return search(q, configs.first(), configs)


def edit_record(request, sheet_name, key):
    post_fields = json.loads(request.body)
    group = post_fields["group"]
    wiki_key = post_fields["wiki_key"]
    new_values = json.loads(post_fields["new_values"])
    sheet = models.Spreadsheet.objects.get(name=sheet_name)
    row = models.Row.objects.get(sheet=sheet, key=key)

    for field, val in new_values.items():
        cell = row.cells.get(column__name=field)
        cell.latest_value = val
        cell.save()
        edit_record = models.CellEdit(
            sheet=sheet,
            cell=cell,
            value=val,
            message="",
            edit_timestamp=datetime.now,
            wiki_key=wiki_key,
        )
        edit_record.save()

    models.TableOfContentsCache.objects.filter(
        toc__in=sheet.tables_of_contents.all()
    ).update(dirty=True)
    models.SearchCacheRow.objects.filter(row=row).update(dirty=True)

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
        line_template = models.Template.objects.get(
            sheet=config.sheet, type="TOC", is_default=True
        ).select_related("toc")

        line_template_contents = line_template.template_file.read().decode("utf-8")
        template_contents = global_toc.template.template_file.read().decode("utf-8")

        # For each row - we store the template rendering in toc_lines
        data[config.sheet.name] = {row[config.sheet.key_column]: row for row in rows}
        data["toc_lines"][config.sheet_name] = {
            {
                row[sheet.key_column]: JinjaTemplate(line_template_contents).render(
                    {sheet.object_name: row}
                )
                for row in rows
            }
        }

    return HttpResponse(JinjaTemplate(global_toc).render(data))


def get_toc(request, sheet_name, toc_name, fmt):
    group = request.GET["group"]
    wiki_key = request.GET["wiki_key"]
    sheet = models.Spreadsheet.objects.get(name=sheet_name)

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
    cached_toc = sheet_config.cached_tocs.get(toc=toc)

    return HttpResponse(cached_toc.rendered_data)


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
    row = sheet.rows.get(key=key)
    attachment = models.Attachment.objects.get(name=attachment_name, row=row)

    if not sheet_config.valid_columns.filter(
        id=attachment.permissions_column.id
    ).exists():
        raise Exception("Not permitted to see this attachment.")

    content_type = magic.from_buffer(attachment.file.open("rb").read(1024), mime=True)
    return FileResponse(
        attachment.file.open("rb"),
        filename=attachment_name,
        content_type=content_type
    )


def reset_config(request, sheet_name, wiki_key):
    models.SheetConfig.objects.filter(sheet__name=sheet_name, wiki_key=wiki_key).update(
        in_config=False
    )
    models.Template.objects.filter(sheet__name=sheet_name, wiki_key=wiki_key).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_group_config(request, sheet_name, wiki_key):
    import hashlib

    new_config = json.loads(request.body)
    sheet = models.Spreadsheet.objects.get(name=sheet_name)

    try:
        config = models.SheetConfig.objects.get(
            sheet__name=sheet_name, wiki_key=wiki_key, group=new_config["group"]
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
                sheet=sheet, wiki_key=wiki_key, group=new_config["group"]
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
        sheet__name=sheet_name, wiki_key=wiki_key, in_config=False
    ).delete()

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
