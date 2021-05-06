from django.apps import AppConfig
from cache_rebuilder import background
import os


class CacheRebuilderConfig(AppConfig):
    name = "cache_rebuilder"

    def ready(self):
        # This check makes sure that this only boots once
        if os.environ.get("RUN_MAIN", None) != "true":
            cache_rebuilder = background.CacheRebuilder()
            cache_rebuilder.start()