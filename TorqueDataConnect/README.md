# Extension:TorqueDataConnect

The TorqueDataConnect extension connects to a running instance of [torquedata](../torquedata)
to serve up information.  In order for it to work, you first need that service running.

This document serves as a reference for someone who already has a good understanding
of how Torque works.  For more detailed information, see [the design](../DESIGN.md) and
[the example setup](../EXAMPLE.md).

## Installation

* NOTE: Make sure your system has PHP 7.3+
* Download and place the file(s) in a directory called TorqueDataConnect in your extensions/ folder
* Add the following line to your LocalSettings.php
```
wfLoadExtension('TorqueDataConnect');
```
* Configure as required

## Configuration

### Parameters

* `$wgTorqueDataConnectConfigPage` - The central [configuration page](#WikiPage configuration).
  It's a good idea to make this in a restricted access namespace of the wiki.
* `$wgTorqueDataConnectServerLocation` - the location of the torquedata server, in order to make
  queries.  Defaults to "http://localhost:5000".
* `$wgTorqueDataConnectGroup` - The group representing this user that's sent to the torquedata.
  server.  This defaults to the first group that is in the list of known Torque groups that
  is also a group the current user is in.  Overriding it can be useful to view data
  as another user, or for non logged in users.
* `$wgTorqueDataConnectView` - The current view used for rendering.  This is usually the
  one selected on the left menu, as a selection between configured groups.
* `$wgTorqueDataConnectSheetName` - The uploaded sheet this wiki is configured to use.
  This affects what sheet search will run against, as well as what sheet `$wgTorqueDataConnectConfigPage`
  will be configured by.
* `$wgTorqueDataConnectWikiKey` - The key identifying this wiki.  This is needed in case
  multiple wikis are using the same torquedata instance so that their permissions are distinct.
  A useful use case of this is a private and public version of the same data.
* `$wgTorqueDataConnectNotFoundMessage` - An optional message to be displayed if a user doesn't
  have permissions to view an object.  Defaults to "No `<key>` found for `<sheet>`".
* `$wgTorqueDataConnectRaw` - Whether the response coming from torquedata is raw HTML or wiki markup.
  This lets TorqueDataConnect know to stop all MediaWiki processing on the response from torquedata.
* `$wgTorqueDataConnectRenderToHTML` - Whether to render the wiki markup to HTML or not.  If
  TorqueDataConnect does the rendering, then things like inner tags will get parsed.  However,
  some users, especially API users, may want the wiki markup to do things like pdf rendering.

### WikiPage configuration

It's useful to have this page be locked down to some group, such as with this configuration:

```
define("TORQUE_CONFIG", 4000);
define("TORQUE_CONFIG_TALK", 4001);
$wgExtraNamespaces[TORQUE_CONFIG] = "TorqueConfig";
$wgExtraNamespaces[TORQUE_CONFIG_TALK] = "TorqueConfig_talk";
$wgNamespaceProtection[TORQUE_CONFIG] = array("edittorqueconfig");
```

This prevents non-admin people from accessing data they shouldn't be accessing.

After which, the page setup needs to be of the form:

```
= Permissions =

 {
|Group
|[[ColumnConfigPage]]
|[[RowConfigPage]]
|}

= Templates =

{
|Full
|[[TorqueConfig:MwikiTemplate]]
|View
|-
|Search
|[[TorqueConfig:SearchTemplate]]
|Search
|-
|TOC
|[[TorqueConfig:TOCTemplate]]
|TOC
|}
```

There must be a "Permissions" heading and a "Templates" heading that each have a following table
of at least 3 columns.

In the "Permissions" table, the columns are:
* the MediaWiki group
* a link to a page that lists the columns that group can see
  - the page linked to must be of the form
```
* \<ColumnName\>
* \<ColumnName2\>
* \<ColumnName3\>
* \<ColumnName4\>
```
  there, the column names are the spreadsheet header names (the values in the cells on the first row)
* and a link to the page that lists what rows on the spreadsheet they have access to.
  - the page linked must be of the form
```
* \<ID1\>: 
* \<ID2\>: 
* \<ID3\>: 
```
    where everything after the colon is discarded by Torque.  The ID here is related to the key_column
    when uploading the sheet (see [below](#torquedataconnectuploadsheet)).

In the "Templates" table the three columns must be:
* The template Name
* A link to a wiki page which is a Jinja template file
* The template type, which must be 'Search', 'TOC', 'View', or 'Raw View'
  * At this time, Search can really only have one template, but that may change in the future
  * The types are as follows:
    * Search: Search results are passed through this template before displaying on the results page
    * TOC: TOC templates are provided with objects passed through this template in addition to their
      specified JSON data.  Having multiple allows toc writers to choose which template to
      view a given `tdcrender` call with.
    * View: Different views for the page.  Having multiple leads to a dropdown on the sidebar
      allowing the user to choose what way they'd like to show the data.  It also allows the
      wiki page writers to choose the template to view a given `tdcrender` call with.
    * Raw View: The same as View, but instead is raw html.  Use this when the template isn't
      returning wiki text, but rather html that should just be put right out to the screen.
      As a warning, this html is not sanitized, so ensure that the template lives in a protected
      section of the wiki.

## Usage

### API

MediaWiki API calls added;

#### torquedataconnect

Make a call to Torque API.  This makes calls against `torqueserver/api/\<path\>`, so you
can access the data but can't do things like update settings.  It always returns in JSON
format.

Parameters:

* `path` - the path to pass on to Torque 

#### torquedataconnectuploadsheet 

Uploads a sheet to Torque.  For admins only, so whatever bot account you might make
needs to be given the `torquedataconnect-admin` privilege.

NOTE: you may need to increase your `upload_max_filesize` and `post_max_size` values
in your PHP configuration if your spreadsheets are large.

Parameters:

* `object_name` - The name of the object that will be referenced in templates
* `sheet_name` - The name of the sheet for reference by tcdrender and the API
* `key_column` - Which column in the spreadsheet should be used for keying into the data
* `data_file` - A CSV file respresenting the data

#### torquedataconnectuploadtoc

Upload a Table of Contents.  For admins only, as above.

Parameters:

* `toc_name` - The name of the table of contents, for reference in templates
* `sheet_name` - The name of the sheet the TOC is related to
* `json` - the JSON file that should be fed to the template
* `template` - the template file used for rendering

#### torquedataconnectuploadattachment

Upload an attachment.  For admins only, as above.

Parameters:

* `attachment_name` - The name of the file that users will reference
* `permissions_column` - The column that will have the `attachment_name` in to see if a user has permissions to view it
* `object_id` - The associated object in the sheet, to see if the user has permissions to that object
* `sheet_name` - The name of the sheet associated with this attachment
* `attachemnt` - The actual data

### tdcrender hook

TorqueDataConnect provides a single hook for pages to use to ask Torque to render
data for them.  The path will get `api` prepended to it so that users can't use it to
access Torque's control areas.  The view, when provided, will tell torquedata to 
render the page/toc with a specific view that was configured in the MainConfig

```
{{ #tdcrender:<path>|<view> }}
```

The paths available from Torque are:

* `\<sheet_name\>/id/\<id\>.mwiki` - for rendering an object (for example, `proposals/id/1234.mwiki`)
* `\<sheet_name\>/toc/\<TOC_Name\>.mwiki` - for rendering a dynamic table of contents that had been uploaded previously (for example, `proposals/toc/Topic_TOC.mwiki`)

### Special page for attachments: Special:TorqueDataConnectAttachment

In order to protect attachments, a special page is provided.  When calling, it
takes parameters to fetch the attachment, and will prompt with a save-as with the name
specified at upload time.

Parameters:

* `sheet_name` - The name of the sheet this attachment is part of
* `attachment` - The name of the attachment specified at upload time

### User Lookup

You can access the list of TorqueDataConnect users through the TorqueDataConnectUserLookup
class.  It only has one method, `lookupByUsername`, but this will allow you to access a
json object representing the user in MediaWiki.  This user object is currently only a
username and id, but may get built out later.

### Search override

Currently TorqueDataConnect takes over the search results and only searches
against data.  This section is here to be filled out when there's more nuance
to how it manages integrating wiki search results.

## User Rights

* `torquedataconnect-admin` - for admin accounts that can upload data, as well as get prompted when the configuration is broken.

Note that this does NOT prevent other people from editing the configuration page, or pages
linked to those configuration pages.  The maintainer needs to use MediaWiki's permission
system to lock those down, most easily done through namespaces, as [noted above](#WikiPage_configuration)

## Internationalization

Currently only has support for English.
