This document works through an example of a Torque system as a companion
to [the design](DESIGN.md).  Several pieces in that document are more challenging
to explain than to show, requiring a practical use case.

# Setting up the system

This section details the initial setup of the system and upload of the data.
The actions here are meant to be done once, even though you can re-upload
data with changes as needed.

## Setting up MediaWiki

You can get MediaWiki from their
[download site](https://www.mediawiki.org/wiki/Download) or as part of your
package manager.  Torque requires MediaWiki version 1.35 or greater.
Then set it up normally, either through the web interface or the command
line.  This will usually include an admin user.  The simplest way to get
MediaWiki installed is to unpack the tar file that you downloaded into your
web directory.  Then, visit that page in your browser, and follow the prompts,
after which you place the LocalSettings.php into that same web directory.

Torque also requires PHP 7.3+

After that, copy the files from Torque into the extensions directory
of that instance.  The example file will create a simlink for you.

## Setting up Torque

Follow the [installation instructions](./django-torque/README.md#installation-and-startup)

# Installing the example system

In [the example directory](./example), follow the [installation instructions](./example/INSTALL.md)

# Using the API

To access this data from another script, we can do the following:

```python
import mwclient
import json
site = mwclient.Site('yourdomain.tld', path='wiki/', scheme='https')
site.login("<API_USER>", "<API_PASSWORD>")
proposalData = site.api('torque', format='json', path='/proposals')

# 5
print(len(proposalData["proposals"]))
print()

# Application A
print(next(filter(lambda p: p["Application Id"] == "1", proposalData["proposals"]))['Title'])
print()

# All available columns
print(proposalData["proposals"][0].keys())
```
