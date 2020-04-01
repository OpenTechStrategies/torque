# torque

A python web server and MediaWiki extension that, when combined, work
to turn a MediaWiki instance into a CMS.

The basic workflow is that, after installation, you can upload a csv
to the torque system through mediawiki, and then tailor the output
of that system based on what mediawiki group a user is part of.

This document provides a brief overview, but see
[the design documentation](DESIGN.md) for more about the features.  You can also
join the
[chat channel](https://chat.opentechstrategies.com/#narrow/stream/45-Lever-for.20Change)
to talk with the development team in real time, and you can also reach
us by filing a ticket in the
[issue tracker](https://github.com/opentechstrategies/torque/issues).

Examples of a torque-compatible proposal input pipeline for different
use cases, with ansible scripts relating to setting up a function system
can be seen in the
[torque-sites](https://github.com/opentechstrategies/torque-sites) repository.

# torquedata

torquedata is a flask server that's responsible for storigin up spreadsheet data
and then serving it out as needed.  As a rule, it's very accepting, and should
not be exposed to the greater internet.  All of the authentication and authorization
are done via the mediawiki plugin.

It stores the data on the file system, with indices and configuration also being
stored there.

See [torquedata README](torquedata/README.md) for more in depth information.

# TorqueDataConnect

TorqueDataConnect is the mediawiki plugin that accesses the torquedata server.
After being installed and configured, it uses hooks to ask torquedata
to render pages formatted for mediawiki, as well as providing json versions
of the data through MediaWiki's api.

See the [TorqueDataConnect README](TorqueDataConnect/README.md) for more in depth information.
