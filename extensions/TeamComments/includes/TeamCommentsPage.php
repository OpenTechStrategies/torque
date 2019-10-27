<?php

/**
 * Class for TeamComments methods that are not specific to one teamcomments,
 * but specific to one teamcomment-using page
 */
class TeamCommentsPage extends ContextSource {

  /**
   * @var Integer: page ID (page.page_id) of this page.
   */
  public $id = 0;

  /**
   * List of users allowed to teamcomment. Empty string - any user can teamcomment
   *
   * @var string
   */
  public $allow = '';

  /**
   * @var Title: title object for this page
   */
  public $title = null;

  /**
   * Constructor
   *
   * @param $pageID: current page ID
   */
  function __construct( $pageID, $context ) {
    $this->id = $pageID;
    $this->setContext( $context );
    $this->title = Title::newFromID( $pageID );
  }

  /**
   * Gets the ID number of the latest teamcomment for the current page.
   *
   * @return int
   */
  function getLatestTeamCommentID() {
    $latestTeamCommentID = 0;
    $dbr = wfGetDB( DB_REPLICA );
    $s = $dbr->selectRow(
      'teamcomments',
      [ 'teamcomment_id' ],
      [ 'teamComment_page_id' => $this->id ],
      __METHOD__,
      [ 'ORDER BY' => 'teamcomment_date DESC', 'LIMIT' => 1 ]
    );
    if ( $s !== false ) {
      $latestTeamCommentID = $s->teamcomment_id;
    }
    return $latestTeamCommentID;
  }

  /**
   * Get number of comments since a given id
   */
  function getNumCommentsSinceID($latestID)  {
    $dbr = wfGetDB( DB_REPLICA );
    $s = $dbr->select(
      'teamcomments',
      [ 'teamcomment_id' ],
      [ 'teamComment_page_id' => $this->id, 'teamcomment_id > '. $latestID]
    );

    return $s->numRows();
  }

  public static function findThreadId($teamcomment, $teamcomments) {
    if($teamcomment->parentID == 0) {
      return $teamcomment->id;
    } else {
      return TeamCommentsPage::findThreadId($teamcomments[$teamcomment->parentID], $teamcomments);
    }
  }

  /**
   * Fetches all teamcomments, called by display().
   *
   * @return array Array containing every possible bit of information about
   *         a teamcomment, including timestamp and more
   */
  public function getTeamComments() {
    $dbr = wfGetDB( DB_REPLICA );

    // Defaults (for non-social wikis)
    $tables = [
      'teamcomments'
    ];
    $fields = [
      'teamcomment_username', 'teamcomment_ip', 'teamcomment_text',
      'teamcomment_date', 'teamcomment_date AS timestamp',
      'teamcomment_user_id', 'teamcomment_id', 'teamcomment_parent_id',
      'teamcomment_deleted', 'teamcomment_date_lastedited'
    ];
    $params = [ 'ORDER BY' => 'teamcomment_date' ];

    // Perform the query
    $res = $dbr->select(
      $tables,
      $fields,
      [ 'teamcomment_page_id' => $this->id ],
      __METHOD__,
      $params
    );

    $teamcomments = [];

    foreach ( $res as $row ) {
      $data = [
        'teamcomment_username' => $row->teamcomment_username,
        'teamcomment_ip' => $row->teamcomment_ip,
        'teamcomment_text' => $row->teamcomment_text,
        'teamcomment_date' => $row->teamcomment_date,
        'teamcomment_user_id' => $row->teamcomment_user_id,
        'teamcomment_id' => $row->teamcomment_id,
        'teamcomment_parent_id' => $row->teamcomment_parent_id,
        'teamcomment_deleted' => $row->teamcomment_deleted,
        'teamcomment_date_lastedited' => $row->teamcomment_date_lastedited,
        'timestamp' => wfTimestamp( TS_UNIX, $row->timestamp )
      ];

      $teamcomments[$row->teamcomment_id] = new TeamComment( $this, $this->getContext(), $data );
    }

    $teamcommentThreads = [];

    foreach ( $teamcomments as $teamcomment ) {
      $threadid = TeamCommentsPage::findThreadId($teamcomment, $teamcomments);

      if(!array_key_exists($threadid, $teamcommentThreads)) {
        $teamcommentThreads[$threadid] = [];
      }
      $teamcommentThreads[$threadid][] = $teamcomment;
    }

    return $teamcommentThreads;
  }

  /**
   * Display all the teamcomments for the current page.
   * CSS and JS is loaded in TeamCommentsHooks.php
   */
  function display() {
    $output = '';

    $teamcommentThreads = $this->getTeamComments();

    $this->teamcomments = $teamcommentThreads;

    $output .= '<a id="cfirst" name="cfirst" rel="nofollow"></a>';

    foreach ( $teamcommentThreads as $thread ) {
      foreach ( $thread as $teamcomment ) {
        $output .= $teamcomment->display();
      }
    }

    return $output;
  }

  function displayHeader() {
    $output = '<h1>Comments</h1>';
    $output .= '<div class="teamcomments-refresh-banner-container" style="display:none;">';
    $output .= '<div class="teamcomments-refresh-banner">' .
      '<span id="teamcomments-number-of-comments">0</span> '.
      wfMessage('teamcomments-banner') .
      ' (<a href="javascript:void(0);" rel="nofollow" class="teamcomments-banner-refresh">'.
      wfMessage('teamcomments-refresh') .
      '</a>)</div></div>';


    return $output;
  }

  /**
   * Displays the form for adding new teamcomments
   *
   * @return string HTML output
   */
  function displayForm() {
    global $wgTeamCommentsCheatSheetLocation;
    $output = '<form action="" method="post" name="teamcommentForm">' . "\n";

    if ( $this->allow ) {
      $pos = strpos(
        strtoupper( addslashes( $this->allow ) ),
        strtoupper( addslashes( $this->getUser()->getName() ) )
      );
    }

    // 'teamcomment' user right is required to add new teamcomments
    if ( !$this->getUser()->isAllowed( 'teamcomment' ) ) {
      $output .= wfMessage( 'teamcomments-not-allowed' )->parse();
    } else {
      // Blocked users can't add new teamcomments under any conditions...
      // and maybe there's a list of users who should be allowed to post
      // teamcomments
      if ( $this->getUser()->isBlocked() == false && ( $this->allow == '' || $pos !== false ) ) {
        $output .= '<div class="c-form-title">';
        $output .= wfMessage( 'teamcomments-submit' )->plain();

        if($wgTeamCommentsCheatSheetLocation) {
          $output .= " <span class='c-cheatsheet'>(<a href='";
          $output .= $wgTeamCommentsCheatSheetLocation;
          $output .= "'>";
          $output .= wfMessage('teamcomments-cheatsheet')->plain();
          $output .= "</a>)</span>";
        }
        $output .= "</div>\n";
        $output .= '<div id="replyto" class="c-form-reply-to"></div>' . "\n";
        // Show a message to anons, prompting them to register or log in
        if ( !$this->getUser()->isLoggedIn() ) {
          $login_title = SpecialPage::getTitleFor( 'Userlogin' );
          $register_title = SpecialPage::getTitleFor( 'Userlogin', 'signup' );
          $output .= '<div class="c-form-message">' . wfMessage(
              'teamcomments-anon-message-login-required',
              htmlspecialchars( $register_title->getFullURL() ),
              htmlspecialchars( $login_title->getFullURL() )
            )->text() . '</div>' . "\n";
        }

        if ( $this->getUser()->isLoggedIn() ) {
          $output .= '<label><span class="teamcomments-hiddenlabel">Comment</span>' . "\n";
          $output .= '<textarea name="teamcommentText" id="teamcomment"></textarea>' . "\n";
          $output .= "</label>\n";
          $output .= '<div class="c-form-button"><input type="button" value="' .
            wfMessage( 'teamcomments-post' )->plain() . '" class="site-button" /></div>' . "\n";
        }
      }
      $output .= '<input type="hidden" name="action" value="purge" />' . "\n";
      $output .= '<input type="hidden" name="pageId" value="' . $this->id . '" />' . "\n";
      $output .= '<input type="hidden" name="teamcommentid" />' . "\n";
      $output .= '<input type="hidden" name="lastTeamCommentId" value="' . $this->getLatestTeamCommentID() . '" />' . "\n";
      $output .= '<input type="hidden" name="teamcommentParentId" />' . "\n";
      $output .= Html::hidden( 'token', $this->getUser()->getEditToken() );
    }
    $output .= '</form>' . "\n";
    return $output;
  }

  /**
   * Purge caches (parser cache and Squid cache)
   */
  function clearTeamCommentListCache() {
    wfDebug( "Clearing teamcomments for page {$this->id} from cache\n" );

    if ( is_object( $this->title ) ) {
      $this->title->invalidateCache();
      $this->title->purgeSquid();
    }
  }

}
