<?php
/**
 * @file
 * @ingroup SpecialPage SimpleFavoritelist
 */

/**
 * Constructor
 *
 * @param $par Parameter
 *        	passed to the page
 */
class SpecialSimpleFavoritelist extends SpecialPage {

	function __construct() {
		parent::__construct ( 'SimpleFavoritelist' );
	}
	function execute($par) {
		$context = $this->getContext();
		$vwfav = new ViewSimpleSimpleFavorites ($context);

		$this->setHeaders ();
		$param = $this->getRequest()->getText ( 'param' );

		$vwfav->wfSpecialSimpleFavoritelist ( $par );
	}

	protected function getGroupName() {
		return 'other';
	}
}

class ViewSimpleSimpleFavorites {

	private $context;
	private $user;
	private $out;
	private $request;
	private $lang;

	function __construct($context) {
		$this->context = $context;
		$this->out = $this->context->getOutput();
		$this->request = $this->context->getRequest();
		$this->lang = $this->context->getLanguage();
		$this->user = $this->context->getUser();
	}

	function wfSpecialSimpleFavoritelist($par) {

		global $wgFeedClasses;

		// Add feed links
		$flToken = $this->user->getOption ( 'simplefavoritelisttoken' );
		if (! $flToken) {
			$flToken = sha1 ( mt_rand () . microtime ( true ) );
			$this->user->setOption ( 'simplefavoritelisttoken', $flToken );
			$this->user->saveSettings ();
		}

		$apiParams = array (
				'action' => 'feedsimplefavoritelist',
				'allrev' => 'allrev',
				'flowner' => $this->user->getName (),
				'fltoken' => $flToken
		);
		$feedTemplate = wfScript ( 'api' ) . '?';

		foreach ( $wgFeedClasses as $format => $class ) {
			$theseParams = $apiParams + array (
					'feedformat' => $format
			);
			$url = $feedTemplate . wfArrayToCGI ( $theseParams );
			$this->out->addFeedLink ( $format, $url );
		}

		$specialTitle = SpecialPage::getTitleFor ( 'SimpleFavoritelist' );
		$this->out->setRobotPolicy ( 'noindex,nofollow' );

		// Anons don't get a simplefavoritelist
		if ($this->user->isAnon ()) {
			$this->out->setPageTitle ( wfMessage ( 'simplefavoritenologin' ) );
			$llink = Linker::linkKnown ( SpecialPage::getTitleFor ( 'Userlogin' ), wfMessage ( 'loginreqlink' )->text (), array (), array (
					'returnto' => $specialTitle->getPrefixedText ()
			) );
			$this->out->addHTML ( wfMessage ( 'simplefavoritelistanontext', $llink )->text () );
			return;
		}

		$this->out->setPageTitle ( wfMessage ( 'simplefavoritelist' ) );

		$this->viewFavList ( $this->user, $this->out, $this->request );
	}
	private function viewFavList($user, $output, $request) {

		$uid = $this->user->getId ();
		$output->setPageTitle ( wfMessage ( 'simplefavoritelist' ) );

		$this->showNormalForm ( $output, $user );

		$dbr = wfGetDB ( DB_REPLICA, 'simplefavoritelist' );
		// $recentchanges = $dbr->tableName( 'recentchanges' );

		$simplefavoritelistCount = $dbr->selectField ( 'simplefavoritelist', 'COUNT(fl_user)', array (
				'fl_user' => $uid
		), __METHOD__ );
		// Adjust for page X, talk:page X, which are both stored separately,
		// but treated together
		// $nitems = floor($simplefavoritelistCount / 2);
		$nitems = $simplefavoritelistCount;
		if ($nitems == 0) {
			$this->out->addWikiMsg ( 'nosimplefavoritelist' );
			return;
		}
	}

	/**
	 * Check the edit token from a form submission
	 *
	 * @param $request WebRequest
	 * @param $user User
	 * @return bool
	 */
	private function checkToken($request, $user) {
		return $user->matchEditToken ( $request->getVal ( 'token' ), 'simplefavorite' );
	}

	/**
	 * Extract a list of titles from a blob of text, returning
	 * (prefixed) strings; unfavoritable titles are ignored
	 *
	 * @param $list mixed
	 * @return array
	 */
	private function extractTitles($list) {
		$titles = array ();
		if (! is_array ( $list )) {
			$list = explode ( "\n", trim ( $list ) );
			if (! is_array ( $list ))
				return array ();
		}
		foreach ( $list as $text ) {
			$text = trim ( $text );
			if (strlen ( $text ) > 0) {
				$title = Title::newFromText ( $text );
				// if( $title instanceof Title && $title->isFavoritable() )
				$titles [] = $title->getPrefixedText ();
			}
		}
		return array_unique ( $titles );
	}

	/**
	 * Print out a list of linked titles
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * @param $titles An
	 *        	array of strings, or Title objects
	 * @param $output OutputPage
	 */
	private function showTitles($titles, $output) {
		$talk = wfMessage ( 'talkpagelinktext' )->text ();
		// Do a batch existence check
		$batch = new LinkBatch ();
		foreach ( $titles as $title ) {
			if (! $title instanceof Title)
				$title = Title::newFromText ( $title );
			// if( $title instanceof Title ) {
			// 	$batch->addObj( $title );
			// 	if ( $title->canHaveTalkPage() ) {
			// 		$batch->addObj( $title->getTalkPage() );
			// 	}
			// }
		}
		$batch->execute ();
		// Print out the list
		$output->addHTML ( "<ul>\n" );
		foreach ( $titles as $title ) {
			if (! $title instanceof Title)
				$title = Title::newFromText ( $title );
			if ($title instanceof Title) {
				$output->addHTML ( "<li>" . Linker::link ( $title ) .
				"</li>\n" );
			}
		}
		$output->addHTML ( "</ul>\n" );
	}

	/**
	 * Count the number of titles on a user's simplefavoritelist, excluding talk pages
	 *
	 * @param $user User
	 * @return int
	 */
	private function countSimpleFavoritelist($user) {
		$dbr = wfGetDB ( DB_MASTER );
		$res = $dbr->select ( 'simplefavoritelist', 'COUNT(fl_user) AS count', array (
				'fl_user' => $user->getId ()
		), __METHOD__ );
		$row = $dbr->fetchObject ( $res );
		return ceil ( $row->count ); // Paranoia
	}

	/**
	 * Get a list of titles on a user's simplefavoritelist, excluding talk pages,
	 * and return as a two-dimensional array with namespace, title and
	 * redirect status
	 *
	 * @param $user User
	 * @return array
	 */
	private function getSimpleFavoritelistInfo($user) {
		$titles = array ();
		$dbr = wfGetDB ( DB_MASTER );
		$uid = intval ( $user->getId () );
		list ( $simplefavoritelist, $page ) = $dbr->tableNamesN ( 'simplefavoritelist', 'page' );
		$sql = "SELECT fl_namespace, fl_title, page_id, page_len, page_is_redirect
			FROM {$simplefavoritelist} LEFT JOIN {$page} ON ( fl_namespace = page_namespace
			AND fl_title = page_title ) WHERE fl_user = {$uid}";
		$res = $dbr->query ( $sql, __METHOD__ );
		if ($res && $dbr->numRows ( $res ) > 0) {
			$cache = LinkCache::singleton ();
			while ( $row = $dbr->fetchObject ( $res ) ) {
				$title = Title::makeTitleSafe ( $row->fl_namespace, $row->fl_title );
				if ($title instanceof Title) {
					// Update the link cache while we're at it
					if ($row->page_id) {
						$cache->addGoodLinkObj ( $row->page_id, $title, $row->page_len, $row->page_is_redirect );
					} else {
						$cache->addBadLinkObj ( $title );
					}
					// Ignore non-talk
					if (! $title->isTalkPage ())
						$titles [$row->fl_namespace] [$row->fl_title] = $row->page_is_redirect;
				}
			}
		}
		return $titles;
	}


	/**
	 * Remove a list of titles from a user's simplefavoritelist
	 *
	 * $titles can be an array of strings or Title objects; the former
	 * is preferred, since Titles are very memory-heavy
	 *
	 * @param $titles An
	 *        	array of strings, or Title objects
	 * @param $user User
	 */
	private function unsimplefavoriteTitles($titles, $user) {
		$dbw = wfGetDB ( DB_MASTER );

		foreach ( $titles as $title ) {

			if (! $title instanceof Title)
				$title = Title::newFromText ( $title );
			if ($title instanceof Title) {

				$dbw->delete ( 'simplefavoritelist', array (
						'fl_user' => $user->getId (),
						'fl_namespace' => ($title->getNamespace () | 1),
						'fl_title' => $title->getDBkey ()
				), __METHOD__ );
				$article = new Article ( $title );
				Hooks::run ( 'UnsimplefavoriteArticleComplete', array (
						&$user,
						&$article
				) );
			}
		}
	}

	/**
	 * Show the standard simplefavoritelist editing form
	 *
	 * @param $output OutputPage
	 * @param $user User
	 */
	private function showNormalForm($output, $user) {

		if (($count = $this->countSimpleFavoritelist ( $user )) > 0) {
			$self = SpecialPage::getTitleFor ( 'SimpleFavoritelist' );
			$output->addHTML ( $this->buildRemoveList ( $user ) );
		}
	}

	private function buildRemoveList($user) {
		$list = "";
		$tocLength = 0;
		foreach ( $this->getSimpleFavoritelistInfo ( $user ) as $namespace => $pages ) {
			$tocLength ++;
			$anchor = "editsimplefavoritelist-ns" . $namespace;

			$list .= "<ul>\n";
			foreach ( $pages as $dbkey => $redirect ) {
				$title = Title::makeTitleSafe ( $namespace, $dbkey );
				$list .= $this->buildRemoveLine ( $title );
			}
			$list .= "</ul>\n";
		}

		return $list;
	}

	private function buildRemoveLine($title) {
		$link = Linker::link ( $title );
		return "<li>" . $link . "</li>\n";
	}
}
