-- Microsoft SQL Server (MSSQL) variant of TeamComments' database schema
-- This is probably crazy, but so is MSSQL. I've never used MSSQL so
-- there's a fair chance that the code is full of bugs, stupid things or both.
-- Please feel free to submit patches or just go ahead and fix it.
--
-- Tested at SQLFiddle.com against MS SQL Server 2008 & 2012 and at least this
-- builds. Doesn't guarantee anything, though.
--
-- Author: Jack Phoenix
-- Date: 24 July 2013

CREATE TABLE /*$wgDBprefix*/TeamComments (
  TeamCommentID INT NOT NULL PRIMARY KEY IDENTITY(0,1),
  TeamComment_Page_ID INT NOT NULL default 0,
  TeamComment_user_id INT NOT NULL default 0,
  TeamComment_Username NVARCHAR(200) NOT NULL default '',
  TeamComment_Text text NOT NULL,
  TeamComment_Date DATETIME NOT NULL default '0000-00-00 00:00:00',
  TeamComment_Parent_ID INT NOT NULL default 0,
  TeamComment_IP NVARCHAR(45) NOT NULL default '',
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/teamcomment_page_id_index ON /*$wgDBprefix*/TeamComments (TeamComment_Page_ID);
CREATE INDEX /*i*/wiki_user_id ON /*$wgDBprefix*/TeamComments (TeamComment_user_id);
CREATE INDEX /*i*/wiki_user_name ON /*$wgDBprefix*/TeamComments (TeamComment_Username);
