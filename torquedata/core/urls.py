from django.urls import path, include
from core import views

urlpatterns = [
    path("search/<sheet_name>", views.search_sheet),
    path("search", views.search_global),
    path("api/<sheet_name>.<fmt>", views.get_sheet),
    path("api/<sheet_name>/toc/<toc_name>.<fmt>", views.get_toc),
    path("api/<sheet_name>/id/<key>.<fmt>", views.get_row_view),
    path("api/<sheet_name>/id/<key>/<field>", views.get_cell_view),
    path("api/<sheet_name>/edit-record/<key>", views.edit_record),
    path("api/<sheet_name>/attachment/<key>/<attachment>", views.get_attachment),
    path("config/<sheet_name>/<wiki_key>/reset", views.reset_config),
    path("config/<sheet_name>/<wiki_key>/complete", views.complete_config),
    path("config/<sheet_name>/<wiki_key>/group", views.set_group_config),
    path("config/<sheet_name>/<wiki_key>/template", views.set_template_config),
    path("upload/sheet", views.upload_sheet),
    path("upload/toc", views.upload_toc),
    path("upload/attachment", views.upload_attachment),
    path("users/username/<username>", views.user_by_username),
]
