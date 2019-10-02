# PickSome Extension

Mediawiki extension to allow users to pick pages that show up in a global
PickSome list on the Special:PickSome page.

# Install

* Symlink or copy the extension folder to your mediawiki extensions directory
* Add the following line to your LocalSettings.php
```
wfLoadExtension('PickSome');
```
* Run `php <mediawiki-instance>/maintenance/update.php` to create the DB tables

# Usage

Each proposal (a page ending in `(\d*)`), when logged in, will have a special
banner that allows the user to pick this page as a picksome.  They can pick
up to two proposals (hardcoded for now).  All picked proposals will be
viewable on the Special:PickSome page.
