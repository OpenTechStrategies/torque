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


def get_wiki(request, collection_name):
    wiki_key = request.GET["wiki_key"]

    if "wiki_keys" in request.GET:
        wiki_keys = request.GET["wiki_keys"].split(",")
        collection_names = request.GET["collection_names"].split(",")
        mapping = dict(zip(collection_names, wiki_keys))

        if collection_name in mapping:
            wiki_key = mapping[collection_name]

    return models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]


def search(q, offset, template_config, wiki_configs, fmt, multi):
    results = (
        models.SearchCacheDocument.objects.filter(
            collection__in=wiki_configs.values_list("collection", flat=True),
            wiki__wiki_key__in=wiki_configs.values_list("wiki__wiki_key", flat=True),
            group__in=wiki_configs.values_list("group", flat=True),
            wiki_config__in=wiki_configs,
            data_vector=q,
        )
        .select_related("document")
        .select_related("collection")
        .annotate(rank=SearchRank(F("data_vector"), SearchQuery(q)))
        .order_by("-rank")
    )

    if fmt == "mwiki":
        resp = ""

        for result in results[offset : (offset + 20)]:
            template = JinjaTemplate(
                models.Template.objects.get(
                    name="Search", collection=template_config.collection
                ).get_file_contents()
            )
            resp += template.render(
                {
                    template_config.collection.object_name: result.document.to_dict(
                        result.wiki_config
                    )
                }
            )
            resp += "\n\n"

        return HttpResponse(str(results.count()) + " " + resp)
    elif fmt == "json":
        response = [
            "/%s/%s/%s/%s"
            % (
                config.COLLECTIONS_ALIAS or "collections",
                result.collection.name,
                config.DOCUMENTS_ALIAS or "documents",
                result.document.key,
            )
            for result in results
        ]

        return JsonResponse(response, safe=False)
    else:
        raise Exception(f"Invalid format {fmt}")


def search_global(request, fmt):
    q = request.GET["q"]
    offset = int(request.GET.get("offset", 0))
    group = request.GET["group"]
    global_wiki_key = request.GET["wiki_key"]
    global_collection_name = request.GET["collection_name"]
    wiki_keys = request.GET["wiki_keys"].split(",")
    collection_names = request.GET["collection_names"].split(",")
    global_config = models.WikiConfig.objects.get(
        collection__name=global_collection_name,
        wiki__wiki_key=global_wiki_key,
        group=group,
    )
    configs = models.WikiConfig.objects.filter(
        collection__name__in=collection_names, wiki__wiki_key__in=wiki_keys, group=group
    ).all()
    return search(q, offset, global_config, configs, fmt, True)


def search_collection(request, collection_name, fmt):
    q = request.GET["q"]
    offset = int(request.GET.get("offset", 0))
    group = request.GET["group"]
    wiki = get_wiki(request, collection_name)
    configs = models.WikiConfig.objects.filter(
        collection__name=collection_name, wiki=wiki, group=group
    )
    return search(q, offset, configs.first(), configs, fmt, False)


def edit_record(collection_name, key, group, wiki, field, new_value):
    collection = models.Collection.objects.get(name=collection_name)
    document = models.Document.objects.get(collection=collection, key=key)
    wiki_config = models.WikiConfig.objects.get(
        collection=collection,
        wiki=wiki,
        group=group,
    )

    levels = field.split("||")

    if levels[0] in [field.name for field in wiki_config.valid_fields.all()]:
        value = document.values.get(field__name=levels[0])

        if len(levels) == 1:
            to_save = new_value
        else:
            to_save = value.to_python()
            for idx in range(1, len(levels)):
                if idx + 1 == len(levels):
                    to_save[levels[idx]] = new_value
                else:
                    to_save = to_save[levels[idx]]

        value.latest = json.dumps(to_save)
        value.save()
        edit_record = models.ValueEdit(
            collection=collection,
            value=value,
            updated=json.dumps(to_save),
            message="",
            edit_timestamp=datetime.now,
            wiki=wiki,
        )
        edit_record.save()

    models.TableOfContentsCache.objects.filter(
        toc__in=collection.tables_of_contents.all()
    ).update(dirty=True)
    models.SearchCacheDocument.objects.filter(document=document).update(dirty=True)

    collection.last_updated = datetime.now
    collection.save()


def get_collections(request, fmt):
    collection_names = [x for x in request.GET["collection_names"].split(",") if x]

    return JsonResponse(collection_names, safe=False)


def get_collection(request, collection_name, fmt):
    if fmt == "json":
        response = {"name": collection_name}

        collection = models.Collection.objects.get(name=collection_name)

        if "group" in request.GET:
            group = request.GET["group"]
            wiki = get_wiki(request, collection_name)
            wiki_config = models.WikiConfig.objects.get(
                collection=collection,
                wiki=wiki,
                group=group,
            )

            response["fields"] = [
                field.name for field in wiki_config.valid_fields.all()
            ]
        else:
            response["fields"] = [field.name for field in collection.fields.all()]

        response["last_updated"] = collection.last_updated.isoformat()

        return JsonResponse(response)
    else:
        raise Exception(f"Invalid format {fmt}")


def get_toc(request, collection_name, toc_name, fmt):
    group = request.GET["group"]
    wiki = get_wiki(request, collection_name)
    collection = models.Collection.objects.get(name=collection_name)

    try:
        wiki_config = models.WikiConfig.objects.get(
            collection=collection,
            wiki=wiki,
            group=group,
        )
    except:
        return HttpResponse(status=403)

    toc = models.TableOfContents.objects.get(collection=collection, name=toc_name)

    if group == "":
        return HttpResponse(status=403)

    if fmt == "mwiki":
        return HttpResponse(toc.render_to_mwiki(wiki_config))
    elif fmt == "html":
        cached_toc = wiki_config.cached_tocs.get(toc=toc)

        return HttpResponse(cached_toc.rendered_html)
    else:
        raise Exception(f"Invalid format {fmt}")


def get_documents(request, collection_name, fmt):
    collection = models.Collection.objects.get(name=collection_name)
    group = request.GET["group"]
    wiki = get_wiki(request, collection_name)

    wiki_config = models.WikiConfig.objects.get(
        collection=collection,
        wiki=wiki,
        group=group,
    )

    if fmt == "json":
        return JsonResponse(
            [document.key for document in wiki_config.valid_ids.all()], safe=False
        )
    else:
        raise Exception(f"Invalid format {fmt}")


def get_document(group, wiki, key, fmt, collection_name, view=None):
    collection = models.Collection.objects.get(name=collection_name)
    wiki_config = models.WikiConfig.objects.get(
        collection=collection,
        wiki=wiki,
        group=group,
    )
    document = models.Document.objects.get(key=key, collection=collection).to_dict(
        wiki_config
    )

    if fmt == "json":
        return JsonResponse(document)
    elif fmt == "mwiki":
        templates = models.Template.objects.filter(
            collection=collection,
            wiki=wiki,
            type="View",
        )
        if view is not None:
            template = templates.get(name=view)
        else:
            template = templates.get(is_default=True)

        rendered_template = JinjaTemplate(template.get_file_contents()).render(
            {collection.object_name: document}
        )
        return HttpResponse(rendered_template)
    elif fmt == "dict":
        return document
    elif fmt == "html":
        # Return the empty string because we don't have a cached version, and the
        # TDC extension will read that and attempt to get the mwiki version.
        return HttpResponse("")
    else:
        raise Exception(f"Invalid format {fmt}")


def get_document_view(request, collection_name, key, fmt):
    group = request.GET["group"]
    wiki = get_wiki(request, collection_name)
    return get_document(
        group, wiki, key, fmt, collection_name, request.GET.get("view", None)
    )


def field(request, collection_name, key, field, fmt):
    field = urllib.parse.unquote_plus(field)
    if request.method == "GET":
        group = request.GET["group"]
        wiki = get_wiki(request, collection_name)
        document = get_document(group, wiki, key, "dict", collection_name, None)

        value = document
        for level in field.split("||"):
            value = value[level]

        return JsonResponse(value, safe=False)
    elif request.method == "POST":
        post_fields = json.loads(request.body)
        group = post_fields["group"]
        wiki = models.Wiki.objects.get(wiki_key=post_fields["wiki_key"])
        new_value = post_fields["new_value"]
        edit_record(collection_name, key, group, wiki, field, new_value)
        return HttpResponse(201)


def get_attachment(request, collection_name, key, attachment):
    group = request.GET["group"]
    wiki = get_wiki(request, collection_name)
    attachment_name = secure_filename(urllib.parse.unquote_plus(attachment))

    collection = models.Collection.objects.get(name=collection_name)
    wiki_config = models.WikiConfig.objects.get(
        collection=collection,
        wiki=wiki,
        group=group,
    )
    document = collection.documents.get(key=key)
    attachment = models.Attachment.objects.get(name=attachment_name, document=document)

    if not wiki_config.valid_fields.filter(id=attachment.permissions_field.id).exists():
        raise Exception("Not permitted to see this attachment.")

    content_type = magic.from_buffer(attachment.file.open("rb").read(1024), mime=True)
    return FileResponse(
        attachment.file.open("rb"), filename=attachment_name, content_type=content_type
    )


def reset_config(request, collection_name, wiki_key):
    wiki = models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]
    wiki.username = None
    wiki.password = None
    wiki.script_path = None
    wiki.server = None
    wiki.save()

    models.WikiConfig.objects.filter(
        collection__name=collection_name, wiki=wiki
    ).update(in_config=False)
    models.Template.objects.filter(collection__name=collection_name, wiki=wiki).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
# Even though collection_name isn't user here, we add it so that the urls
# all nicely line up with the other config requests
def set_wiki_config(request, collection_name, wiki_key):
    wiki = models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]
    wiki.username = request.POST["username"]
    wiki.password = request.POST["password"]
    wiki.script_path = request.POST["script_path"]
    wiki.server = request.POST["server"]
    wiki.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_group_config(request, collection_name, wiki_key):
    import hashlib

    new_config = json.loads(request.body)
    collection = models.Collection.objects.get(name=collection_name)
    wiki = models.Wiki.objects.get(wiki_key=wiki_key)

    try:
        config = models.WikiConfig.objects.get(
            collection=collection, wiki=wiki, group=new_config["group"]
        )
    except models.WikiConfig.DoesNotExist:
        config = None

    permissions_sha = hashlib.sha224(
        collection_name.encode("utf-8")
        + str(new_config.get("valid_ids")).encode("utf-8")
        + str(new_config.get("fields")).encode("utf-8")
    ).hexdigest()

    if config is None or permissions_sha != config.search_cache_sha:
        if config is not None:
            config.valid_ids.clear()
            config.valid_fields.clear()
        else:
            config = models.WikiConfig(
                collection=collection, wiki=wiki, group=new_config["group"]
            )
            config.save()

            for toc in collection.tables_of_contents.all():
                (cache, created) = models.TableOfContentsCache.objects.update_or_create(
                    toc=toc, wiki_config=config
                )
                cache.dirty = True
                cache.save()

        config.search_cache_sha = permissions_sha

        valid_documents = models.Document.objects.filter(
            collection=collection, key__in=new_config.get("valid_ids")
        )
        valid_fields = models.Field.objects.filter(name__in=new_config.get("fields"))
        config.save()
        config.valid_ids.add(*valid_documents)
        config.valid_fields.add(*valid_fields)
        config.search_cache_dirty = True

    config.in_config = True
    config.save()

    return HttpResponse(status=200)


def complete_config(request, collection_name, wiki_key):
    models.WikiConfig.objects.filter(
        collection__name=collection_name, wiki__wiki_key=wiki_key, in_config=False
    ).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_template_config(request, collection_name, wiki_key):
    new_config = json.loads(request.body)

    conf_name = new_config["name"]
    conf_type = new_config["type"]

    collection = models.Collection.objects.get(name=collection_name)
    wiki = models.Wiki.objects.get(wiki_key=wiki_key)
    try:
        config = models.Template.objects.get(
            collection=collection,
            wiki=wiki,
            type=conf_type,
            name=conf_name,
        )
    except models.Template.DoesNotExist:
        # create if does not exist
        config = models.Template(
            collection=collection,
            wiki=wiki,
            type=conf_type,
            name=conf_name,
            # set as default if first template of this type
            is_default=not models.Template.objects.filter(
                collection__name=collection.name,
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
def upload_collection(request):
    with request.FILES["data_file"].open(mode="rt") as f:
        collection, documents = models.Collection.from_json(
            name=request.POST["collection_name"],
            object_name=request.POST["object_name"],
            key_field=request.POST["key_field"],
            file=f,
        )
    collection.save()

    # Regenerate search caches in case data has changed.  We assume that the
    # cache is invalid, making uploading a collection be a very expensive operation,
    # but that's probably better than attempting to analyze cache invalidation
    # and failing.

    for config in models.WikiConfig.objects.filter(collection=collection):
        config.search_cache_dirty = True
        config.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_toc(request):
    collection = models.Collection.objects.get(name=request.POST["collection_name"])
    (template, created) = models.Template.objects.update_or_create(
        collection=collection,
        type="uploaded_template",
        name=request.POST["toc_name"],
    )
    template.template_file = request.FILES["template"]
    template.save()
    json_file = request.FILES["json"].read().decode("utf-8")
    (toc, created) = models.TableOfContents.objects.update_or_create(
        collection=collection,
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

    for config in collection.configs.all():
        (cache, created) = models.TableOfContentsCache.objects.update_or_create(
            toc=toc,
            wiki_config=config,
        )
        cache.dirty = True
        cache.save()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def upload_attachment(request):
    collection = models.Collection.objects.get(name=request.POST["collection_name"])
    permissions_field = models.Field.objects.get(
        collection=collection, name=request.POST["permissions_field"]
    )
    document = collection.documents.get(key=request.POST["object_id"])
    (attachment, changed) = models.Attachment.objects.update_or_create(
        collection=collection,
        name=secure_filename(request.POST["attachment_name"]),
        document=document,
    )
    attachment.permissions_field=permissions_field
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
