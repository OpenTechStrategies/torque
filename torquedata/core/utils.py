from jinja2 import Environment
import config


class Filter:
    """A search filter"""

    def name(self):
        """Returns the name of the filter.  This isn't the display name,
        but rather the name in the database.  For ease, it makes the most
        sense to make it like a python variable, with lower case and underscores."""
        pass

    def display_name(self):
        """The name to display on the wiki.  Defaults to just the name."""
        return self.name()

    def document_value(self, document):
        """Returns a value for a given document.  Most of the time, this will
        just be a Value within the document, but sometimes there is extra processing
        in order to group documents for filtering."""
        pass

    def sort(self, names):
        """Sorts the names, which are keys for the filter.  Defaults to alphabetical."""
        names.sort()
        return names


## A factory method for getting a jinja environment
def get_jinja_env():
    enabled_extensions = []
    if config.ENABLED_JINJA_EXTENSIONS:
        enabled_extensions = enabled_extensions + config.ENABLED_JINJA_EXTENSIONS

    return Environment(
        extensions=enabled_extensions,
    )
