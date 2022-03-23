import time
from multiprocessing import Process
from django.db import transaction
from django import db
import sys
from django.contrib.postgres.search import SearchVector
import traceback


class RebuildWikiConfigs:
    def run(self):
        from torque import models

        for config in models.WikiConfig.objects.filter(cache_dirty=True).all():
            # We do this outside of the transaction, because if someone comes
            # along and dirties it again while we're rebuilding, we want to
            # rebuild it after we're done rebuilding it.
            config.cache_dirty = False
            config.save()
            print(
                "Rebuilding search index for %s: %s"
                % (config.wiki.wiki_key, config.group)
            )
            with transaction.atomic():
                config.rebuild_search_index()
            print(
                "Rebuilding template index for %s: %s"
                % (config.wiki.wiki_key, config.group)
            )
            with transaction.atomic():
                config.rebuild_template_cache()


class RebuildTOCs:
    def run(self):
        from torque import models

        for toc_cache in models.TableOfContentsCache.objects.filter(dirty=True).all():
            # As above, we do this outside of the transaction, because if someone comes
            # along and dirties it again while we're rebuilding, we want to
            # rebuild it after we're done rebuilding it.
            print(
                "Rebuilding toc %s (%s): %s..."
                % (toc_cache.toc.collection.name, toc_cache.wiki_config.group, toc_cache.toc.name),
                end=''
            )
            toc_cache.dirty = False
            toc_cache.save()
            with transaction.atomic():
                toc_cache.rebuild()
            print("Rebuilt")


class RebuildSearchCacheDocuments:
    def run(self):
        from torque import models

        for cache_document in models.SearchCacheDocument.objects.filter(dirty=True):
            collection = cache_document.document.collection
            for config in collection.configs.all():
                document_dict = cache_document.document.to_dict(config)
                cache_document.data = " ".join(list(map(str, document_dict.values())))
                cache_document.dirty = False
                cache_document.save()
                models.SearchCacheDocument.objects.filter(id=cache_document.id).update(
                    data_vector=SearchVector("data")
                )


class CacheRebuilder(Process):
    def __init__(self):
        super().__init__()
        self.daemon = True

    def run(self):
        db.connections.close_all()

        while True:
            try:
                RebuildWikiConfigs().run()
                RebuildTOCs().run()
                RebuildSearchCacheDocuments().run()
            except:
                print("Rebuilder failed a loop due to %s" % sys.exc_info()[0])
                print(traceback.format_exc())

            time.sleep(5)
