from django.apps import AppConfig
from torque.cache_rebuilder import background
import os


class CacheRebuilderConfig(AppConfig):
    name = "torque.cache_rebuilder"

    def ready(self):
        # This check makes sure that this only boots once
        if os.environ.get("RUN_MAIN", None) != "true" and not os.environ.get(
            "DISABLE_CACHE_REBUILDER", False
        ):
            cache_rebuilder = background.CacheRebuilder()
            cache_rebuilder.start()
