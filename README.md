# torque

A flexible web-based open source system for collaboratively evaluating proposals.

Please see [the design documentation](DESIGN.md) for more about
Torque's features.  You can join the
[chat channel](https://chat.opentechstrategies.com/#narrow/stream/45-Lever-for.20Change)
to talk with the development team in real time, and you can also reach
us by filing a ticket in the
[issue tracker](https://github.com/opentechstrategies/torque/issues).

See the
[development wiki](https://github.com/opentechstrategies/torque/wiki)
for evaluation notes on various plugins we're considering using.

See the [ansible install guide](ansible/INSTALL.md) for installation
instructions.

An example of a torque-compatible proposal input pipeline for one
particular use case can be seen in the
[MacFound](https://github.com/opentechstrategies/MacFound) repository.

# Extensions

## Wildcard

Look in [the wildcard extension](extensions/Wildcard/) for
information.

## TeamComments

This extension was forked from
[the mediawiki Comments extension](https://www.mediawiki.org/wiki/Extension:Comments)
and then updated to have uses more specific to the needs of torque.  Those include,
but aren't limited to:

* UI Updates
* Global on/off switch
* Viewing authorization
* Removal of scoring, profile picture, voting, ignoring
* Adding more nesting

## Favorites

This extension was forked from
[the main mediawiki extension](https://www.mediawiki.org/wiki/Extension:Favorites)
and then updated to have uses more specific to the needs of torque.  Those include,
but aren't limited to:

* Changing some looking and feel
* Removing a lot of the uneeded functionality, especially in the Special pages
* Updating how the extension is loaded
* Removing some of the configuration because it's unneeded
* Removing the parser tag
