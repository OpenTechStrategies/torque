import mwclient
import json

import dateutil.parser

from .cache import DiskCache, MemoryCache
from .version import __version__
from datetime import datetime

class Torque:
    """The entrypoint to accessing a torque system.

    This is the main object that code using the client should instantiate.
    It manages the connection to the server, as well as handling all the
    plumbing required to translate the http calls using the mwclient library.

    Attributes
    ----------
    site : mwclient.Site
        The site object holding the connection
    cache : cache.Cache
        The local cache strategy
    collections : Collections
        All the collections that are available from the server
    information : dict
        The server configuration, for things like version and aliases

    When started up, an alias is fetched from the server.  This alias
    is then set as a synonym alias for collections.  For example, if the
    COLLECTIONS_ALIAS on the server is set to "competitions", then there's
    an additional attribute as follows:

    competition : Collections
        All the competitions available from the server


    Methods
    -------
    search(search_term, collection_name=None)
        searches for documents
    bulk_fetch(documents, num_threads=10)
        eager loads the documents
    """

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

        information = self._get_data("/system")
        self.documents_alias = None
        if "collections_alias" in information:
            self.documents_alias = information["documents_alias"]

            setattr(self, information["collections_alias"], self.collections)

        if information["server_version"] != __version__:
            raise Exception("API version %s does not match server version %s, aborting" % (__version__, information["server_version"]))

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

    def _get_data(self, path):
        """Internal utility method to get data from the server located at PATH"""
        return self.site.api('torquedataconnect', format='json', path=path)["result"]

    def bulk_fetch(self, documents, num_threads=10):
        """Fetch DOCUMENTS in bulk, split over NUM_THREADS threads.

        As DOCUMENTS are lazy loaded in the system, this greedily fills
        them with the data from the server.  This is done in a multi threaded
        way to save time.

        DOCUMENTS can either be [Document*], or a Documents object, so it can
        be used with either the result of search() or
        collections['somekey'].documents"""
        if isinstance(documents, list):
            docs_to_process = documents.copy()
        elif isinstance(documents, Documents):
            docs_to_process = [doc for doc in documents]
        else:
            raise Exception("bulk_fetch expects list or Documents")

        import threading
        lock = threading.Lock()

        def fetch_document():
            document = True
            while document:
                with lock:
                    if len(docs_to_process) > 0:
                        document = docs_to_process.pop()
                    else:
                        document = None
                if document:
                    document._get_data()

        threads = [threading.Thread(target=fetch_document) for x in range(num_threads)]

        for thread in threads:
            thread.start()

        for thread in threads:
            thread.join()


class Collections:
    """
    A container object for all the collections on the server.

    This is a list/dict like class that represents the collections on the server.
    This isn't just a dict in order to lazy load the collections from the
    server.  If not, then when connecting to torque would then make N
    queries, one for each collection.

    It can be indexed like a dict, but also iterated over like a list.  So the
    following work:

        Torque(...).collections["XYZ"]

    and

        for collection in Torque(...).collections:
           ...

    Attributes
    ----------
    torque : Torque
        The parent torque object
    collection_data : dict
        The in memory cache of the actual collections after loading
    names : list
        The names of the available collections
    """
    def __init__(self, torque):
        """Initializes a lazy loaded list of collections

        Additionally fetches the list of available collections."""
        self.torque = torque
        self.collection_data = {}
        self.names = self.torque._get_data("/collections")

    def keys(self):
        """Returns the available collection names."""
        return self.names

    def __iter__(self):
        self.idx = 0
        return self

    def __next__(self):
        if self.idx < len(self.names):
            collection_name = self.names[self.idx]
            self.idx += 1
            return self[collection_name]
        else:
            raise StopIteration()

    def __getitem__(self, collection_name):
        """Returns a Collection object represented by COLLECTION_NAME.

        If this doesn't exist yet in memory, lazily instantiate it, which will
        involve server calls to populating it."""
        if collection_name not in self.collection_data:
            self.collection_data[collection_name] = Collection(self.torque, collection_name)

        return self.collection_data[collection_name]


class Collection:
    """A Collection of documents.

    Not only the interface to get various documents, but also handles
    the cache invalidation of those objects, as that information exists
    at the collection leve on the server.

    A note that this will only hold the documents that the logged in user
    has acccess to.

    Attributes
    ----------
    torque : Torque
        the parent torque object
    name : str
        the name of this collection
    documents : Documents
        the documents in this collection
    last_updated : Timestamp
        the time this collection last an an update on the server,
        as from an edit or addition.
    fields : List
        all the fields that the user has access to on the server

    Like Torque above, an alias is created if there's a DOCUMENTS_ALIAS
    on the server.  For instance, if "proposals" is set to DOCUMENTS_ALIAS,
    then there will be an attribute as follows:

    proposals : Documents
        the proposals in the competition

    Methods
    -------
    search(search_term)
        search for documents in this collection
    """

    def __init__(self, torque, name):
        self.torque = torque
        self.name = name

        self.documents = Documents(self.torque, self)
        if torque.documents_alias:
            setattr(self, torque.documents_alias, self.documents)

        self._refresh_from_server()

    def search(self, search_term):
        """Return Documents mathing SEARCH_TERM in this collection."""
        return self.torque.search(search_term, self.name)

    def _refresh_from_server(self):
        """Internal method to update the last_updated, which is used
        for cache invalidation for documents."""
        collection_information = self.torque._get_data("/collections/%s" % self.name)
        self.fields = collection_information["fields"]
        self.last_updated = dateutil.parser.isoparse(collection_information["last_updated"])
        self.refreshed_at = datetime.now()

    def _evaluate_cache(self):
        """Refresh data from the server if it's been too long since last looked,
        depending on the information from the Torque Cache"""
        if self.torque.cache is not None and (datetime.now() - self.refreshed_at).seconds > self.torque.cache.cache_timeout():
            self._refresh_from_server()


class Documents:
    """
    A container object for all the documents in given Collection.

    This is a list/dict like class that represents the documents on the server.
    This isn't just a dict in order to lazy load the documents from the
    server.

    It can be indexed like a dict, but also iterated over like a list.  So the
    following work:

        Torque(...).collections["XYZ"].documents["123"]

    and

        for collection in Torque(...).collections:
            for document in collection.documents:
                ...

    It does not store the documents in memory (unlike Collections) above, because
    we want to respect whatever caching strategy the user is using.  That means
    that the following will fetch from the server twice:

        Torque(...).collections["XYZ"].documents["123"]
        Torque(...).collections["XYZ"].documents["123"]

    Also, the list of keys is not retrieved from the server until used for iteration.
    When using access, torqueclient assumes you know what you're doing.

    Attributes
    ----------
    torque : Torque
        The parent torque object
    collection: Collection
        The parent Collection object
    keys : list
        The keys for the available documents
    """
    def __init__(self, torque, collection):
        self.torque = torque
        self.collection = collection
        self.keys = None

    def __iter__(self):
        """Fetches the keys on the first use"""
        if self.keys is None:
            self.keys = self.torque._get_data("/collections/%s/documents" % self.collection.name)
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
    """A Document

    A lazy loaded instance of a document on the torque server.  This does
    not load any data from the server until accessed (via keys(), or __getitem__
    methods)

    A note that this will only hold the fields that the logged in user
    has acccess to.

    Attributes
    ----------
    torque : Torque
        the parent torque object
    collection : Torque
        the parent collection object
    key : str
        the identifying key from the server
    data : dict
        initially set to None (until lazy loaded), a dictionary of names to values

    Methods
    -------
    keys()
        all the field keys
    uri()
        the uri of the document, which is a useful index when creating a cache
    """
    def __init__(self, torque, collection, key):
        self.torque = torque
        self.collection = collection
        self.key = key
        self.data = None

    def __getitem__(self, field):
        return self._get_data()[field]

    def __setitem__(self, field, new_value):
        """Sets the field value not only in memory, but also pushes the change
        to the server."""
        self._get_data()
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
        """Returns the uri of the doucment on the server."""
        return "/collections/%s/documents/%s" % (self.collection.name, self.key)

    def keys(self):
        """Returns the list of all the field keys available on the server."""
        return self._get_data().keys()

    def _get_data(self):
        """Gets the data for the document from the server.

        There's logic here as well that will refresh data if new, as well
        as pull from cache as appropriate."""
        if self.data is None:
            if self.torque.cache is not None:
                self.collection._evaluate_cache()
                if self.torque.cache.contains_document_data(self, self.collection.last_updated):
                    self.data = self.torque.cache.retrieve_document_data(self)

            if self.data is None:
                self.data = self.torque._get_data(self.uri())

            if self.torque.cache is not None and not self.torque.cache.contains_document_data(self, self.collection.last_updated):
                self.torque.cache.persist_document(self, self.collection.last_updated)

        return self.data
