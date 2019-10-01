# Extension:TeamComments
The TeamComments extension adds the <teamcomments /> parser hook tag to allow commenting on articles where the tag is present.

## Installation

* Download and place the file(s) in a directory called Comments in your extensions/ folder.
* Add the following code at the bottom of your LocalSettings.php:

```
wfLoadExtension('TeamComments');
```

* Run the update script which will automatically create the necessary database tables that this extension needs.
* Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

## Usage

* `<teamcomments />` - place wherever on a page (usually the bottom) that you want the comments section to appear

User rights

This extension adds three new user rights:

* `teamcomment` (which allows posting comments)
* `teamcommentlinks` (which allows posting external links in comments)
* `teamcommentadmin` (which allows deleting user-posted comments), e.g.

```
$wgGroupPermissions['sysop']['commentadmin'] = true;
```

Only logged in users can post comments.

## Parameters

* `$wgTeamCommentsEnabled` - Whether the system is enabled on a global scale.  Provided so comments can be turned off while keeping the tags in pages
* `$wgCommentsInRecentChanges` - by default, this variable is set to false. Set it to true to display comments log entries in Special:RecentChanges, too, in addition to the comments log at Special:Log/comments.
* `$wgTeamCommentsCheatSheetLocation` - by default, this variable is set to false.  Set it to a location to have a a link in the comments section to a user specified cheat sheet

Internationalization

The TeamComments extension inherited (partial or full) support for 68 different languages from the Comments Extesion, including English.
