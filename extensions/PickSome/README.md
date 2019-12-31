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
sidebar menu.  Then each valid page (see `$wgPickSomePage` below) will have
a special banner that allows the user to pick this page.  They can pick up to
`<N>` proposals.  All picked proposals will be viewable on the Special:PickSome page.

## Parameters

* `$wgPickSomeNumberOfPicks` - The number of picks (defaulted to 2) each user can choose
* `$wgPickSomePage` - Determines if a page can be removed or added.
  * If a string, matches as a regex for titles of pages that are pickable
  * If a function, gets passed a Title and a permission (PickSome::ADD and PickSome::REMOVE) and needs to return a boolean

As an example of a `$wgPickSomePage` function, the following would allow someone to not remove picks, and add only if the page is a linked page in a marker page ("Eligible Picks"):

```
$wgPickSomePage = function($title) {
  if($permission == PickSome::REMOVE) {
    return false;
  }
  $eligibleWildCardsTitle = Title::newFromText("Eligible Picks");
  if($eligibleWildCardsTitle->exists()) {
    $page = new WikiPage($eligibleWildCardsTitle);
    $valid_pages = [];

    // Links are surrounded by brackets
    preg_match_all("/\\[\\[([^\\]]*)\\]\\]/", $page->getContent()->getText(), $valid_pages);

    // Only add to list if it's a valid page on the wiki
    foreach($valid_pages[1] as $valid_page) {
      if($title->equals(Title::newFromText($valid_page))) {
        return true;
      }
    }
    return false;
  } else {
    return false;
  }
};

```

## Rights

* `'picksome'` - Accounts who have the rights to use PickSome can access the interface.:
* `'picksome-write'` - Accounts that can add/remove choices (after clearing $wgPickSomePage) above.  Accounts that have `'picksome'` but not `'picksome-write`' will be able to view picks, but not make them
* `'picksome-admin'` - Accounts that can remove other user picks (from the global pick page)

To enable for everyone, the following to lines should be added:

```
$wgGroupPermissions['*']['picksome'] = true;
$wgGroupPermissions['*']['picksome-write'] = true;
```


## Internationalization

Currently only has support for English.
