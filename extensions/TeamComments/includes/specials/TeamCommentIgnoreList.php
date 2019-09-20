<?php
/**
 * A special page for displaying the list of users whose teamcomments you're
 * ignoring.
 * @file
 * @ingroup Extensions
 */
class TeamCommentIgnoreList extends SpecialPage {

	/**
	 * Constructor -- set up the new special page
	 */
	public function __construct() {
		parent::__construct( 'TeamCommentIgnoreList' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Group this special page under the correct header in Special:SpecialPages.
	 *
	 * @return string
	 */
	function getGroupName() {
		return 'users';
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page or null
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();
		$user = $this->getUser();

		$user_name = $request->getVal( 'user' );

		/**
		 * Redirect anonymous users to Login Page
		 * It will automatically return them to the TeamCommentIgnoreList page
		 */
		if ( $user->getId() == 0 && $user_name == '' ) {
			$loginPage = SpecialPage::getTitleFor( 'Userlogin' );
			$out->redirect( $loginPage->getLocalURL( 'returnto=Special:TeamCommentIgnoreList' ) );
			return;
		}

		$out->setPageTitle( $this->msg( 'teamcomments-ignore-title' )->text() );

		$output = ''; // Prevent E_NOTICE

		if ( $user_name == '' ) {
			$output .= $this->displayTeamCommentBlockList();
		} else {
			if ( $request->wasPosted() ) {
				// Check for cross-site request forgeries (CSRF)
				if ( !$user->matchEditToken( $request->getVal( 'token' ) ) ) {
					$out->addWikiMsg( 'sessionfailure' );
					return;
				}
				$user_name = htmlspecialchars_decode( $user_name );
				$user_id = User::idFromName( $user_name );
				// Anons can be teamcomment-blocked, but idFromName returns nothing
				// for an anon, so...
				if ( !$user_id ) {
					$user_id = 0;
				}

				TeamCommentFunctions::deleteBlock( $user->getId(), $user_id );
				if ( $user_id && class_exists( 'UserStatsTrack' ) ) {
					$stats = new UserStatsTrack( $user_id, $user_name );
					$stats->decStatField( 'teamcomment_ignored' );
				}
				$output .= $this->displayTeamCommentBlockList();
			} else {
				$output .= $this->confirmTeamCommentBlockDelete();
			}
		}

		$out->addHTML( $output );
	}

	/**
	 * Displays the list of users whose teamcomments you're ignoring.
	 *
	 * @return string HTML
	 */
	function displayTeamCommentBlockList() {
		$lang = $this->getLanguage();
		$title = $this->getPageTitle();

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'TeamComments_block',
			[ 'cb_user_name_blocked', 'cb_date' ],
			[ 'cb_user_id' => $this->getUser()->getId() ],
			__METHOD__,
			[ 'ORDER BY' => 'cb_user_name' ]
		);

		if ( $dbr->numRows( $res ) > 0 ) {
			$out = '<ul>';
			foreach ( $res as $row ) {
				$user_title = Title::makeTitle( NS_USER, $row->cb_user_name_blocked );
				$out .= '<li>' . $this->msg(
					'teamcomments-ignore-item',
					htmlspecialchars( $user_title->getFullURL() ),
					$user_title->getText(),
					$lang->timeanddate( $row->cb_date ),
					htmlspecialchars( $title->getFullURL( 'user=' . $user_title->getText() ) )
				)->text() . '</li>';
			}
			$out .= '</ul>';
		} else {
			$out = '<div class="teamcomment_blocked_user">' .
				$this->msg( 'teamcomments-ignore-no-users' )->text() . '</div>';
		}
		return $out;
	}

	/**
	 * Asks for a confirmation when you're about to unblock someone's teamcomments.
	 *
	 * @return string HTML
	 */
	function confirmTeamCommentBlockDelete() {
		$user_name = $this->getRequest()->getVal( 'user' );

		$out = '<div class="teamcomment_blocked_user">' .
				$this->msg( 'teamcomments-ignore-remove-message', $user_name )->parse() .
			'</div>
			<div>
				<form action="" method="post" name="teamcomment_block">' .
					Html::hidden( 'user', $user_name ) . "\n" .
					Html::hidden( 'token', $this->getUser()->getEditToken() ) . "\n" .
					'<input type="button" class="site-button" value="' . $this->msg( 'teamcomments-ignore-unblock' )->text() . '" onclick="document.teamcomment_block.submit()" />
					<input type="button" class="site-button" value="' . $this->msg( 'teamcomments-ignore-cancel' )->text() . '" onclick="history.go(-1)" />
				</form>
			</div>';
		return $out;
	}
}
