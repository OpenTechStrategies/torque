# Extension:PickSome

The PickSome extension all users to pick pages that show up in a global
PickSome list on the Special:PickSome page.

## Installation

* Download and place the file(s) in a directory called Comments in your extensions/ folder
* Add the following line to your LocalSettings.php
```
wfLoadExtension('PickSome');
```
* Run the update script `php <mediawiki-instance>/maintenance/update.php` to create the DB tables

## Usage

For logged in users, can enable PickSome by clicking "Start Picking" on the
sidebar menu.  Then each proposal (a page ending in `(\d*)`) will have a special
banner that allows the user to pick this page as a picksome.  They can pick
up to `<N>` proposals.  All picked proposals will be viewable on the
Special:PickSome page.

## Parameters

* `$wgPickSomeNumberOfPicks` - The number of picks (defaulted to 2) each user can choose

## Internationalization

Currently only has support for English.
