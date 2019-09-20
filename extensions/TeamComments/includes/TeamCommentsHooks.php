<?php
/**
 * Hooked functions used by the TeamComments extension.
 * All class methods are public and static.
 *
 * @file
 * @ingroup Extensions
 * @author Jack Phoenix
 * @author Alexia E. Smith
 * @copyright (c) 2013 Curse Inc.
 * @license GPL-2.0-or-later
 * @link https://www.mediawiki.org/wiki/Extension:TeamComments Documentation
 */

class TeamCommentsHooks {
	/**
	 * Registers the following tags and magic words:
	 * - <teamcomments />
	 * - NUMBEROFTEAMCOMMENTSPAGE
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$parser->setHook( 'teamcomments', [ 'DisplayTeamComments', 'getParserHandler' ] );
		$parser->setFunctionHook( 'NUMBEROFTEAMCOMMENTSPAGE', 'NumberOfTeamComments::getParserHandler', Parser::SFH_NO_HASH );
	}

	/**
	 * Adds the three new required database tables into the database when the
	 * user runs /maintenance/update.php (the core database updater script).
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = __DIR__ . '/../sql';

		$dbType = $updater->getDB()->getType();
		// For non-MySQL/MariaDB/SQLite DBMSes, use the appropriately named file
		if ( !in_array( $dbType, [ 'mysql', 'sqlite' ] ) ) {
			$teamcomments = "teamcomments.{$dbType}.sql";
			$teamcomments_vote = "teamcomments_vote.{$dbType}.sql";
			$teamcomments_block = "teamcomments_block.{$dbType}.sql";
		} else {
			$teamcomments = 'teamcomments.sql';
			$teamcomments_vote = 'teamcomments_vote.sql';
			$teamcomments_block = 'teamcomments_block.sql';
		}

		$updater->addExtensionTable( 'TeamComments', "{$dir}/{$teamcomments}" );
		$updater->addExtensionTable( 'TeamComments_Vote', "{$dir}/{$teamcomments_vote}" );
		$updater->addExtensionTable( 'TeamComments_block', "{$dir}/{$teamcomments_block}" );
	}

	/**
	 * For integration with the Renameuser extension.
	 *
	 * @param RenameuserSQL $renameUserSQL
	 */
	public static function onRenameUserSQL( $renameUserSQL ) {
		$renameUserSQL->tables['TeamComments'] = [ 'Comment_Username', 'Comment_user_id' ];
		$renameUserSQL->tables['TeamComments_Vote'] = [ 'Comment_Vote_Username', 'Comment_Vote_user_id' ];
		$renameUserSQL->tables['TeamComments_block'] = [ 'cb_user_name', 'cb_user_id' ];
		$renameUserSQL->tables['TeamComments_block'] = [ 'cb_user_name_blocked', 'cb_user_id_blocked' ];
	}
}
