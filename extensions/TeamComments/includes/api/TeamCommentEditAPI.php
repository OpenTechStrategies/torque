<?php

class TeamCommentEditAPI extends ApiBase {

  public function execute() {
    $user = $this->getUser();

    $teamcomment = TeamComment::newFromID($this->getMain()->getVal('teamcommentID'));

    if (! $teamcomment->isOwner($user) || wfReadOnly()) {
      return true;
    }

    $teamcomment->edit($this->getMain()->getVal('teamcommentText'));

    $result = $this->getResult();
    $result->addValue($this->getModuleName(), 'newFormattedText', $teamcomment->getText());
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
      ],
      'teamcommentText' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'string'
      ]
    ];
  }
}
