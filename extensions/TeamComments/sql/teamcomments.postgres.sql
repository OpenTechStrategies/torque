-- PostgreSQL variant of TeamComments' database schema
-- This is probably crazy, but so is PostgreSQL. I've never used PGSQL so
-- there's a fair chance that the code is full of bugs, stupid things or both.
-- Please feel free to submit patches or just go ahead and fix it.
--
-- Tested at SQLFiddle.com against PostgreSQL 8.3.20 & 9.1.9 and at least this
-- builds. Doesn't guarantee anything, though.
--
-- Author: Jack Phoenix
-- Date: 24 July 2013

DROP SEQUENCE IF EXISTS TeamComments_TeamCommentID_seq CASCADE;
CREATE SEQUENCE TeamComments_TeamCommentID_seq MINVALUE 0 START WITH 0;

CREATE TABLE TeamComments (
  TeamCommentID INTEGER NOT NULL PRIMARY KEY DEFAULT nextval('TeamComments_TeamCommentID_seq'),
  TeamComment_Page_ID INTEGER NOT NULL DEFAULT 0,
  TeamComment_user_id INTEGER NOT NULL DEFAULT 0,
  TeamComment_Username TEXT NOT NULL DEFAULT '',
  TeamComment_Text TEXT NOT NULL,
  TeamComment_Date TIMESTAMPTZ NOT NULL DEFAULT now(),
  TeamComment_Parent_ID INTEGER NOT NULL DEFAULT 0,
  TeamComment_IP TEXT NOT NULL DEFAULT ''
);

CREATE INDEX teamcomment_page_id_index ON TeamComments (TeamComment_Page_ID);
CREATE INDEX wiki_user_id ON TeamComments (TeamComment_user_id);
CREATE INDEX wiki_user_name ON TeamComments (TeamComment_Username);
