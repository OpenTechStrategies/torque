<?php
  
class TeamCommentsList extends SpecialPage {

  public function __construct() {
    parent::__construct( 'TeamCommentsList' );
  }

  public function execute( $subPage ) {
    $out = $this->getOutput();
    $out->addModuleStyles( 'ext.teamcomments.css' );
    $out->setPageTitle(wfMessage("teamcomments-special-teamcommentlist"));

    $dbr = wfGetDB( DB_REPLICA );
    $res = $dbr->select(
      'teamcomments',
      [ 'distinct(teamComment_page_id)', 'max(teamcomment_date) as tcd'],
      '',
      __METHOD__,
      ['GROUP BY' => 'teamComment_page_id', "ORDER BY" => "tcd DESC"]
    );

    $context = new RequestContext;
    $context->setTitle( $this->getTitle() );
    $context->setRequest( new FauxRequest() );
    $context->setUser( $this->getUser() );

    $out->addWikiText("__TOC__");
    $out->addHtml("<div class='special-teamcomments-list'>");
    foreach($res as $row) {
      $id = $row->teamComment_page_id;
      $title = Title::newFromId($id);
      $out->addWikiText("= [[" . $title->getFullText() . "]] =");
      $teamcommentsPage = new TeamCommentsPage($id, $context);

      $output = '<div class="teamcomments-body">';
      $output .= '<div id="allteamcomments">' . $teamcommentsPage->display() . '</div>';
      $output .= '</div>';

      $out->addHtml($output);
    }
    $out->addHtml("</div>");
  }
}

