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

CREATE TABLE /*$wgDBprefix*/TeamComments_Vote (
  TeamComment_Vote_ID INT NOT NULL default 0,
  TeamComment_Vote_user_id INT NOT NULL default 0,
  TeamComment_Vote_Username NVARCHAR(200) NOT NULL default '',
  TeamComment_Vote_Score INT NOT NULL default 0,
  TeamComment_Vote_Date DATETIME NOT NULL default '0000-00-00 00:00:00',
  TeamComment_Vote_IP NVARCHAR(45) NOT NULL default ''
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/TeamComments_Vote_user_id_index ON /*$wgDBprefix*/TeamComments_Vote (TeamComment_Vote_ID,TeamComment_Vote_Username);
CREATE INDEX /*i*/TeamComment_Vote_Score ON /*$wgDBprefix*/TeamComments_Vote (TeamComment_Vote_Score);
CREATE INDEX /*i*/TeamComment_Vote_user_id ON /*$wgDBprefix*/TeamComments_Vote (TeamComment_Vote_user_id);
