<?php

class TeamCommentLatestIdAPI extends ApiBase {

  public function execute() {
    // To avoid API warning, register the parameter used to bust browser cache
    $this->getMain()->getVal( '_' );

    $pageID = $this->getMain()->getVal( 'pageID' );

    $teamcommentsPage = new TeamCommentsPage( $pageID, RequestContext::getMain() );

    $result = $this->getResult();
    $result->addValue( $this->getModuleName(), 'id', $teamcommentsPage->getLatestTeamCommentID() );
  }

  public function getAllowedParams() {
    return [
      'pageID' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ]
    ];
  }
}
