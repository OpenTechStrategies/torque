from pathlib import Path
import os
import pickle
import json

import sqlite3

class Cache:
    def contains_document_data(self, document, last_updated_time):
        pass

    def retrieve_document_data(self, document):
        pass

    def persist_documenta(self, document, last_udpated_time):
        pass

    def cache_timeout(self):
        pass


class MemoryCache(Cache):
    def __init__(self, cache_timeout_seconds=3600):
        self.cache = {}
        self.cache_timeout_seconds = cache_timeout_seconds

    def contains_document_data(self, document, last_updated_time):
        return document.uri() in self.cache and self.cache[document.uri()]["persisted_time"] >= last_updated_time

    def retrieve_document_data(self, document):
        return self.cache[document.uri()]["data"]

    def persist_document(self, document, last_updated_time):
        self.cache[document.uri()] = {"data": document.data, "persisted_time": last_updated_time}

    def cache_timeout(self):
        return self.cache_timeout_seconds


class DiskCache(Cache):
    def __init__(self, location=None, cache_timeout_seconds=3600):
        if location is None:
            location = os.path.join(Path.home(), ".torqueclient")

        self.location = location
        self.cache_timeout_seconds = cache_timeout_seconds

        try:
            os.mkdir(location)
        except FileExistsError:
            pass

    def document_path(self, document):
        return os.path.join(self.location, document.collection.name, "%s.json" % document.key)

    def contains_document_data(self, document, last_updated_time):
        if not os.path.exists(self.document_path(document)):
            return False
        with open(self.document_path(document), "r") as f:
            cached_document = json.load(f)
            return cached_document["persisted_time"] >= last_updated_time.timestamp()

    def retrieve_document_data(self, document):
        with open(self.document_path(document), "r") as f:
            return json.load(f)["data"]

    def persist_document(self, document, last_updated_time):
        Path(os.path.join(self.location, document.collection.name)).mkdir(parents=True, exist_ok=True)
        with open(self.document_path(document), "w") as f:
            json.dump({"data": document.data, "persisted_time": last_updated_time.timestamp()}, f)

    def cache_timeout(self):
        return self.cache_timeout_seconds
