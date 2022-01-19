from pathlib import Path
import os
import pickle
import json

import sqlite3


class Cache:
    """The interface for torque cache objects.

    Torque cache objects are implement various strategies.  This interface
    is meant to be subclassed and all of its methods overridden.  Caches
    only cache documents (not collections), and handle the documents
    as large dictionaries of data.

    Methods
    -------
    contains_document_data(document, last_updated_time):
        returns whether the cache has the specified document
    retrieve_document_data(document)
        returns the dictionary representing the document data
    persist_document(document, last_updated_time)
        save the document to the cache
    cache_timeout()
        returns the cache timeout, in seconds
    """

    def contains_document_data(self, document, last_updated_time):
        """Returns whether the cache has the specifiec DOCUMENT that is newer
        than LAST_UPDATED_TIME.

        If the document doesn't exist, or is old, return False.  The Document
        is passed in in full (rather than just the key) so that things like
        Document.uri() can be used for indexing."""
        pass

    def retrieve_document_data(self, document):
        """Retrieves the document data for DOCUMENT.

        A full Document will be passed in (not just a key), so that indexing
        can be done on more than just the key, for example the Document.uri()
        method."""
        pass

    def persist_document(self, document, last_updated_time):
        """Persisions DOCUMENT which is current as of LAST_UPDATED_TIME.

        Persist the document to whatever store is appropraite.  LAST_UPDATED_TIME
        should be stored along side, so that contains_document_data can check
        against it."""
        pass

    def cache_timeout(self):
        """Returns the cache timeout, in seconds.

        The cache timeout is each cache's definition of how often information should
        be retrieved from the server.  For instance, if it's 3600, that means after
        an hour of running, the torqueclient will go out and update the last_update_time
        of all the collections (lazily loaded), which will then affect calls
        to contains_document_data."""
        pass


class MemoryCache(Cache):
    """A simple in memory cache for the torque client

    Stores all the retrieved objects in memory, indexed by the uri of the document.

    Methods
    -------
    contains_document_data(document, last_updated_time):
        returns whether the cache has the specified document
    retrieve_document_data(document)
        returns the dictionary representing the document data
    persist_document(document, last_updated_time)
        save the document to the cache
    cache_timeout()
        returns the cache timeout, in seconds
    """

    def __init__(self, cache_timeout_seconds=3600):
        """Initializes the cache with the specified timeout (defaults to 3600 seconds)"""
        self.cache = {}
        self.cache_timeout_seconds = cache_timeout_seconds

    def contains_document_data(self, document, last_updated_time):
        """Implements the Cache interface in memory"""
        return (
            document.uri() in self.cache
            and self.cache[document.uri()]["persisted_time"] >= last_updated_time
        )

    def retrieve_document_data(self, document):
        """Implements the Cache interface in memory"""
        return self.cache[document.uri()]["data"]

    def persist_document(self, document, last_updated_time):
        """Implements the Cache interface in memory"""
        self.cache[document.uri()] = {
            "data": document.data,
            "persisted_time": last_updated_time,
        }

    def cache_timeout(self):
        """Implements the Cache interface in memory"""
        return self.cache_timeout_seconds


class DiskCache(Cache):
    """A simple on disk cache for the torque client

    Stores all the retrieved objects on disk, with a configured
    storage location.

    Methods
    -------
    contains_document_data(document, last_updated_time):
        returns whether the cache has the specified document
    retrieve_document_data(document)
        returns the dictionary representing the document data
    persist_document(document, last_updated_time)
        save the document to the cache
    cache_timeout()
        returns the cache timeout, in seconds"""

    def __init__(self, location=None, cache_timeout_seconds=3600):
        """Initializes the disk cache with the specified location and
        timeout (defaults to 3600).  When LOCATION is absent, the default
        is the directory .torqueclient in the home directory of the user.

        Within that directory, cached documents are kept as json in the
        directory structure:

        <base_location>/<collection>/<key>.json

        The cache creates these directories as they're needed.

        """
        if location is None:
            location = os.path.join(Path.home(), ".torqueclient")

        self.location = location
        self.cache_timeout_seconds = cache_timeout_seconds

        try:
            os.mkdir(location)
        except FileExistsError:
            pass

    def _document_path(self, document):
        """Internal function to get the disk cache path for DOCUMENT"""
        return os.path.join(
            self.location, document.collection.name, "%s.json" % document.key
        )

    def contains_document_data(self, document, last_updated_time):
        """Implements the Cache interface on disk."""
        if not os.path.exists(self._document_path(document)):
            return False
        with open(self._document_path(document), "r") as f:
            cached_document = json.load(f)
            return cached_document["persisted_time"] >= last_updated_time.timestamp()

    def retrieve_document_data(self, document):
        """Implements the Cache interface on disk."""
        with open(self._document_path(document), "r") as f:
            return json.load(f)["data"]

    def persist_document(self, document, last_updated_time):
        """Implements the Cache interface on disk."""
        Path(os.path.join(self.location, document.collection.name)).mkdir(
            parents=True, exist_ok=True
        )
        with open(self._document_path(document), "w") as f:
            json.dump(
                {
                    "data": document.data,
                    "persisted_time": last_updated_time.timestamp(),
                },
                f,
            )

    def cache_timeout(self):
        """Implements the Cache interface on disk."""
        return self.cache_timeout_seconds
