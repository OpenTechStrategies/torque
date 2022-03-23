from jinja2 import Environment
from django.conf import settings

# This is hardcoded here, while we figure out a better path forward
#
# It's used to handshake with the python client to ensure that everything
# works correctly together.
SERVER_VERSION = "0.2"


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

    def document_value(self, document_dict):
        """Returns a value for a given document dictionary.  Most of the time, this will
        just be a value within the document, but sometimes there is extra processing
        in order to group documents for filtering.

        The dictionary provided will be permissioned for the specific user group attached
        to the cache."""
        pass

    def sort(self, names):
        """Sorts the names, which are keys for the filter.  Defaults to alphabetical."""
        names.sort()
        return names

    def ignored_values(self):
        """Returns a list of ignored values, which could be set if someone doesn't
        have permissions, or just in general if they want to be ignored."""
        return []


class CsvFieldProcessor:
    def field_names(self, field_name):
        pass

    def process_value(self, value):
        pass


## A factory method for getting a jinja environment
def get_jinja_env():
    enabled_extensions = []
    if settings.TORQUE_ENABLED_JINJA_EXTENSIONS:
        enabled_extensions = enabled_extensions + settings.TORQUE_ENABLED_JINJA_EXTENSIONS

    return Environment(
        extensions=enabled_extensions,
    )
