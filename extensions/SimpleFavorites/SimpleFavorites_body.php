<?php
class SimpleFavorites {
	var $user;
	function simplefavoritesLinks(&$sktemplate, &$links) {
		$this->user = $user = $sktemplate->getUser ();
		if ($user->isAnon ()) {
			// do nothing
			return false;
		}
		
		$title = $sktemplate->getTitle ();
		
		// See if this object even exists - if the user can't read it, the object doesn't get created.
		if (is_object ( $title )) {
			$ns = $title->getNamespace ();
			$titleKey = $title->getDBkey ();
		} else {
			return false;
		}
		$mode = $this->inSimpleFavorites ( $ns, $titleKey ) ? 'unsimplefavorite' : 'simplefavorite';

		$class = 'simplefavorite-icon icon ';
		$place = 'views';
		$text = '';
		
		$token = $this->getSimpleFavoriteToken ( $title, $user, $mode );
		
		$links [$place] [$mode] = array (
				'class' => $class,
				'text' => $text, // uses 'simplefavorite' or 'unsimplefavorite' message
				                 // 'href' => $this->getTitle()->getLocalURL( array( 'action' => $mode) ) //'href' => $favTitle->getLocalUrl( 'action=' . $mode )
				'href' => $title->getLocalURL ( array (
						'action' => $mode,
						'token' => $token 
				) ) 
		);
		
		return false;
	}
	
	/**
	 * Is this item in the user's simplefavorite list?
	 */
	private function inSimpleFavorites($ns, $titleKey) {
		$dbr = wfGetDB ( DB_REPLICA );
		$res = $dbr->select ( 'simplefavoritelist', 1, array (
				'fl_user' => $this->user->getId (),
				'fl_namespace' => $ns,
				'fl_title' => $titleKey 
		), __METHOD__ );
		$issimplefavorited = ($dbr->numRows ( $res ) > 0) ? true : false;
		return $issimplefavorited;
	}
	
	/**
	 * Get token to simplefavorite (or unsimplefavorite) a page for a user
	 *
	 * @param Title $title
	 *        	Title object of page to simplefavorite
	 * @param User $user
	 *        	User for whom the action is going to be performed
	 * @param string $action
	 *        	Optionally override the action to 'unsimplefavorite'
	 * @return string Token
	 */
	function getSimpleFavoriteToken(Title $title, User $user, $action = 'simplefavorite') {
		if ($action != 'unsimplefavorite') {
			$action = 'simplefavorite';
		}
		$salt = array (
				$action,
				$title->getDBkey () 
		);
		
		// This token stronger salted and not compatible with ApiSimpleFavorite
		// It's title/action specific because index.php is GET and API is POST
		return $user->getEditToken ( $salt );
	}
	
	/**
	 * Get token to unsimplefavorite (or simplefavorite) a page for a user
	 *
	 * @param Title $title
	 *        	Title object of page to unsimplefavorite
	 * @param User $user
	 *        	User for whom the action is going to be performed
	 * @param string $action
	 *        	Optionally override the action to 'simplefavorite'
	 * @return string Token
	 */
	function getUnsimplefavoriteToken(Title $title, User $user, $action = 'unsimplefavorite') {
		return self::getSimpleFavoriteToken ( $title, $user, $action );
	}
	
	/**
	 * Check if the given title already is simplefavorited by the user, and if so
	 * add simplefavorite on a new title.
	 * To be used for page renames and such.
	 *
	 * @param $ot Title:
	 *        	page title to duplicate entries from, if present
	 * @param $nt Title:
	 *        	page title to add simplefavorite on
	 */
	public static function duplicateEntries($ot, $nt) {
		SimpleFavorites::doDuplicateEntries ( $ot->getSubjectPage (), $nt->getSubjectPage () );
	}
	
	/**
	 * Handle duplicate entries.
	 * Backend for duplicateEntries().
	 */
	private static function doDuplicateEntries($ot, $nt) {
		$oldnamespace = $ot->getNamespace ();
		$newnamespace = $nt->getNamespace ();
		$oldtitle = $ot->getDBkey ();
		$newtitle = $nt->getDBkey ();
		
		$dbw = wfGetDB ( DB_MASTER );
		$res = $dbw->select ( 'simplefavoritelist', 'fl_user', array (
				'fl_namespace' => $oldnamespace,
				'fl_title' => $oldtitle 
		), __METHOD__, 'FOR UPDATE' );
		// Construct array to replace into the simplefavoritelist
		$values = array ();
		while ( $s = $dbw->fetchObject ( $res ) ) {
			$values [] = array (
					'fl_user' => $s->fl_user,
					'fl_namespace' => $newnamespace,
					'fl_title' => $newtitle 
			);
		}
		$dbw->freeResult ( $res );
		
		if (empty ( $values )) {
			// Nothing to do
			return true;
		}
		
		// Perform replace
		// Note that multi-row replace is very efficient for MySQL but may be inefficient for
		// some other DBMSes, mostly due to poor simulation by us
		$dbw->replace ( 'simplefavoritelist', array (
				array (
						'fl_user',
						'fl_namespace',
						'fl_title' 
				) 
		), $values, __METHOD__ );
		
		// Delete the old item - we don't need to have the old page on the list of simplefavorites.
		$dbw->delete ( 'simplefavoritelist', array (
				'fl_namespace' => $oldnamespace,
				'fl_title' => $oldtitle 
		), $fname = 'Database::delete' );
		return true;
	}
}


