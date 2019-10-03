<?php

class SimpleFavoriteAction {

	function __construct($action,$title,$article = null) {
		$user = User::newFromSession();
		
		if ($article) {
			$output = $article->getContext()->getOutput();
		} else {
			$output = false;
		}
		
		if ($action == 'simplefavorite') {
			$result = $this->doSimpleFavorite($title, $user);
			$message = 'addedsimplefavoritetext';
		} else {
			$result = $this->doUnsimplefavorite($title, $user);
			$message = 'removedsimplefavoritetext';
		}
		
		if ($result == true) {
			if ($output) {
				// don't do this if we are calling from the API
				$output->addWikiMsg( $message, $title->getPrefixedText() );
			}
			$user->invalidateCache();
			return true;
		} else {
			if ($output) {
				// don't do this if we are calling from the API
				$output->addWikiMsg( 'simplefavoriteerrortext', $title->getPrefixedText() );
			}
			return false;
		}
		
		
	}
	
	function doSimpleFavorite( Title $title, User $user  ) {
		$success = false;
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'simplefavoritelist',
				array(
						'fl_user' => $user->getId(),
						'fl_namespace' => MWNamespace::getSubject($title->getNamespace()),
						'fl_title' => $title->getDBkey(),
						'fl_notificationtimestamp' => null
				), __METHOD__, 'IGNORE' );
		
			if ( $dbw->affectedRows() > 0 ) {
			$success = true;
		}
		return $success;
	}
	
	function doUnsimplefavorite( Title $title, User $user  ) {
		$success = false;
		
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'simplefavoritelist',
				array(
						'fl_user' => $user->getId(),
						'fl_namespace' => MWNamespace::getSubject($title->getNamespace()),
						'fl_title' => $title->getDBkey()
				), __METHOD__
		);
		
		if ( $dbw->affectedRows() > 0) {
			$success = true;
		} 
		
		return $success;
	}
	

	
	
}



