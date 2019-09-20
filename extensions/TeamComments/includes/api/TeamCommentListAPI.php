<?php

class TeamCommentListAPI extends ApiBase {

	public function execute() {
		$teamcommentsPage = new TeamCommentsPage( $this->getMain()->getVal( 'pageID' ), RequestContext::getMain() );
		$teamcommentsPage->orderBy = $this->getMain()->getVal( 'order' );
		$teamcommentsPage->currentPagerPage = $this->getMain()->getVal( 'pagerPage' );

		$output = '';
		if ( $this->getMain()->getVal( 'showForm' ) ) {
			$output .= $teamcommentsPage->displayOrderForm();
		}
		$output .= $teamcommentsPage->display();
		if ( $this->getMain()->getVal( 'showForm' ) ) {
			$output .= $teamcommentsPage->displayForm();
		}

		$result = $this->getResult();
		$result->addValue( $this->getModuleName(), 'html', $output );
		return true;
	}

	public function getAllowedParams() {
		return [
			'pageID' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'integer'
			],
			'order' => [
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_TYPE => 'boolean'
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
