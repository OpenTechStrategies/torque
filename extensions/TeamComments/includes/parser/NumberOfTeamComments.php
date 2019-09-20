<?php

class NumberOfTeamComments {
	/**
	 * Registers NUMBEROFTEAMCOMMENTS and NUMPBEROFTEAMCOMMENTSPAGE as a valid magic word identifier.
	 *
	 * @param array $variableIds Array of valid magic word identifiers
	 * @return bool true
	 */
	public static function onMagicWordwgVariableIDs( &$variableIds ) {
		$variableIds[] = 'NUMBEROFTEAMCOMMENTS';
		$variableIds[] = 'NUMBEROFTEAMCOMMENTSPAGE';

		return true;
	}

	/**
	 * Main backend logic for the {{NUMBEROFTEAMCOMMENTS}} and {{NUMBEROFTEAMCOMMENTSPAGE}}
	 * magic word.
	 * If the {{NUMBEROFTEAMCOMMENTS}} magic word is found, first checks memcached to
	 * see if we can get the value from cache, but if that fails for  some reason,
	 * then a COUNT(*) SQL query is done to fetch the amount from the database.
	 * If the {{NUMBEROFTEAMCOMMENTSPAGE}} magic word is found, uses
	 * NumberOfTeamComments::getNumberOfTeamCommentsPage to get the number of teamcomments
	 * for this article.
	 *
	 * @param $parser Parser
	 * @param $cache
	 * @param string $magicWordId Magic word identifier
	 * @param int $ret What to return to the user (in our case, the number of teamcomments)
	 * @return bool
	 */
	public static function onParserGetVariableValueSwitch( &$parser, &$cache, &$magicWordId, &$ret ) {
		global $wgMemc;

		if ( $magicWordId == 'NUMBEROFTEAMCOMMENTS' ) {
			$key = $wgMemc->makeKey( 'teamcomments', 'magic-word' );
			$data = $wgMemc->get( $key );
			if ( $data != '' ) {
				// We have it in cache? Oh goody, let's just use the cached value!
				wfDebugLog(
					'TeamComments',
					'Got the amount of teamcomments from memcached'
				);
				// return value
				$ret = $data;
			} else {
				// Not cached → have to fetch it from the database
				$dbr = wfGetDB( DB_REPLICA );
				$teamcommentCount = (int)$dbr->selectField(
					'TeamComments',
					'COUNT(*) AS count',
					[],
					__METHOD__
				);
				wfDebugLog( 'TeamComments', 'Got the amount of teamcomments from DB' );
				// Store the count in cache...
				// (86400 = seconds in a day)
				$wgMemc->set( $key, $teamcommentCount, 86400 );
				// ...and return the value to the user
				$ret = $teamcommentCount;
			}
		} elseif ( $magicWordId == 'NUMBEROFTEAMCOMMENTSPAGE' ) {
			$id = $parser->getTitle()->getArticleID();
			$ret = self::getNumberOfTeamCommentsPage( $id );
		}

		return true;
	}

	/**
	 * Hook for parser function {{NUMBEROFTEAMCOMMENTSPAGE:<page>}}
	 *
	 * @param Parser $parser
	 * @param string $pagename Page name
	 * @return int Amount of teamcomments on the given page
	 */
	static function getParserHandler( $parser, $pagename ) {
		$page = Title::newFromText( $pagename );

		if ( $page instanceof Title ) {
			$id = $page->getArticleID();
		} else {
			$id = $parser->getTitle()->getArticleID();
		}

		return self::getNumberOfTeamCommentsPage( $id );
	}

	/**
	 * Get the actual number of teamcomments
	 *
	 * @param int $pageId ID of page to get number of teamcomments for
	 * @return int
	 */
	static function getNumberOfTeamCommentsPage( $pageId ) {
		global $wgMemc;

		$key = $wgMemc->makeKey( 'teamcomments', 'numberofteamcommentspage', $pageId );
		$cache = $wgMemc->get( $key );

		if ( $cache ) {
			$val = intval( $cache );
		} else {
			$dbr = wfGetDB( DB_REPLICA );

			$res = $dbr->selectField(
				'TeamComments',
				'COUNT(*)',
				[ 'TeamComment_Page_ID' => $pageId ],
				__METHOD__
			);

			if ( !$res ) {
				$val = 0;
			} else {
				$val = intval( $res );
			}
			$wgMemc->set( $key, $val, 60 * 60 ); // cache for an hour
		}

		return $val;
	}

}
