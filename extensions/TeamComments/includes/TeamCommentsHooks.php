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
   *
   * @param Parser $parser
   */
  public static function onParserFirstCallInit( Parser &$parser ) {
    $parser->setHook( 'teamcomments', [ 'DisplayTeamComments', 'getParserHandler' ] );
  }

  /**
   * Adds the new required databas tables into the database when the
   * user runs /maintenance/update.php (the core database updater script).
   *
   * @param DatabaseUpdater $updater
   */
  public static function onLoadExtensionSchemaUpdates( $updater ) {
    $dir = __DIR__ . '/../sql';
    $updater->addExtensionTable( 'teamcomments', "{$dir}/teamcomments.sql" );
  }

  /**
   * For integration with the Renameuser extension.
   *
   * @param RenameuserSQL $renameUserSQL
   */
  public static function onRenameUserSQL( $renameUserSQL ) {
    $renameUserSQL->tables['teamcomments'] = [ 'comment_username', 'comment_user_id' ];
  }
}
