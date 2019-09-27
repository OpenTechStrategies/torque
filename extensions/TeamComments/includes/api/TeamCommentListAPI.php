<?php

class TeamCommentListAPI extends ApiBase {

  public function execute() {
    $teamcommentsPage = new TeamCommentsPage( $this->getMain()->getVal( 'pageID' ), RequestContext::getMain() );
    $teamcommentsPage->currentPagerPage = $this->getMain()->getVal( 'pagerPage' );

    $output = '';
    if ( $this->getMain()->getVal( 'showForm' ) ) {
      $output .= $teamcommentsPage->displayHeader();
    }
    $output .= $teamcommentsPage->display();
    if ( $this->getMain()->getVal( 'showForm' ) ) {
      $output .= $teamcommentsPage->displayForm();
    }

    $result = $this->getResult();
    $result->addValue( $this->getModuleName(), 'html', $output );
    $result->addValue( $this->getModuleName(), 'latestCommentID', $teamcommentsPage->getLatestTeamCommentID() );
    return true;
  }

  public function getAllowedParams() {
    return [
      'pageID' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ],
      'pagerPage' => [
        ApiBase::PARAM_REQUIRED => true,
        ApiBase::PARAM_TYPE => 'integer'
      ],
      'showForm' => [
        ApiBase::PARAM_REQUIRED => false,
        ApiBase::PARAM_TYPE => 'integer'
      ]
    ];
  }
}
