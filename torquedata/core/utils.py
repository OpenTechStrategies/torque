class Filter:
    """A search filter"""

    def name(self):
        """Returns the name of the filter.  This isn't the display name,
        but rather the name in the database.  For ease, it makes the most
        sense to make it like a python variable, with lower case and underscores."""
        pass

    def document_value(self, document):
        """Returns a value for a given document.  Most of the time, this will
        just be a Value within the document, but sometimes there is extra processing
        in order to group documents for filtering."""
        pass
