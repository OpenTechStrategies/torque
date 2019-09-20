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
		} else {
			$teamcomments = 'teamcomments.sql';
		}

		$updater->addExtensionTable( 'TeamComments', "{$dir}/{$teamcomments}" );
	}

	/**
	 * For integration with the Renameuser extension.
	 *
	 * @param RenameuserSQL $renameUserSQL
	 */
	public static function onRenameUserSQL( $renameUserSQL ) {
		$renameUserSQL->tables['TeamComments'] = [ 'Comment_Username', 'Comment_user_id' ];
	}
}
