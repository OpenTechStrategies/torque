<?php

/**
 * API module to allow users to simplefavorite a page
 *
 * @ingroup API
 */
class ApiSimpleFavorite extends ApiBase {

	public function __construct( $main, $action ) {
		parent::__construct( $main, $action );
	}

	public function execute() {
		$user = $this->getUser();
		if ( !$user->isLoggedIn() ) {
			$this->dieUsage( 'You must be logged-in to have a simplefavoritelist', 'notloggedin' );
		}

		$params = $this->extractRequestParams();
		$title = Title::newFromText( $params['title'] );

		if ( !$title || $title->getNamespace() < 0 ) {
			$this->dieUsageMsg( array( 'invalidtitle', $params['title'] ) );
		}

		$res = array( 'title' => $title->getPrefixedText() );

		if ( $params['unsimplefavorite'] ) {
			$res['unsimplefavorited'] = '';
			$res['message'] = $this->msg( 'removedsimplefavoritetext', $title->getPrefixedText() )->title( $title )->parseAsBlock();
			$success = new SimpleFavoriteAction('unsimplefavorite',$title);
			//$success = UnsimplefavoriteAction::doUnsimplefavorite( $title, $user );
		} else {
			$res['simplefavorited'] = '';
			$res['message'] = $this->msg( 'addedsimplefavoritetext', $title->getPrefixedText() )->title( $title )->parseAsBlock();
			$success = new SimpleFavoriteAction('simplefavorite',$title);
			//$success = FavAction::doSimpleFavorite( $title, $user );
		}
		if ( !$success ) {
			$this->dieUsageMsg( 'hookaborted' );
		}
		$this->getResult()->addValue( null, $this->getModuleName(), $res );

	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	// since this makes changes the database, we should use this, but I just can't get it to work.
 	//public function needsToken() {
 	//	return 'simplefavorite';
 	//}

	//public function getTokenSalt() {
	//	return 'simplefavorite';
	//}

	public function getAllowedParams() {
		return array(
			'title' => array(
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			),
			'unsimplefavorite' => false,
 			//'token' => array(
 			//	ApiBase::PARAM_TYPE => 'string',
 			//	ApiBase::PARAM_REQUIRED => true
			//),
		);
	}

	public function getParamDescription() {
		return array(
			'title' => 'The page to (un)simplefavorite',
			'unsimplefavorite' => 'If set the page will be unsimplefavorited rather than simplefavorited',
			'token' => 'A token previously acquired via prop=info',
		);
	}

	public function getDescription() {
		return 'Add or remove a page from/to the current user\'s simplefavoritelist';
	}

	public function getExamples() {
		return array(
			'api.php?action=simplefavorite&title=Main_Page' => 'SimpleFavorite the page "Main Page"',
			'api.php?action=simplefavorite&title=Main_Page&unsimplefavorite=' => 'Unsimplefavorite the page "Main Page"',
		);
	}

	public function getHelpUrls() {
		return 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:SimpleFavorites';
	}

	public static function getTokenFav() {
		global $wgUser;

		return $wgUser->getEditToken( 'simplefavorite' );
	}
	public static function getTokenUnfav() {
		global $wgUser;

		return $wgUser->getEditToken( 'unsimplefavorite' );
	}

	public static function injectTokenFunction( &$list ) {
		$list['simplefavorite'] = array( __CLASS__, 'getTokenFav' );
		$list['unsimplefavorite'] = array( __CLASS__, 'getTokenUnfav' );
		return true; // Hooks must return bool
	}
}
