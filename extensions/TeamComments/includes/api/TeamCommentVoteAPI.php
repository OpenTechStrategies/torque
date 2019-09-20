<?php

class TeamCommentVoteAPI extends ApiBase {

	public function execute() {
		$user = $this->getUser();
		// Blocked users cannot vote, obviously, and neither can those users without the necessary privileges
		if (
			$user->isBlocked() ||
			!$user->isAllowed( 'teamcomment' ) ||
			wfReadOnly()
		) {
			return '';
		}

		$teamcomment = TeamComment::newFromID( $this->getMain()->getVal( 'teamcommentID' ) );
		$voteValue = $this->getMain()->getVal( 'voteValue' );

		if ( $teamcomment && is_numeric( $voteValue ) ) {
			$teamcomment->vote( $voteValue );

			$html = $teamcomment->getScoreHTML();
			$html = htmlspecialchars( $html );

			if ( class_exists( 'UserStatsTrack' ) ) {
				$stats = new UserStatsTrack( $user->getId(), $user->getName() );

				// Must update stats for user doing the voting
				if ( $voteValue == 1 ) {
					$stats->incStatField( 'teamcomment_give_plus' );
				}
				if ( $voteValue == -1 ) {
					$stats->incStatField( 'teamcomment_give_neg' );
				}

				// Also must update the stats for user receiving the vote
				$stats_teamcomment_owner = new UserStatsTrack( $teamcomment->userID, $teamcomment->username );
				$stats_teamcomment_owner->updateTeamCommentScoreRec( $voteValue );

				$stats_teamcomment_owner->updateTotalPoints();
				if ( $voteValue === 1 ) {
					$stats_teamcomment_owner->updateWeeklyPoints( $stats_teamcomment_owner->point_values['teamcomment_plus'] );
					$stats_teamcomment_owner->updateMonthlyPoints( $stats_teamcomment_owner->point_values['teamcomment_plus'] );
				}
			}

			$result = $this->getResult();
			$result->addValue( $this->getModuleName(), 'html', $html );
			return true;
		}
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
			],
			'voteValue' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'integer'
			],
		];
	}
}
