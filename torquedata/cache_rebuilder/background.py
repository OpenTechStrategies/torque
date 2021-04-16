import time
from multiprocessing import Process
from django.db import transaction
from django import db
import sys
from django.contrib.postgres.search import SearchVector


class RebuildSheetConfigs():
    def run(self):
        from core import models
        for config in models.SheetConfig.objects.filter(search_cache_dirty=True).all():
            # We do this outside of the transaction, because if someone comes
            # along and dirties it again while we're rebuilding, we want to
            # rebuild it after we're done rebuilding it.
            config.search_cache_dirty=False
            config.save()
            with transaction.atomic():
                config.rebuild_search_index()


class RebuildTOCs():
    def run(self):
        from core import models
        for toc_cache in models.TableOfContentsCache.objects.filter(dirty=True).all():
            # As above, we do this outside of the transaction, because if someone comes
            # along and dirties it again while we're rebuilding, we want to
            # rebuild it after we're done rebuilding it.
            toc_cache.dirty=False
            toc_cache.save()
            with transaction.atomic():
                toc_cache.rebuild()


class RebuildSearchCacheRows:
    def run(self):
        from core import models
        for cache_row in models.SearchCacheRow.objects.filter(dirty=True):
            sheet = cache_row.row.sheet
            for config in sheet.configs.all():
                row_dict = cache_row.row.to_dict(config)
                cache_row.data=" ".join(list(map(str, row_dict.values())))
                cache_row.dirty=False
                cache_row.save()
                models.SearchCacheRow.objects.filter(id=cache_row.id).update(data_vector=SearchVector("data"))


class CacheRebuilder(Process):
    def __init__(self):
        super().__init__()
        self.daemon = True

    def run(self):
        db.connections.close_all()

        while True:
            try:
                RebuildSheetConfigs().run()
                RebuildTOCs().run()
                RebuildSearchCacheRows().run()
            except:
                print("Rebuilder failed a loop due to %s" % sys.exc_info()[0])

            time.sleep(5)
