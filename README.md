# Torque

A Python web server and MediaWiki extension that work
to turn a MediaWiki instance into a CMS.

The basic workflow is that, after installation, you can upload a json document
to the torque system through MediaWiki, and then tailor the output
of that system based on what MediaWiki group a user is part of.

This document provides a brief overview, but see
[the design documentation](DESIGN.md) for more about the features.  You can also
join the
[chat channel](https://chat.opentechstrategies.com/#narrow/stream/45-Lever-for.20Change)
to talk with the development team, or reach us by filing a ticket in the
[issue tracker](https://github.com/opentechstrategies/torque/issues).

Examples of a torque-compatible proposal input pipeline for different
use cases, with ansible scripts relating to setting up a function system
can be seen in the
[torque-sites](https://github.com/opentechstrategies/torque-sites) repository.

[Example Setup](./EXAMPLE.md)

# django-torque

django-torque is a django server that's responsible for storing collection data (in json)
and then serving it out as needed.  As a rule, it's very accepting and should
not be exposed to the greater internet.  All of the authentication and authorization
is done via the MediaWiki plugin.

Data, indices, and configuration are stored in the filesystem.

See [torque README](django-torque/README.md) for more in depth information.

# extension

Torque is the MediaWiki plugin that accesses the torque server.
It uses hooks to ask torque to render pages formatted for MediaWiki, and
provides JSON versions of the data through MediaWiki's api.

See the [extension README](extension/README.md) for more in depth information.

# Releasing

Look at the [Releasing documentation](RELEASING.md) for notes about versioning
and releasing torque.
