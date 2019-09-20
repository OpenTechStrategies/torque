-- MySQL/SQLite schema for the TeamComments extension

CREATE TABLE /*_*/TeamComments_Vote (
  TeamComment_Vote_ID int(11) NOT NULL default 0,
  TeamComment_Vote_user_id int(11) NOT NULL default 0,
  TeamComment_Vote_Username varchar(200) NOT NULL default '',
  TeamComment_Vote_Score int(4) NOT NULL default 0,
  TeamComment_Vote_Date datetime NOT NULL default '1970-01-01 00:00:01',
  TeamComment_Vote_IP varchar(45) NOT NULL default ''
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/TeamComments_Vote_user_id_index ON /*_*/TeamComments_Vote (TeamComment_Vote_ID,TeamComment_Vote_Username);
CREATE INDEX /*i*/TeamComment_Vote_Score ON /*_*/TeamComments_Vote (TeamComment_Vote_Score);
CREATE INDEX /*i*/TeamComment_Vote_user_id ON /*_*/TeamComments_Vote (TeamComment_Vote_user_id);
