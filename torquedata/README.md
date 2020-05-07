# The torquedata app

This is the Python server resposible for housing data, rendering, and searching.

Outside of starting it up, this should remain a black box.  The reason being
that none of the routes or uses for this should be accessed except
through the [TorqueDataConnect MediaWiki plugin](../TorqueDataConnect/).

For developers, look in the individual code files for details on the inner
workings.

# Installation and Startup

Installation is handled by using Pipenv:

```
$ pipenv install
```

## Configuration

Copy over the config file and then update the variables.  See the template
for more information.

```
$ cp config.py.tmpl config.py
$ $EDITOR config.py
```

## Starting the server

The easiest way to start the server in development mode is via Pipenv

```
$ export FLASK_ENV=development  # For reloading on code changes
$ export FLASK_APP=torquedata
$ pipenv run flask run
```

### Using supervisor

```
# As superuser, on debian at least
$ apt-get install supervisor
$ cat > /etc/supervisor/conf.d/torquedata.conf << EOF
[program:torquedata]
directory=/path/to/torquedata/server
user:DEPLOYMENT_USER
command=pipenv run flask run
autostart=true
autorestart=false
redirect_stderr=true
redirect_stdout=true
environment=FLASK_APP=torquedata
EOF
```

Then restart it

```
# As superuser
$ service supervisor restart
```
