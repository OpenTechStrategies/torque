from django.urls import path, include
from core import views
import config

urlpatterns = [
    # When changing, or adding to one of these, ensure that you also do in the section
    # below that respects COLLECTIONS_ALIAS and DOCUMENTS_ALIAS
    path("api/system.<fmt>", views.get_system_information),
    path("api/collections.<fmt>", views.get_collections),
    path("api/search.<fmt>", views.search_global),
    path("api/collections/<collection_name>.<fmt>", views.get_collection),
    path("api/collections/<collection_name>/documents.<fmt>", views.get_documents),
    path(
        "api/collections/<collection_name>/documents/<key>.<fmt>",
        views.get_document_view,
    ),
    path(
        "api/collections/<collection_name>/documents/<key>/attachments/<attachment>",
        views.get_attachment,
    ),
    path(
        "api/collections/<collection_name>/documents/<key>/fields/<field>.<fmt>",
        views.field,
    ),
    path("api/collections/<collection_name>/search.<fmt>", views.search_collection),
    path("api/collections/<collection_name>/tocs/<toc_name>.<fmt>", views.get_toc),
    path("config/<collection_name>/<wiki_key>/reset", views.reset_config),
    path("config/<collection_name>/<wiki_key>/wiki", views.set_wiki_config),
    path("config/<collection_name>/<wiki_key>/complete", views.complete_config),
    path("config/<collection_name>/<wiki_key>/group", views.set_group_config),
    path("config/<collection_name>/<wiki_key>/template", views.set_template_config),
    path("upload/collection", views.upload_collection),
    path("upload/toc", views.upload_toc),
    path("upload/attachment", views.upload_attachment),
    path("users/username/<username>", views.user_by_username),
    path("csv", views.add_csv),
    path("csv/<name>.<fmt>", views.get_csv),
]

# This is duplicated, rather than overriding the above generic "collections"/"documents" endpoints
# because the TorqueDataConnect extension needs to be able to call into this.  So that left
# us three options:
#  * Duplicate the endpoints with configuration
#  * Duplicate the configuration into the mediawiki extension so it knows what to call
#  * Put the configuration into the mediawiki extension and then munge the URIs before
#    calling through to torquedata (here)
#
# The first seemed most sensible at the time
if config.COLLECTIONS_ALIAS and config.DOCUMENTS_ALIAS:
    urlpatterns.extend(
        [
            path("api/%s.<fmt>" % config.COLLECTIONS_ALIAS, views.get_collections),
            path("api/search.<fmt>", views.search_global),
            path(
                "api/%s/<collection_name>.<fmt>" % config.COLLECTIONS_ALIAS,
                views.get_collection,
            ),
            path(
                "api/%s/<collection_name>/%s.<fmt>"
                % (config.COLLECTIONS_ALIAS, config.DOCUMENTS_ALIAS),
                views.get_documents,
            ),
            path(
                "api/%s/<collection_name>/%s/<key>.<fmt>"
                % (config.COLLECTIONS_ALIAS, config.DOCUMENTS_ALIAS),
                views.get_document_view,
            ),
            path(
                "api/%s/<collection_name>/%s/<key>/attachments/<attachment>"
                % (config.COLLECTIONS_ALIAS, config.DOCUMENTS_ALIAS),
                views.get_attachment,
            ),
            path(
                "api/%s/<collection_name>/%s/<key>/fields/<field>.<fmt>"
                % (config.COLLECTIONS_ALIAS, config.DOCUMENTS_ALIAS),
                views.field,
            ),
            path(
                "api/%s/<collection_name>/search.<fmt>" % (config.COLLECTIONS_ALIAS),
                views.search_collection,
            ),
            path(
                "api/%s/<collection_name>/tocs/<toc_name>.<fmt>"
                % (config.COLLECTIONS_ALIAS),
                views.get_toc,
            ),
        ]
    )

# Deprecated, as they have been subsumed by the above api, however
# updating them requires updating all the templates in all the competitions
# which is probably too much for right now
urlpatterns.extend(
    [
        path("api/<collection_name>/id/<key>.<fmt>", views.get_document_view),
        path("api/<collection_name>/toc/<toc_name>.<fmt>", views.get_toc),
    ]
)
