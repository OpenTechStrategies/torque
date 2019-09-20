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

CREATE TABLE TeamComments_Vote (
  TeamComment_Vote_ID INTEGER NOT NULL DEFAULT 0,
  TeamComment_Vote_user_id INTEGER NOT NULL DEFAULT 0,
  TeamComment_Vote_Username TEXT NOT NULL DEFAULT '',
  TeamComment_Vote_Score INTEGER NOT NULL DEFAULT 0,
  TeamComment_Vote_Date TIMESTAMPTZ NOT NULL DEFAULT now(),
  TeamComment_Vote_IP TEXT NOT NULL DEFAULT ''
);

CREATE UNIQUE INDEX TeamComments_Vote_user_id_index ON TeamComments_Vote (TeamComment_Vote_ID,TeamComment_Vote_Username);
CREATE INDEX TeamComment_Vote_Score ON TeamComments_Vote (TeamComment_Vote_Score);
CREATE INDEX TeamComment_Vote_user_id ON TeamComments_Vote (TeamComment_Vote_user_id);
