-- Oracle variant of TeamComments' database schema
-- This is probably crazy, but so is Oracle. I've never used Oracle so
-- there's a fair chance that the code is full of bugs, stupid things or both.
-- Please feel free to submit patches or just go ahead and fix it.
--
-- This DOES NOT build at SQLFiddle.com...
--
-- Author: Jack Phoenix
-- Date: 24 July 2013

-- No idea if this is needed, but /maintenance/oracle/tables.sql uses it, so I
-- guess it serves some purpose here, too
define mw_prefix='{$wgDBprefix}';

CREATE SEQUENCE TeamComments_TeamCommentID_seq;

CREATE TABLE &mw_prefix.TeamComments (
  TeamCommentID NUMBER NOT NULL,
  TeamComment_Page_ID NUMBER NOT NULL DEFAULT 0,
  TeamComment_user_id NUMBER NOT NULL DEFAULT 0,
  TeamComment_Username VARCHAR2(200) NOT NULL,
  -- CLOB (original MySQL one uses text), as per http://stackoverflow.com/questions/1180204/oracle-equivalent-of-mysqls-text-type
  TeamComment_Text CLOB NOT NULL,
  TeamComment_Date TIMESTAMP(6) WITH TIME ZONE NOT NULL,
  TeamComment_Parent_ID NUMBER NOT NULL DEFAULT 0,
  TeamComment_IP VARCHAR2(45) NOT NULL,
);

CREATE INDEX &mw_prefix.teamcomment_page_id_index ON &mw_prefix.TeamComments (TeamComment_Page_ID);
CREATE INDEX &mw_prefix.wiki_user_id ON &mw_prefix.TeamComments (TeamComment_user_id);
CREATE INDEX &mw_prefix.wiki_user_name ON &mw_prefix.TeamComments (TeamComment_Username);

ALTER TABLE &mw_prefix.TeamComments ADD CONSTRAINT &mw_prefix.TeamComments_pk PRIMARY KEY (TeamCommentID);
