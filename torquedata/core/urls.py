from django.urls import path, include
from core import views
import config

urlpatterns = [
    # When changing, or adding to one of these, ensure that you also do in the section
    # below that respects SHEETS_ALIAS and ROWS_ALIAS
    path("api/sheets.<fmt>", views.get_sheets),
    path("api/search.<fmt>", views.search_global),
    path("api/sheets/<sheet_name>.<fmt>", views.get_sheet),
    path("api/sheets/<sheet_name>/rows.<fmt>", views.get_rows),
    path("api/sheets/<sheet_name>/rows/<key>.<fmt>", views.get_row_view),
    path(
        "api/sheets/<sheet_name>/rows/<key>/attachments/<attachment>",
        views.get_attachment,
    ),
    path("api/sheets/<sheet_name>/rows/<key>/fields/<field>.<fmt>", views.field),
    path("api/sheets/<sheet_name>/search.<fmt>", views.search_sheet),
    path("api/sheets/<sheet_name>/tocs/<toc_name>.<fmt>", views.get_toc),
    path("config/<sheet_name>/<wiki_key>/reset", views.reset_config),
    path("config/<sheet_name>/<wiki_key>/complete", views.complete_config),
    path("config/<sheet_name>/<wiki_key>/group", views.set_group_config),
    path("config/<sheet_name>/<wiki_key>/template", views.set_template_config),
    path("upload/sheet", views.upload_sheet),
    path("upload/toc", views.upload_toc),
    path("upload/attachment", views.upload_attachment),
    path("users/username/<username>", views.user_by_username),
]

# This is duplicated, rather than overriding the above generic "sheets"/"rows" endpoints
# because the TorqueDataConnect extension needs to be able to call into this.  So that left
# us three options:
#  * Duplicate the endpoints with configuration
#  * Duplicate the configuration into the mediawiki extension so it knows what to call
#  * Put the configuration into the mediawiki extension and then munge the URIs before
#    calling through to torquedata (here)
#
# The first seemed most sensible at the time
if config.SHEETS_ALIAS and config.ROWS_ALIAS:
    urlpatterns.extend(
        [
            path("api/%s.<fmt>" % config.SHEETS_ALIAS, views.get_sheets),
            path("api/search.<fmt>", views.search_global),
            path("api/%s/<sheet_name>.<fmt>" % config.SHEETS_ALIAS, views.get_sheet),
            path(
                "api/%s/<sheet_name>/%s.<fmt>"
                % (config.SHEETS_ALIAS, config.ROWS_ALIAS),
                views.get_rows,
            ),
            path(
                "api/%s/<sheet_name>/%s/<key>.<fmt>"
                % (config.SHEETS_ALIAS, config.ROWS_ALIAS),
                views.get_row_view,
            ),
            path(
                "api/%s/<sheet_name>/%s/<key>/attachments/<attachment>"
                % (config.SHEETS_ALIAS, config.ROWS_ALIAS),
                views.get_attachment,
            ),
            path(
                "api/%s/<sheet_name>/%s/<key>/fields/<field>.<fmt>"
                % (config.SHEETS_ALIAS, config.ROWS_ALIAS),
                views.field,
            ),
            path(
                "api/%s/<sheet_name>/search.<fmt>" % (config.SHEETS_ALIAS),
                views.search_sheet,
            ),
            path(
                "api/%s/<sheet_name>/tocs/<toc_name>.<fmt>" % (config.SHEETS_ALIAS),
                views.get_toc,
            ),
        ]
    )

# Deprecated, as they have been subsumed by the above api, however
# updating them requires updating all the templates in all the competitions
# which is probably too much for right now
urlpatterns.extend(
    [
        path("search/<sheet_name>.<fmt>", views.search_sheet),
        path("search.<fmt>", views.search_global),
        path("api/<sheet_name>/id/<key>.<fmt>", views.get_row_view),
        path("api/<sheet_name>/toc/<toc_name>.<fmt>", views.get_toc),
    ]
)
