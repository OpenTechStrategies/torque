# The torque app

This is the django app that should be deployed in a running django server.

Outside of installing the app, this should remain a black box.  The reason being
that none of the routes or uses for this should be accessed except
through the [Torque MediaWiki plugin](../extension/).

For developers, look in the individual code files for details on the inner
workings.

# Installation and Startup

Install via pip

```
$ pip install django-torque
```

## Installation Configuration

Update your settings.py to include:

```
INSTALLED_APPS = [
    ...
    "torque",
    "torque.cache_rebuilder",
    ...
]

# and the urls
ROOT_URLCONF = "torque.urls"
```

For the urls, you can also do add them by the following:

```
urlpatterns = [
    ...
    path('torque/', include('torque.urls')),
    ...
]
```

If you do that, you need to make sure that your mediawiki extension is
configured to the correct subpath (`localhost:5000/torque/` or however)

Then run the migrations:

```
$ python manage.py migrate
```

## App configuration

### TORQUE_ENABLED_JINJA_EXTENSIONS

A list of jinja2 extensions to enable when rendering templates
See an extension list: https://jinja.palletsprojects.com/en/2.11.x/extensions/
For example, to enable the "jinja2.ext.do" extension you would set this to
```
TORQUE_ENABLED_JINJA_EXTENSIONS=['jinja2.ext.do']
```

### TORQUE_FILTERS

All the search filters for cached search items.  These must implement
utils.Filter, and that class has more documentation on what must be done.

In short, the filters must provide a name, translate the documents into
in values that can be filtered upon.

```
from torque import utils
class ExampleFilter(utils.Filter):
    def name(self):
        return "example"

    def display_name(self):
        return "Example"

    def document_value(self, document):
        # Filter on the first character of the key
        return document.key[0]

FILTERS=[
    ExampleFilter()
]
```

### TORQUE_CSV_PROCESS

When generating a csv, this dictionary will match against fields named in the keys,
and the document will be processed through the value, which should be an instance of
`utils.CsvFieldProcessor`

# Running from this repository (in development)

Install a pipenv environment:

```pipenv install```

Set up your configuration via

```
$ cp config.py.tmpl config.py
$ $EDITOR config.py
```

Then run the migrations:

```
$ python manage.py migrate
```

Then start it up via normal django commands

```
$ pipenv run python manage.py runserver 5000
```
