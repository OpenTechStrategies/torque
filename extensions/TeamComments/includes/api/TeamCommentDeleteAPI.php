<?php

class TeamCommentDeleteAPI extends ApiBase {

  public function execute() {
    $user = $this->getUser();

    $teamcomment = TeamComment::newFromID( $this->getMain()->getVal( 'teamcommentID' ) );
    // Blocked users cannot delete teamcomments, and neither can unprivileged ones.
    // Also check for database read-only status
    if (! $teamcomment->isOwner($user) || wfReadOnly()) {
      return true;
    }

    $teamcomment->delete();

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
