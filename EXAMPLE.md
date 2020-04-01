This document works through a sample example of a torque system as a companion
to [the design](DESIGN.md).  Several pieces in that document are more challenging
to explain than to show, requiring a practical use case.

# Setting up the system

This section details the initial setup of the system and upload of the data.
The actions here are meant to be done once, even though you can re-upload
data with changes as needed.

## Setting up MediaWiki

You can either get MediaWiki from their
[download site](https://www.mediawiki.org/wiki/Download) or as part of your
package manager.  torque has been tested with MediaWiki 1.33, and should work
with that or greater.  Then set it up normally, either through the web
interface, or via the commandline.  This will usually include an admin user.

After that, copy the files from TorqueDataConnect into the extensions directory
of that instance.

```
$ cp -a TorqueDataConnect /path/to/mediawiki/extensions/
```

Enable and configure TorqueDataConnect in LocalSettings:

```php
# Define a namespace that's locked down from normal users
define("TORQUE_CONFIG", 4000);
define("TORQUE_CONFIG_TALK", 4001);
$wgExtraNamespaces[TORQUE_CONFIG] = "TorqueConfig";
$wgExtraNamespaces[TORQUE_CONFIG_TALK] = "TorqueConfig_talk";

# Create a permission for editting pages in this namespace
$wgNamespaceProtection[TORQUE_CONFIG] = array("edittorqueconfig");

# Set up the basic torque variables
$wgTorqueDataConnectSheetName = "proposals";
$wgTorqueDataConnectWikiKey = "MyTestWiki";
$wgTorqueDataConnectConfigPage = "TorqueConfig:MainConfig";  # In the new protected namespace

# Give sysops full permissions
$wgGroupPermissions['sysop']['edittorqueconfig'] = true;
$wgGroupPermissions['sysop']['torquedataconnect-admin'] = true;

# Add a public group
$wgGroupPermissions['public']['read'] = true;

# Load the extension
wfLoadExtension('TorqueDataConnect');
```

## Setting up torque

Follow the [installation instructions](torquedata/README.md#Installation and Startup)

## Uploading a spreadsheet

Get the [example spreadsheet](example.csv).  Note the second line that has the type
information.  in this example, the Cities are a list, and are separated by new lines.

Upload the document to the running mediawiki instance using
[mwclient](https://mwclient.readthedocs.io/en/latest/reference/site.html)

```python
import mwclient

# Fill these values in
host = "yourdomain.tld"
path = "wiki/"
scheme = "https"
username = "USERNAME"
password = "PASSWORD"
csv_file = "example.csv"

# We need a very large timeout because uploading reindexes everything!
site = mwclient.Site(host, path=path, scheme=scheme, reqs={'timeout': 300})
site.login(username, password)
with open(csv_file) as f:
    site.raw_call(
    'api',
    {
        "action": "torquedataconnectuploadsheet",
        "format": 'json',
        "object_name": 'proposal',
        "sheet_name": 'proposals',
        "key_column": 'Application #'
    },
    { "data_file": f.read() }
    )

```

Some hard coded values that should be changed when working with real data:

* The object_name is "proposal" and the sheet_name is "proposals".  These will
  be the names of the objects in the templates later
* The key_column is the "Application #"

## Uploading an attachment

In the spreadsheet, there is a file referenced called "SupportingData.txt."
It's referenced in the Filename column of the spreadsheet, for Application #
'2' which we'll use in the template to refer to the special page.

The first step is to create it:

```
$ echo "This is Supporting Data" > SupportingData.txt
```

The following python code can upload it.

```python
import mwclient

# Fill these values in
host = "yourdomain.tld"
path = "wiki/"
scheme = "https"
attachment_file = "SupportingData.csv"

site = mwclient.Site(host, path=path, scheme=scheme)
site.login(username, password)
with open(attachment_file) as attachment:
    site.raw_call(
    'api',
    {
        "action": "torquedataconnectuploadattachment",
        "format": 'json',
        "sheet_name": 'proposals',
        "object_id": '2',
        "permissions_column": "Filename",
        "attachment_name": attachment_file
    },
    {
        "attachment": attachment.read()
    }
    )
```

## Uploading some Tables of Contents

We want to upload two kinds of tables of contents.  The first is
just a list of all the proposals available to the user:

```
$ cat > list.j2 << EOF
{%- for proposal_id in proposals.keys() %}
* {{ toc_lines[proposal_id] }}
{% endfor %}
EOF

$ cat > list.json << EOF
{}
EOF
```

This creates two files.  The first is a jinja template that loops over the
provided proposals and then uses the `toc_lines` variable to get the TOC
template rendered information.  Note that it's just creating a MediaWiki
list using MediaWiki markup via the `*` at the beginning.  You could use
`1.` or similar if you wanted a different type of list.  Also note that even
though there's no supporting data, we still need a json file when uploading.

The second is a table of contents grouped by city:

```
$ cat > bycity.j2 << EOF
{% for city_name, proposal_ids in cities.items() %}
    {%- set proposals_in_city = [] %}
    {%- for proposal_id in proposal_ids %}
        {%- if proposal_id in proposals.keys() %}
            {{- "" if proposals_in_city.append(proposal_id) }}
        {%- endif %}
    {%- endfor %}
    {%- if proposals_in_city|length > 0 %}
= {{ city_name }} =
        {%- for proposal_id in proposals_in_city %}
* {{ toc_lines[proposal_id] }}
        {%- endfor %}
    {%- endif %}
{%- endfor %}
EOF

$ cat > citydata.json << EOF
{
  "cities": {
    "Chicago": ["1", "2", "5"],
    "Paris": ["2", "3"],
    "Tokyo": ["2", "5"],
  }
}
EOF
```

This set is doing a fair amount.  The json file is a simple relation of
which application ids are linked to what cities.  This could be generated
at upload time from the spreadsheet, or hand curated.  Because torque
can't know anything specific about data, these kinds of relations have
to be built at data upload time, rather than dynamically.

The jinja template is looping over all the data present in citydata.json,
then ascertaining which proposals the user has access to via the `proposals`
field.  After it ensures that the user has access to at least 1 proposal
in that city, it displays the city name as a wiki header, and then loops
over all the proposals matched to that city.  It then uses the TOC template
via the `toc_lines` variable to put out the markup representing that proposal.

Now let's upload these files via python

```python
import mwclient

# Fill these values in
host = "yourdomain.tld"
path = "wiki/"
scheme = "https"
username = "USERNAME"
password = "PASSWORD"

site = mwclient.Site(host, path=path, scheme=scheme)
site.login(username, password)
template_file_name = "list.j2"
json_file_name = "list.json"
with open(os.path.join(template_file_name)) as template_file, \
    open(json_file_name) as json_file:
        site.raw_call(
        'api',
        {
            "action": "torquedataconnectuploadtoc",
            "format": 'json',
            "sheet_name": 'proposals',
            "toc_name": "Everything"
        },
        {
            "template": template_file.read(),
            "json": json_file.read()
        }
        )

template_file_name = "bycity.j2"
json_file_name = "citydata.json"
with open(os.path.join(template_file_name)) as template_file, \
    open(json_file_name) as json_file:
        site.raw_call(
        'api',
        {
            "action": "torquedataconnectuploadtoc",
            "format": 'json',
            "sheet_name": 'proposals',
            "toc_name": "ByCity"
        },
        {
            "template": template_file.read(),
            "json": json_file.read()
        }
        )
```

# Live Configuration

This section details how to configure the running system through the TorqueConfig
namespace.  These files are meant to be living files that reside in MediaWiki with
updates, history, and greater access to torque admin users.

## The main config page

We configured above that the main config page should live at TorqueConfig:MainConfig
when setting up our LocalSettings file, so let's edit that page now.  Navigate
to that page in your browser and create it;

```MediaWiki
= Permissions =

 {|class="wikitable"
!User Group
!Columns
!Proposal Groups
|-
|sysop
|[[TorqueConfig:AllColumns]]
|[[TorqueConfig:AllProposals]]
|-
|public
|[[TorqueConfig:NonConfidentialColumns]]
|[[TorqueConfig:NonConfidentialProposals]]
|}

= Templates =

{|class="wikitable"
!Name
!Template
!Type
|-
|Full
|[[TorqueConfig:MwikiTemplate]]
|View
|-
|Redacted
|[[TorqueConfig:RedactedTemplate]]
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

When saving this page, all the links should be red for pages that are not yet
created, and there should be a header at the top that alerts you to the fact
that they don't exist.

In this configuration, we have two groups of users: sysops (which is a MediaWiki
defined group) and public users.  The latter needed to be configured in
LocalSettings.  We also have four templates, two view templates (Full
and Redacted), as well as a Search template and a TOC template.

We can click on all these pages and create each one in turn.

## TorqueConfig:AllColumns

This is a list of all the columns.  There's nothing special about the name
other than self documenting what the list should be.  The columns should
be a simple mediawiki list on this page:

```MediaWiki
* Application #
* Title
* Description
* Confidential
* Cities
* Filename
```

## TorqueConfig:AllProposals

Similarly, here's a list of all the proposals in the system.  Note that
the only thing torque cares about is the id before the colon.  For
what comes after, this convention is to provide links to the pages
having those proposals themselves.  These links will be red becase
we haven't added the pages with those proposals to the wiki yet,
even though the data is in torque.

```MediaWiki
* 1: [[Application A]]
* 2: [[Application B]]
* 3: [[Application C]]
* 4: [[Application D]]
* 5: [[Application E]]
```

## TorqueConfig:NonConfidentialColumns

These columns are the ones that are public information.  We remove
the Filename and Confidential sections for those users.

```MediaWiki
* Application #
* Title
* Description
* Cities
```

## TorqueConfig:NonConfidentialProposals

Similarly, the first three proposals are all those users have
access to see.

```MediaWiki
* 1: [[Application A]]
* 2: [[Application B]]
* 3: [[Application C]]
```

## TorqueConfig:MwikiTemplate

The main template for viewing.

```MediaWiki
= {{ proposal['Title'] }} =
{{ proposal['Description'] }}

= Cities =
{% for city in proposal['Cities'] %}
* {{ city }}
{% endfor %}

{% if 'Confidential' in proposal.keys() %}
= Confidential Information }}

{{ proposal['Confidential'] }}

  {%- if 'Filename' in proposal.keys() -%}
    '''Supporting Data''': [{{ "{{" }}fullurl:Special:TorqueDataConnectAttachment|sheet_name=proposals&id={{proposal['Application #']}}&attachment={{ proposal['Filename'] | replace(" ", "+") | urlencode }}{{ "}}" }} Supporting Data]
  {%- endif -%}
{% endif %}
```

This template is a standard jinja template where the object data was handed
to it with the name `proposal` because that's how we configured it when
uploading it.

Some notes:

* For list types, the list will be broken apart in python so you can just iterate over them
* For `{{` and `}}` which are sometimes needed in wiki markup, and in order to do that
  in jinja, you need to put them inside an expression
* If you want to do indenting, you need to add a lot of `-` to the brackets so that jinja
  strips the whitespace.  Otherwise, the wiki will interpret your indenting as important
  to the markup.
* For linking into the SpecialPage for the attachment, a full url is required as it's
  not a native MediaWiki page.

## TorqueConfig:RedactedTemplate

This is a slightly redacted template.  Some uses for different views:

* Different layout for printing out proposals
* Skinned proposals for presentation purposes
* Developing a replacement template without impacting current users.

```MediaWiki
= {{ proposal['Title'] }} =
{{ proposal['Description'] }}

{% if 'Confidential' in proposal.keys() %}
= Confidential Information }}

{{ proposal['Confidential'] }}
{% endif %}
```

Note that this redacted template still needs to check for users who
may not have access to Confidential information, since templates are
available for all users.

## TorqueConfig:SearchTemplate

This template links to the page, but also adds some styling to make
it look somewhat like the normal mediawiki results:

```MediaWiki
<div style='max-width:38em'>
[[{{ proposal['Title'] }}]]
<div style='max-height:6.5em;line-height:1.5em;overflow:hidden;'>
'''{{ proposal["Description"] }}'''
</div>

<hr>
</div>
```

## TorqueConfig:TOCTemplate

This template not only links to the appropriate page, but also notes the
application id in blue.

```MediaWiki
<span style='color:blue;font-style: italic>Application {{ proposal['Application #'] }}</span> - [[{{ proposal["Title"] -}}]]
```

The full jinja templating system is available to us, so we could do something
like displaying a different line for those users with access to the
Confidential column if we wanted.

## Pages that have proposals on them

Now that everything is created, we need to create the wiki pages
that are going to house the rendered templates.  If there's a lot
of pages, using the `mwclient` library to create them all can save
time, but for this example hand crafting is fine.

For each application, go to

`http://yourdomain.tld/wiki/index.php/Application_A` and make the
page:

```MediaWiki
{{ #tdcrender:proposals/id/1.mwiki }}
```

Then repeat for:
* `http://yourdomain.tld/wiki/index.php/Application_B`, to 2.mwiki
* `http://yourdomain.tld/wiki/index.php/Application_C`, to 3.mwiki
* `http://yourdomain.tld/wiki/index.php/Application_D`, to 4.mwiki
* `http://yourdomain.tld/wiki/index.php/Application_E`, to 5.mwiki

## The Everything List TOC

Similarly, the Everything List TOC might look like:

```MediaWiki
{{ #tdcrender:proposals/toc/Everything.mwiki }}
```

You can put this anywhere in your wiki, on whatever page makes
the most sense.  You could do it on the MainPage, or some
other list section.

## The City Grouping TOC

For the city, we might want to have a MediaWiki TOC at the top
of the page:

```MediaWiki
__TOC__

{{ #tdcrender:proposals/toc/ByCity.mwiki }}
```

# Using the API

To access this data from another script, we can do the following:

```python
import mwclient
import json
site = mwclient.Site('yourdomain.tld', path='wiki/', scheme='https')
site.login("<API_USER>", "<API_PASSWORD>")
proposalData = site.api('torquedataconnect', format='json', path='/proposals')

# 5
print(len(proposalData["proposals"]))
print()

# Application A
print(next(filter(lambda p: p["Application Id"] == "1", proposalData["proposals"]))['Title'])
print()

# All available columns
print(proposalData["proposals"][0].keys())
```
