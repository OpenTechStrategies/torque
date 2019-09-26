<?php

class TeamCommentNumNewAPI extends ApiBase {

  public function execute() {
    // To avoid API warning, register the parameter used to bust browser cache
    $this->getMain()->getVal( '_' );

    $pageID = $this->getMain()->getVal( 'pageID' );
    $latestID = $this->getMain()->getVal( 'latestID' );

    $teamcommentsPage = new TeamCommentsPage( $pageID, RequestContext::getMain() );

    $result = $this->getResult();
    $result->addValue( $this->getModuleName(), 'numnew', $teamcommentsPage->getNumCommentsSinceID($latestID) );
  }

  public function getAllowedParams() {
    return [
      'pageID' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ],
      'latestID' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ]
    ];
  }
}
