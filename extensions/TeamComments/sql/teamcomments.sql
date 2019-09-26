-- MySQL/SQLite schema for the Teamteamcomments extension

CREATE TABLE /*_*/teamcomments (
  teamcomment_id int(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
  teamcomment_page_id int(11) NOT NULL default 0,
  teamcomment_user_id int(11) NOT NULL default 0,
  teamcomment_username varchar(200) NOT NULL default '',
  teamcomment_text text NOT NULL,
  teamcomment_date datetime NOT NULL default '1970-01-01 00:00:01',
  teamcomment_date_lastedited datetime,
  teamcomment_parent_id int(11) NOT NULL default 0,
  teamcomment_ip varchar(45) NOT NULL default '',
  teamcomment_deleted boolean NOT NULL default false
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/teamcomment_page_id_index ON /*_*/teamcomments (teamcomment_page_id);
CREATE INDEX /*i*/wiki_user_id ON /*_*/teamcomments (teamcomment_user_id);
CREATE INDEX /*i*/wiki_user_name ON /*_*/teamcomments (teamcomment_username);
