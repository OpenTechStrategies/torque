import mwclient
import json

import dateutil.parser

from .cache import DiskCache, MemoryCache
from datetime import datetime

class Torque:
    def __init__(self, url, username, password, cache=DiskCache()):
        """Initializes to a running torque system available from
        a mediawiki instance at URL that is accessed using USERNAME
        and PASSWORD.

        URL must be fully qualified with the protocol, ie
        http://your.domain.tld/

        CACHE is an optional cache that implements cache.Cache,
        defaulting to the DiskCache"""
        (scheme, host) = url.split("://")
        self.site = mwclient.Site(host, path="/", scheme=scheme, reqs={"timeout": 300})
        self.site.login(username, password)
        self.cache = cache

        self.collections = Collections(self)

        information = self.get_data("/system")
        self.documents_alias = None
        if "collections_alias" in information:
            self.documents_alias = information["documents_alias"]

            setattr(self, information["collections_alias"], self.collections)

    def search(self, search_term, collection_name=None):
        """Search the connected torque system for SEARCH_TERM.

        Optionally pass in a COLLECTION_NAME to restrict the results
        to that collection."""
        path = "/collections/%s/search" % collection_name if collection_name else "/search"
        response = []
        for uri in self.site.api('torquedataconnect', format='json', path=path, q=search_term)["result"]:
            parts = uri.split("/", 4)
            response.append(self.collections[parts[2]].documents[parts[4]])

        return response

    def get_data(self, path):
        """Utility method to get data from the server located at PATH"""
        return self.site.api('torquedataconnect', format='json', path=path)["result"]


class Collections:
    def __init__(self, torque):
        self.torque = torque
        self.collection_data = {}
        self.collection_names = self.torque.get_data("/collections")

    def __iter__(self):
        self.idx = 0
        return self

    def __next__(self):
        if self.idx < len(self.collection_names):
            collection_name = self.collection_names[self.idx]
            self.idx += 1
            return self[collection_name]
        else:
            raise StopIteration()

    def __getitem__(self, collection_name):
        if collection_name not in self.collection_data:
            self.collection_data[collection_name] = Collection(self.torque, collection_name)

        return self.collection_data[collection_name]


class Collection:
    def __init__(self, torque, name):
        self.torque = torque
        self.name = name

        self.documents = Documents(self.torque, self)
        if torque.documents_alias:
            setattr(self, torque.documents_alias, self.documents)

        self.refresh_from_server()

    def search(self, search_term):
        return self.torque.search(search_term, self.name)

    def refresh_from_server(self):
        collection_information = self.torque.get_data("/collections/%s" % self.name)
        self.fields = collection_information["fields"]
        self.last_updated = dateutil.parser.isoparse(collection_information["last_updated"])
        self.document_data = None
        self.refreshed_at = datetime.now()

    def evaluate_cache(self):
        if self.torque.cache is not None and (datetime.now() - self.refreshed_at).seconds > self.torque.cache.cache_timeout():
            self.refresh_from_server()


class Documents:
    def __init__(self, torque, collection):
        self.torque = torque
        self.collection = collection
        self.keys = None

    def __iter__(self):
        if self.keys is None:
            self.keys = self.torque.get_data("/collections/%s/documents" % self.collection.name)
        self.idx = 0
        return self

    def __next__(self):
        if self.idx < len(self.keys):
            key = self.keys[self.idx]
            self.idx += 1
            return self[key]
        else:
            raise StopIteration()

    def __getitem__(self, key):
        # We always return a new Document here because we want to respect whatever
        # caching strategy the end user has decided to use.
        return Document(self.torque, self.collection, key)


class Document:
    def __init__(self, torque, collection, key):
        self.torque = torque
        self.collection = collection
        self.key = key
        self.data = None

    def __getitem__(self, field):
        return self.get_data()[field]

    def __setitem__(self, field, new_value):
        self.get_data()
        self.torque.site.api(
            'torquedataconnect',
            format='json',
            path="%s/fields/%s" % (
                self.uri(),
                field
            ),
            new_value=new_value)
        self.data[field] = new_value

        if self.torque.cache is not None:
            self.torque.cache.persist_document(self)

    def uri(self):
        return "/collections/%s/documents/%s" % (self.collection.name, self.key)

    def keys(self):
        return self.get_data().keys()

    def get_data(self):
        if self.data is None:
            if self.torque.cache is not None:
                self.collection.evaluate_cache()
                if self.torque.cache.contains_document_data(self, self.collection.last_updated):
                    self.data = self.torque.cache.retrieve_document_data(self)

            if self.data is None:
                self.data = self.torque.get_data(self.uri())

            if self.torque.cache is not None and not self.torque.cache.contains_document_data(self, self.collection.last_updated):
                self.torque.cache.persist_document(self, self.collection.last_updated)

        return self.data
