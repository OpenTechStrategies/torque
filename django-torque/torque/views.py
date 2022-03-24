import json
import urllib.parse
from werkzeug.utils import secure_filename
from datetime import datetime
from django.core.files.base import ContentFile
from django.db.models import Q, F, Count
from django.http import HttpResponse, JsonResponse, FileResponse
from django.views.decorators.http import require_http_methods
from django.views.decorators.csrf import csrf_exempt
from torque import models
from django.contrib.postgres.search import SearchQuery, SearchRank
from django.conf import settings
from torque import utils
from torque.version import __version__

import magic
import csv

jinja_env = utils.get_jinja_env()

def get_wiki(dictionary, collection_name):
    wiki_key = dictionary["wiki_key"]

    if "wiki_keys" in dictionary:
        wiki_keys = dictionary["wiki_keys"].split(",")
        collection_names = dictionary["collection_names"].split(",")
        mapping = dict(zip(collection_names, wiki_keys))

        if collection_name in mapping:
            wiki_key = mapping[collection_name]

    return models.Wiki.objects.get_or_create(wiki_key=wiki_key)[0]


def get_wiki_from_request(request, collection_name):
    return get_wiki(request.GET, collection_name)


def get_system_information(request, fmt):
    if fmt == "json":
        information = {}
        if settings.TORQUE_COLLECTIONS_ALIAS and settings.TORQUE_DOCUMENTS_ALIAS:
            information["collections_alias"] = settings.TORQUE_COLLECTIONS_ALIAS
            information["documents_alias"] = settings.TORQUE_DOCUMENTS_ALIAS

        information["server_version"] = __version__
        return JsonResponse(
            information,
            safe=False,
        )
    else:
        raise Exception(f"Invalid format {fmt}")


def search(q, filters, offset, template_config, wiki_configs, fmt, multi):
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
    )
    filter_results = []
    for filter in settings.TORQUE_FILTERS:
        additional_filters = []
        for name, values in filters.items():
            if name != filter.name():
                q_objects = Q()
                for value in values:
                    q_dict = {"filtered_data__%s" % name: value}
                    q_objects |= Q(**q_dict)
                additional_filters.append(q_objects)
        filter_result = {
            "name": filter.name(),
            "display": filter.display_name(),
            "counts": {},
        }
        grouped_results = (
            results.filter(*additional_filters)
            .values("filtered_data__%s" % filter.name())
            .annotate(total=Count("id"))
        )
        for result in grouped_results:
            name = result["filtered_data__%s" % filter.name()] or "None"
            filter_result["counts"][name] = {
                "name": name,
                "total": result["total"],
            }

        names = list(filter_result["counts"].keys())
        names = filter.sort(names)
        filter_result["counts"] = [
            filter_result["counts"][name]
            for name in names
            if name not in filter.ignored_values()
        ]

        # If the only values returned are ignored ones, then we don't want to show the filter at all!
        if len(filter_result["counts"]) > 0:
            filter_results.append(filter_result)

    additional_filters = []
    for name, values in filters.items():
        q_objects = Q()
        for value in values:
            q_dict = {"filtered_data__%s" % name: value}
            q_objects |= Q(**q_dict)
        additional_filters.append(q_objects)

    returned_results = (
        results.filter(*additional_filters)
        .annotate(rank=SearchRank(F("data_vector"), SearchQuery(q)))
        .order_by("-rank")
    )

    # While this result isn't actually mwiki text, this result is intended
    # for the mediawiki Torque extension.  Probably better to keep
    # the mwiki format than to do something like create a new "torque" format.
    # But, if we decide we need results to go to another renderer, it may
    # be worth being more clear about what we're doing here via the interface.
    if fmt == "mwiki":
        mwiki_text = ""

        for result in returned_results[offset : (offset + 20)]:
            template = jinja_env.from_string(
                models.Template.objects.get(
                    name="Search", collection=template_config.collection
                ).get_file_contents()
            )
            mwiki_text += template.render(
                {
                    template_config.collection.object_name: result.document.to_dict(
                        result.wiki_config
                    )
                }
            )
            mwiki_text += "\n\n"

        return JsonResponse(
            {
                "num_results": returned_results.count(),
                "mwiki_text": mwiki_text,
                "filter_results": filter_results,
            },
            safe=False,
        )
    elif fmt == "json":
        response = [
            "/%s/%s/%s/%s"
            % (
                settings.TORQUE_COLLECTIONS_ALIAS or "collections",
                result.collection.name,
                settings.TORQUE_DOCUMENTS_ALIAS or "documents",
                result.document.key,
            )
            for result in returned_results
        ]

        return JsonResponse(response, safe=False)
    else:
        raise Exception(f"Invalid format {fmt}")


def search_global(request, fmt):
    q = request.GET["q"]
    f = json.loads(request.GET["f"]) if "f" in request.GET and request.GET["f"] else {}
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
    return search(q, f, offset, global_config, configs, fmt, True)


def search_collection(request, collection_name, fmt):
    q = request.GET["q"]
    f = json.loads(request.GET["f"]) if "f" in request.GET and request.GET["f"] else {}
    offset = int(request.GET.get("offset", 0))
    group = request.GET["group"]
    wiki = get_wiki_from_request(request, collection_name)
    configs = models.WikiConfig.objects.filter(
        collection__name=collection_name, wiki=wiki, group=group
    )
    return search(q, f, offset, configs.first(), configs, fmt, False)


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
            wiki = get_wiki_from_request(request, collection_name)
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
    wiki = get_wiki_from_request(request, collection_name)
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
    wiki = get_wiki_from_request(request, collection_name)

    wiki_config = models.WikiConfig.objects.get(
        collection=collection,
        wiki=wiki,
        group=group,
    )

    if fmt == "json":
        return JsonResponse(
            [document.key for document in wiki_config.valid_ids.all()], safe=False
        )
    elif fmt == "mwiki":
        # Because we use mwiki as a format marker for when the mediawiki extension
        # is contacting us, we're going to not fully follow the expected functionality,
        # which would be to return a valid mediawiki page.  Rather, we're going to
        # return the TOC style mediawiki text for the documents in the collection.
        #
        # An alternate path would be figure out how to call document.mwiki with
        # the appropriate template.
        caches = {
            cache.document.key: cache.rendered_text
            for cache in models.TemplateCacheDocument.objects.filter(
                wiki_config=wiki_config
            ).prefetch_related("document")
        }
        return JsonResponse(caches)
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
            try:
                view_object = json.loads(view)

                view_wiki = models.Wiki.objects.get(wiki_key=view_object["wiki_key"])
                view = view_object["view"]
                template = models.Template.objects.get(
                    wiki=view_wiki, type="View", name=view
                )
            except json.JSONDecodeError:
                template = templates.get(name=view)
        else:
            template = templates.get(is_default=True)

        rendered_template = jinja_env.from_string(template.get_file_contents()).render(
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
    wiki = get_wiki_from_request(request, collection_name)
    return get_document(
        group, wiki, key, fmt, collection_name, request.GET.get("view", None)
    )


def field(request, collection_name, key, field, fmt):
    field = urllib.parse.unquote_plus(field)
    if request.method == "GET":
        group = request.GET["group"]
        wiki = get_wiki_from_request(request, collection_name)
        document = get_document(group, wiki, key, "dict", collection_name, None)

        value = document
        for level in field.split("||"):
            value = value[level]

        return JsonResponse(value, safe=False)
    elif request.method == "POST":
        post_fields = json.loads(request.body)
        group = post_fields["group"]
        wiki = get_wiki(post_fields, collection_name)
        new_value = post_fields["new_value"]
        edit_record(collection_name, key, group, wiki, field, new_value)
        return HttpResponse(201)


def get_attachment(request, collection_name, key, attachment):
    group = request.GET["group"]
    wiki = get_wiki_from_request(request, collection_name)
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

    models.Template.objects.filter(collection__name=collection_name, wiki=wiki).update(
        in_config=False
    )

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
        valid_fields = models.Field.objects.filter(
            name__in=new_config.get("fields"), collection=collection
        )
        config.save()
        config.valid_ids.add(*valid_documents)
        config.valid_fields.add(*valid_fields)
        config.cache_dirty = True

    config.in_config = True
    config.save()

    return HttpResponse(status=200)


def complete_config(request, collection_name, wiki_key):
    models.WikiConfig.objects.filter(
        collection__name=collection_name, wiki__wiki_key=wiki_key, in_config=False
    ).delete()
    models.Template.objects.filter(
        collection__name=collection_name, wiki__wiki_key=wiki_key, in_config=False
    ).delete()

    return HttpResponse(status=200)


@csrf_exempt
@require_http_methods(["POST"])
def set_template_config(request, collection_name, wiki_key):
    new_config = json.loads(request.body)

    conf_name = new_config["name"]
    conf_type = new_config["type"]
    default = new_config["default"]

    collection = models.Collection.objects.get(name=collection_name)
    wiki = models.Wiki.objects.get(wiki_key=wiki_key)
    config = models.Template.objects.get_or_create(
        collection=collection, wiki=wiki, type=conf_type, name=conf_name
    )[0]

    config.template_file.save(
        f"{wiki_key}-{conf_name}", ContentFile(new_config["template"])
    )
    config.in_config = True
    config.is_default = default
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
        config.cache_dirty = True
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
        permissions_field=permissions_field,
    )
    attachment.permissions_field = permissions_field
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


@csrf_exempt
@require_http_methods(["POST"])
def add_csv(request):
    def determine_name():
        import string
        import random

        characters = string.ascii_lowercase + string.ascii_uppercase + string.digits
        possible_name = "".join([random.choice(characters) for i in range(6)])
        if models.CsvSpecification.objects.filter(name=possible_name).count() > 0:
            return determine_name()
        else:
            return possible_name

    name = determine_name()
    post_fields = json.loads(request.body)

    documents = []
    for post_doc in post_fields["documents"]:
        documents.append(
            models.Document.objects.get(collection__name=post_doc[0], key=post_doc[1])
        )

    csv_spec = models.CsvSpecification(
        name=name, filename=post_fields["filename"], fields=post_fields["fields"]
    )
    # Save first so the many to many below works correctly
    csv_spec.save()
    csv_spec.documents.set(documents)
    csv_spec.save()
    return JsonResponse(
        {
            "name": name,
        }
    )


def get_csv(request, name, fmt):
    csv_spec = models.CsvSpecification.objects.get(name=name)

    group = request.GET["group"]

    documents = csv_spec.documents.all()
    valid_documents = []
    wiki_configs_for_csv = set()
    for document in documents:
        if document.wiki_config.filter(group=group).count() > 0:
            valid_documents.append(document)
            wiki_configs_for_csv.add(document.wiki_config.get(group=group))

    if fmt == "json":
        document_information = {}
        for document in documents:
            if document.collection.name not in document_information:
                document_information[document.collection.name] = []
            document_information[document.collection.name].append(document.key)
        return JsonResponse(
            {
                "name": name,
                "filename": csv_spec.filename,
                "fields": sorted(csv_spec.fields),
                "documents": document_information,
            }
        )
    elif fmt == "csv":
        field_names = sorted(csv_spec.fields)

        valid_field_names = []
        for wiki_config in wiki_configs_for_csv:
            for field_name in field_names:
                if wiki_config.valid_fields.filter(name=field_name).count() > 0:
                    valid_field_names.append(field_name)

        valid_field_names = sorted(list(set(valid_field_names)))

        response = HttpResponse(
            content_type="text/csv",
            headers={
                "Content-Disposition": 'attachment; filename="%s.csv"'
                % csv_spec.filename
            },
        )
        writer = csv.writer(response)

        columns = []
        for field_name in valid_field_names:
            if field_name in getattr(settings, "TORQUE_CSV_PROCESSORS", {}):
                columns.extend(
                    settings.TORQUE_CSV_PROCESSORS[field_name].field_names(field_name)
                )
            else:
                columns.append(field_name)
        writer.writerow(columns)

        for document in valid_documents:
            row = []
            values_by_field = {
                v.field.name: v
                for v in document.values.filter(
                    field__name__in=valid_field_names
                ).prefetch_related("field")
            }
            for field_name in valid_field_names:
                if (
                    field_name in values_by_field
                    and field_name in getattr(settings, "TORQUE_CSV_PROCESSORS", {})
                ):
                    row.extend(
                        settings.TORQUE_CSV_PROCESSORS[field_name].process_value(
                            values_by_field[field_name].to_python()
                        )
                    )
                elif field_name in values_by_field:
                    row.append(values_by_field[field_name].to_python())
                else:
                    row.append("")
            writer.writerow(row)

        return response
    else:
        raise Exception(f"Invalid format {fmt}")
