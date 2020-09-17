# Torque

Torque is a two-part system that gives MediaWiki lightweight CMS capabilities.
It was originally designed to meet the needs of philanthropic competitions.
While MediaWiki is well-suited for collaboration and communication, it does not
offer any kind of restrictions on who can view confidential or sensitive data.
Torque adds this functionality.

The two systems that make up Torque are:

1. torquedata, a Flask app that houses and renders the data
2. TorqueDataConnect, a MediaWiki extension that integrates the Torque
   responses into wiki pages and controls access to said pages.

The decision to split the server housing the data from MediaWiki was made
for several reasons:

* Ease of development.  MediaWiki is an excellent platform for developing wikis,
  but Python and Flask fit Torque's backend needs far better.
* Allowing multiple wikis to access one data set. For instance, having a public
    wiki and a private wiki with different permission sets.
* Allowing the data to reside on a different server than the wikis themselves.

## Types of Users

Torque is built around having four classes of users:

* Normal Wiki Users: These users use the wiki like anyone else.  Ideally,
  unless they are editing pages, they don't have any idea Torque is running
  underneath.  They belong to a user group that's defined in Torque to
  customize the information they have access to.

* API Users: These users access the Torque information through the
  MediaWiki API.  They have the same permissions as the normal users,
  but the information usually comes in the JSON format for programmatic ease.

* Torque Admin Wiki Users: These users are responsible for the dynamic
  configuration of the Torque system.  They can edit the Torque config Page,
  update the templates defined in MediaWiki, adjust column and object
  permissions.

  These users belong to the `torquedataconnect-admin` and can also
  upload new spreadsheets, and overwrite spreadsheets.

* System Administrators: These users are responsible for setting up the
  MediaWiki instance, installing Torque, and setting the variables
  necessary for a correctly running system.  In an ideal Torque system,
  after initial setup, there is no more work for system administrators.

## Permissions Structure

Torque's permissions are defined dynamically in MediaWiki.
The page linked to by the
[`$wgTorqueDataConnectConfigPage`](TorqueDataConnect/README.md#Parameters)
variable is read, and the groups listed in the `Permissions` section are
matched with the user groups of the logged in MediaWiki user.  The first
group in the `Permissions` table that matches a MediaWiki group the
user is assigned to gets sent to torquedata for validation and rendering.

Then, torquedata will redact the list of objects available to that user,
and the columns available to the template for rendering, based on the
permissions set up in that table.  See
[the configuration page](TorqueDataConnect/README.md#WikiPage configuration)
for details about the format of that page.

The columns and proposals linked are also used to generate search indices
in torquedata for search results that are correct for the users permissions.

## torquedata Flask app

`torquedata` exists to use a backing store, initially
CSV files stored on the hard drive, and provide different outputs necessary for
the project.

Because it's largely not user facing, the [README](torquedata/README.md)
is lightweight and concerned mostly system adminstrator information
for installation and configuration of the app.  How it exposes information to
MediaWiki is left undocumented and may be changed at any time.

It provides the following features:

### Input from CSV files

CSV files are uploaded through the
[TorqueDataConnect extension](TorqueDataConnect/README.md#torquedataconnectuploadsheet).
The files must start with two rows, one being a header row, and the following
being a column type row.

Entries in the header row should be unique. They will become indices in the
dictionary passed to templates for display. The column type row declares what
type will be found in the column, allowing Torque to assign it that type for use
in the templates.

NOTE: There's no data sanitization.  The uploader needs to take
care of that before using Torque.  XSS attacks, badly formatted sheets,
and whatnot, will just get blindly served up if passed to Torque.

For right now, the available types are:

* `list` - a new line separated list of values.  This gets turned
  into a list in memory for the template to iterate over
* `json` - a json document embedded in the csv, that will then
  get turned into a python dict

One of the columns must be specified as a key column.  The keys
in the document should also all be unique to prevent collisions.
This key is the way that all of the data is queried from the various
hooks and permissions checks.

When uploading, the last thing needed is a name for the sheet.  This
needs to be a simple name that can work as a variable, so it can't
start with a number.  The reason is that in table of content templates,
the place Torque puts what objects the user is allowed to view is in
the variable `<sheet_name>`.

### Wiki Markup from CSV rows

Templates are configured as part of the
[TorqueDataConnect extension](TorqueDataConnect/README.md#WikiPage_configuration)

These templates, which are stored on the wiki, are Jinja templates.  When a request
is made to `api/<sheet_name>/id/<id>.mwiki`, the desired template can be specified
(or a the default is used), and Torque does the following:

* It ascertains whether the user has the permissions to access that `id`
* It then culls the object referenced by that `id` to just ht ecolumns
  the user has access too
* It feeds that redacted object to the template under the name specified
  at sheet upload time as the `object_name`

If it fails for any of these reasons, it errors out with a 403 HTTP code.

These are the only assumptions made by Torque. It doesn't know
whether it's returning wiki markup, HTML, or something else.  Conventionally,
the `mwiki` type on the API call indicates that the template should put
out wiki markup, however there's nothing forcing it to.  The power of
declaring how things look is completely up to the users of the wiki with
permissions to edit the templates.

### TOC pages

Tables of Contents are dynamically generated lists of objects in the sheet.
In order to provide flexibility there are three parts to the table of contents.

Part 1 is the template itself.  This template will be provided, as data, an
object that is made of two sections.

Part 2 is the first section of the aforementioned object.  This is a JSON document
that's converted to a dictionary and provided to the template as the base.  That
means that if you provide the JSON document:

```
{
  grouped_objects: [
    {
       group_name: "Name1",
       object_ids: [1, 2, 3]
    },
    {
       group_name: "Name2",
       object_ids: [4, 5, 6]
    },
    ...
  ],
  description: "A generic description here"
}
```
you would refer to the object you've been given in your template by the names
`grouped_objects` and `description` in the Jinja template.

Part 3 is an object that's added to the data passed to the template, which
will always be in the name `<sheet_name>` for whatever sheet this Table of
Contents belongs to.  That object will be an dictionary mapping `key` of
the object (based on the `key_column` when configured) to object itself for
the template to use. The result of passing the object through the TOC Template
defined by the MediaWiki instance is stored in the `toc_lines` variable, indexed
by the same key.  This allows users of the wiki to declare how the table of
contents items look and feel, and what information is displayed.

### JSON output from CSV rows for API

Torque also allows programmatic access of the data, throught the `torquedataconnect`
API call.  Any user with access can call into MediaWiki's API, using HTTP
or a supporting library, and ask for a path.  The response is a JSON document
with a list of objects, each having a mapping of the header to cell data for
a given row.  This is redacted based on what that user has access to.

This allows for outside software to use the document store Torque uses.  Of course,
outside sources can use the rest of MediaWiki's API to just render the wiki pages,
but this provides a more software friendly source of data.

### Attachments

One large issue with MediaWiki is that there's no way to strongly associate
attachments (in this case, PDFs) with pages, and then have authorization
fall through to those.  Indeed, the default setting is that attachments
are just handled by the filesystem and webserver.  `torquedata` handles
those attachments, and all the authorizations therein through a SpecialPage.

When uploading, one of the arguments to the 
[`torquedataconnectuploadattachment`](TorqueDataConnect/README.md#torquedataconnectuploadattachment)
API call is the `permissions_column`.  The user must have access to that
column, and access to the proposal, for the file to be returned.  If so,
then the user can download the file.

See [the special page](TorqueDataConnect/README.md#Special page for attachments: Special:TorqueDataConnectAttachment)
for details on how to generate the page.

### Search results

MediaWiki cannot cull search results based on authorization
because it's not a CMS. Instead, that's handled by `torquedata`.  The search
results come back filtered through the template set up for Search.

The search uses [whoosh](https://whoosh.readthedocs.io/en/latest/index.html)
to build indices for every group of users with unique permissions, so
the results are tailored to the logged in user.

## TorqueDataConnect MediaWiki Extension

TorqueDataConnect controls the user facing
aspects of Torque.  You can look at the [README](TorqueDataConnect/README.md)
for the reference of how to configure and use the system.

### Torque Configuration

The MediaWiki configuration comes in two parts.  The first is in `LocalSettings.php`,
which sets up the page.  Those are defined by the
[parameters](TorqueDataConnect/README.md#parameters).  The ones that need to be
set for a correctly running system are:

* `$wgTorqueDataConnectConfigPage`
* `$wgTorqueDataConnectSheetName`
* `$wgTorqueDataConnectWikiKey`

The others have values to default to, or are assigned by TorqueDataConnect based on
your user.  See below for why you might want to override those.

### Configuration Page

The configuration page linked to by `$wgTorqueDataConnectConfigPage` is
set up to link groups with permissions, and defined templates.  See the
[above section](#Permissions Structure) for more information.  Every
Torque instance has to have a built out and configured config Page
in order to work correctly.

When not set up correctly, the plugin will let you know that there's
an error if you're in the `torquedataconnect-admin` user group.

### Template Page

There are three different types of templates: View, TOC, and Search.

For each type, the template listed first in the configuration becomes
the default template for that type.

#### View

View templates define how objects are rendered in the wiki.
If there are multiple view templates, than a left sidebar item is created
that allows the user to select which view they want.

The selected view template is called with the object being rendered
set to the name defined when
[uploading the sheet](TorqueDataConnect/README.md#torquedataconnectuploadsheet).
That object is a dictionary with the column headers of the spreadsheet
being indices to get the cell data for that row.  This is an
instance where demonstration is more informative than information, so see the
[example](example/INSTALL.md) for a concrete example.

The variable `$wgTorqueDataConnectView` is set and passed along to torquedata
based on the user selection.

#### Search

Search templates define how search results are rendered.  Similar
to the view templates, the `object_name` is used to declare the variable
name available in Jinja for rendering.  Because there is
only one template for search results, it needs to access only fields that
are available for everyone to see, or have conditionals to prevent an
error.  The template should be relatively short in order for the
search results page to show up.

The reason this is templated, rather than just displaying the best
matching sections is that the sections that match may not be displayed
in view templates (even though the user has access to them).

#### TOC

TOC templates define how objects are provided to the template associated
with that table of contents.  It's usually one line, but could be a short
paragraph.

Since the table of contents already has a template, this feature is
somewhat redundant.  However, the uploaded template is usually set at
setup time, and Torque wants to give flexibility to users of the system
to adjust the look and feel without requiring more uploads.

### tdcrender Hook

The `#tdcrender` hook is the main way that pages ask torquedata to render 
objects for them.  These can be inserted at any place on any page, and
the resulting text will be inserted to that location.

The hook also allows for the caller to specify which view template to use,
within the templates available for that render type (toc versus normal).

#### Single objects

For single objects, reference the object by its id.  The path used is

`<sheet_name>/id/<id>.mwiki`

This will render the single object through the chosen view.  If there's
an error, like if the template doesn't work with the redacted column set,
or the user doesn't have access to that object, then a generic error
message will be returned in order to obfuscate the reason for the response.

#### Tables of contents

For tables of contents, reference the TOC by its name.  The path used is

`<sheet_name>/toc/<toc_name>.mwiki`

See [above](#TOC pages) for how this is rendered.

### Attachments Special Page

Attachments are another area that MediaWiki decides to not protect.
They are usually kept as a public directory and served
directly from the webserver without going through MediaWiki at all.  This
has a number of benefits, such as caching, allowing deep linking, sharing
of resources, and just easier software development. However, it lacks any kind
of restrictions, as even a user not yet logged in can view attachments with the
correct URL. 

The way Torque solves this is by creating a
[special page](TorqueDataConnect/README.md#Special page for attachments: Special:TorqueDataConnectAttachment).
When uploading, the column and proposal are linked to an attachment, and
then when accessing that file, those are checked against the Torque
permissions the user has.

### Uploading data

Torque allows torqueadmin's to upload three kinds of files:

* [The full data sheet](TorqueDataConnect/README.md#torquedataconnectuploadsheet)
* [A table of contents](TorqueDataConnect/README.md#torquedataconnectuploadtoc)
* [An Attachment](TorqueDataConnect/README.md#torquedataconnectuploadattachment)

These are done through the MediaWiki API (most likely through a bot account).

See the [example](example/INSTALL.md) for a better demonstration of how one might
upload these files.

### API usage

Normal wiki users can access MediaWiki programmatically through
the API.  Torque adds an API that has the same permissions as the `tdcrender`
hook, but instead of returning a rendered wiki page, returns a JSON
representation of the authorized data.  This allows Torque systems to be
the central data repository for projects, instead of just a consumer.

### Conditional override of LocalSettings Parameters

Some of the [parameters](TorqueDataConnect/README) used by TorqueDataConnect
are set by the extension itself based on the user logged in and the
dynamic configuration of the system.

* `$wgTorqueDataConnectView`
* `$wgTorqueDataConnectGroup`

However, it may be useful to override them.  When overridden, Torque
uses the set value rather than assigning one to it.  Some use cases
include:

* Hard-coding the view for certain users regardless of what they select
* Being able to view the the data through the lens of a different group
* Hard-coding view/group based on dns entry point, for instance, for
  public facing views of the wiki.

In that case, just set them in LocalSettings, or via other hooks
and extensions.

### Search Results

Currently, the search results from Torque completely take over the
results page for MediaWiki, and supplant all normal search results.
This is because MediaWiki does not provide ranking information
with its results, so each search engine must have a complete picture
of the data in order to correctly rank search results against each other.

In the future, torquedata will gain a better understanding of the wiki to return
better interleaved results. 
