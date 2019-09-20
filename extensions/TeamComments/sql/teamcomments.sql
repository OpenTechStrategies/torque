-- MySQL/SQLite schema for the TeamTeamComments extension

CREATE TABLE /*_*/TeamComments (
  TeamCommentID int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  TeamComment_Page_ID int(11) NOT NULL default 0,
  TeamComment_user_id int(11) NOT NULL default 0,
  TeamComment_Username varchar(200) NOT NULL default '',
  TeamComment_Text text NOT NULL,
  TeamComment_Date datetime NOT NULL default '1970-01-01 00:00:01',
  TeamComment_Parent_ID int(11) NOT NULL default 0,
  TeamComment_IP varchar(45) NOT NULL default ''
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/teamcomment_page_id_index ON /*_*/TeamComments (TeamComment_Page_ID);
CREATE INDEX /*i*/wiki_user_id ON /*_*/TeamComments (TeamComment_user_id);
CREATE INDEX /*i*/wiki_user_name ON /*_*/TeamComments (TeamComment_Username);
