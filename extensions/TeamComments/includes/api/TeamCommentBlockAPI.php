<?php

class TeamCommentBlockAPI extends ApiBase {

	public function execute() {
		// Do nothing when the database is in read-only mode
		if ( wfReadOnly() ) {
			return true;
		}

		// Load user_name and user_id for person we want to block from the teamcomment it originated from
		$dbr = wfGetDB( DB_REPLICA );
		$s = $dbr->selectRow(
			'TeamComments',
			[ 'teamcomment_username', 'teamcomment_user_id' ],
			[ 'TeamCommentID' => $this->getMain()->getVal( 'teamcommentID' ) ],
			__METHOD__
		);
		if ( $s !== false ) {
			$userID = $s->teamcomment_user_id;
			$username = $s->teamcomment_username;
		}

		TeamCommentFunctions::blockUser( $this->getUser(), $userID, $username );

		if ( class_exists( 'UserStatsTrack' ) ) {
			$stats = new UserStatsTrack( $userID, $username );
			$stats->incStatField( 'teamcomment_ignored' );
		}

		$result = $this->getResult();
		$result->addValue( $this->getModuleName(), 'ok', 'ok' );
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function isWriteMode() {
		return true;
	}

	public function getAllowedParams() {
		return [
			'teamcommentID' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'integer'
			]
		];
	}
}
