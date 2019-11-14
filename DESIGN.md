# Torque Design

## Overview

Torque allows different groups of people to comment on proposals, and
to indicate favored proposals in some specific way.

The initial system is designed around the needs of grant-making
foundations that are running contests similar to
the [100&Change](https://www.macfound.org/programs/100change/)
competition run by the [MacArthur Foundation](https://macfound.org/)
and [Lever for Change](https://www.leverforchange.org/).  Those latter
two organizations and their partners are supplying the first use
cases, and these requirements are designed with them in mind.
However, we are also trying to keep the system generalizable to other
proposal evaluation processes, and are interested in suggestions or
contributions in that direction.

## Shape of the data.

The fundamental content objects are pages and comments.  Each wiki
page represents one proposal, and every comment is associated with a
particular page (even perhaps with specific text on that page).

We assume the wiki pages have been generated automatically from some
outside source
(e.g., [csv2wiki](https://github.com/opentechstrategies/csv2wiki)
operating on a spreadsheet of proposals, one row per proposal), and
thus are not to be edited by users.  We may want to enforce this
no-editing technically.  (There is also a Table of Contents page and
maybe some category pages -- these pages can be considered derived
data from the proposal pages.)

Users interact with the data is by making per-page comments, and in
some cases by marking a page via some kind of selector or scoring
interface (e.g., checking a box to indicate "I like this proposal" or
something like that).  Each comment has its own thread.

The system does not use wiki "Talk" pages for commenting, because the
UX isn't quite right.  Instead, we'll use a system explicitly designed
for commenting; see our
[evaluations of various comments plugins](https://github.com/OpenTechStrategies/torque/wiki/Comments-Evaluation).

## Users and their capabilities.

Every user of the system is authenticated; there is no anonymous
access.  Users are:

* Board members
* Expert reviewers brought in by Board members
* Foundation staff members

When a Board member makes a comment on a proposal, all other Board
members can see it, but no one else (not even the expert reviewers
invited in by that Board member).  At some future point, we might want
to have features by which a Board member can pass questions along to
other reviewers, but for now they can do that by out-of-band means
(email, whatever), 

When an expert reviewer makes a comment on a proposal, all Board
members can see it, and that particular expert reviewer themselves can
see it (but other expert reviewers cannot), and all Foundation staff
can see it.

## Authentication, Authorization, and Okta

Setting up permissions for those who have logged in via Okta could
happen like this:

1. Individual (or group) is enabled for the Torque app in the 
   Okta applications section.
2. Someone uses Okta to log in for the account in question.
3. SAML plugin generates a user in MediaWiki matching that login.
4. LfC staff member logs in as admin to MediaWiki, assigning that
   person to a group (e.g., Board Member) in the user admin screen.
5. Further logins by that person will show up with Board Member
   preferences.

If we are able to assign users to a group in Okta correctly, and that
information travels to Torque correctly, this becomes even easier:

1. Individual (or group) is enabled in Torque app in Okta
   applications section, with appropriate group set.
2. Logins by that person will show up with Board member preferences.

We don't yet (as of 2019-11-04) know which scenario we'll have;
ideally the second one, but we could work with the first if needed.

## Automated deployment and content management

Torque is deployed using [Ansible](https://www.ansible.com/), and some
of what gets deployed is simply regular wiki content that is
considered part of the Torque system (see
[here](ansible/roles/mediawiki/files) for example).

But because Torque is a wiki, there may be times when users edit those
Torque-originated pages.  Now we have a problem: the next redeployment
would overwrite the users' changes.  (The changes could be retrieved
from the wiki's version control history, of course, but that's
cumbersome -- someone has to know to go to look for them.)

Ansible can detect whether the thing it's replacing is different from
what it's trying to upload, but it has no way to know whether the
reason for the difference is that there's a new version of that thing
in Torque, or that a user edited the thing at the destination, or
both.  A solution to this would be for Ansible to have a list of
digital fingerprints of all past versions of each thing it uploads, so
it can check what's currently at the destination against the list.  If
what's there is on the list, then the destination can safely be
replaced; otherwise, a user must have edited the destination, so the
deployment process should issue a warning and not touch the
destination.

We haven't implemented that solution yet.  For now, those installing
Torque should deal with such content conflicts manually.

## Generating PDF books from sets of articles

Currently, book creation in torque is done through a now-obsolete
system that is what Wikipedia itself formerly used: the
[Collection](ansible/thirdparty/extensions/Collection-REL1_33-8566dd1.tar.gz)
extension.  It depends on (among other things) mwlib, which uses
Python 2.x not 3.x.  Wikipedia itself has temporarily disabled its
Book Creator, which formerly used this extension, and posted a
[timeline](https://www.mediawiki.org/wiki/Reading/Web/PDF_Functionality)
about the disablement and the hoped-for future restoration of book
creation.

This [History
section](https://en.wikipedia.org/wiki/Wikipedia:Books#History) gives
a nice overview of the current state of the onion.  Basically,
single-page PDF generation in mediawiki took a detour through the
now-also-obsolete [Electron](https://www.mediawiki.org/wiki/Electron)
before settling on [Proton](https://www.mediawiki.org/wiki/Proton),
which is now handling single-article PDFs on Wikipedia.org.  However,
as yet there's no code hooking Proton into some kind of article
collection system so that one can generate a book consisting of
multiple articles.  The [Talk page for
Proton](https://www.mediawiki.org/wiki/Talk:Proton) gives more
information, also referring to the German company PediaPress's efforts
to make a new book service called "Collector".  According the History
page that effort is closed source, and according to the Talk page the
effort is running behind schedule, though apparently they have a test
service up at https://pediapress.com/collector.

User [Steelpillow](https://en.wikipedia.org/wiki/User:Steelpillow),
who seems to know a lot about this topic, suggests the [Talk page for
Reading/Web/PDF_Functionality](https://www.mediawiki.org/wiki/Talk:Reading/Web/PDF_Functionality)
as a source of more information.

Meanwhile, there is an independent thing happening at
http://mediawiki2latex.wmflabs.org/.  It converts wiki pages to LaTeX
and PDF, and works with any website running MediaWiki, especially
Wikipedia and Wikibooks.  It's FOSS and written in Haskell, but WMF
doesn't support Haskell, so this is unlikely to become an official
Wikipedia solution although it might be interesting for torque's
purposes.

## Reference

These requirements are
[discussed in more detail](https://chat.opentechstrategies.com/#narrow/stream/45-Lever-for.20Change/topic/hello/near/69877) in
our chat room.
