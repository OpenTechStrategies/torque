<?php

class TeamCommentSubmitAPI extends ApiBase {

  public function execute() {
    $user = $this->getUser();
    // Blocked users cannot submit new teamcomments, and neither can those users
    // without the necessary privileges. Also prevent obvious cross-site request
    // forgeries (CSRF)
    if (
      $user->isBlocked() ||
      !$user->isLoggedIn() ||
      !$user->isAllowed( 'teamcomment' ) ||
      wfReadOnly()
    ) {
      return true;
    }

    $teamcommentText = $this->getMain()->getVal( 'teamcommentText' );

    if ( $teamcommentText != '' ) {
      // To protect against spam, it's necessary to check the supplied text
      // against spam filters (but teamcomment admins are allowed to bypass the
      // spam filters)
      if ( !$user->isAllowed( 'teamcommentadmin' ) && TeamCommentFunctions::isSpam( $teamcommentText ) ) {
        $this->dieWithError(
          $this->msg( 'teamcomments-is-spam' )->plain(),
          'teamcomments-is-spam'
        );
      }

      // If the teamcomment contains links but the user isn't allowed to post
      // links, reject the submission
      if ( !$user->isAllowed( 'teamcommentlinks' ) && TeamCommentFunctions::haveLinks( $teamcommentText ) ) {
        $this->dieWithError(
          $this->msg( 'teamcomments-links-are-forbidden' )->plain(),
          'teamcomments-links-are-forbidden'
        );
      }

      $page = new TeamCommentsPage( $this->getMain()->getVal( 'pageID' ), $this->getContext() );

      TeamComment::add( $teamcommentText, $page, $user, $this->getMain()->getVal( 'parentID' ) );

      if ( class_exists( 'UserStatsTrack' ) ) {
        $stats = new UserStatsTrack( $user->getId(), $user->getName() );
        $stats->incStatField( 'teamcomment' );
      }
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
      'pageID' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ],
      'parentID' => [
        ApiBase::PARAM_REQUIRED => false,
        ApiBase::PARAM_TYPE => 'integer'
      ],
      'teamcommentText' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'string'
      ]
    ];
  }
}
