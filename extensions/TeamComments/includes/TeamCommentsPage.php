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
   * @var Integer: maximum amount of threads of teamcomments shown per page before pagination is enabled;
   */
  public $limit = 50;

  /**
   * @TODO document
   *
   * @var int
   */
  public $pagerLimit = 9;

  /**
   * The current page of teamcomments we are paged to
   *
   * @var int
   */
  public $currentPagerPage = 0;

  /**
   * List of users allowed to teamcomment. Empty string - any user can teamcomment
   *
   * @var string
   */
  public $allow = '';

  /**
   * @TODO document
   *
   * @var string
   */
  public $pageQuery = 'cpage';

  /**
   * @var Title: title object for this page
   */
  public $title = null;

  /**
   * List of lists of teamcomments on this page.
   * Each list is a separate 'thread' of teamcomments, with the parent teamcomment first, and any replies following
   * Not populated until display() is called
   *
   * @var array
   */
  public $teamcomments = [];

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
   * Gets the total amount of teamcomments on this page
   *
   * @return int
   */
  function countTotal() {
    $dbr = wfGetDB( DB_REPLICA );
    $count = 0;
    $s = $dbr->selectRow(
      'teamcomments',
      [ 'COUNT(*) AS TeamCommentCount' ],
      [ 'teamcomment_page_id' => $this->id ],
      __METHOD__
    );
    if ( $s !== false ) {
      $count = $s->TeamCommentCount;
    }
    return $count;
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
   * @return int The page we are currently paged to
   * not used for any API calls
   */
  function getCurrentPagerPage() {
    if ( $this->currentPagerPage == 0 ) {
      $this->currentPagerPage = $this->getRequest()->getInt( $this->pageQuery, 1 );

      if ( $this->currentPagerPage < 1 ) {
        $this->currentPagerPage = 1;
      }
    }

    return $this->currentPagerPage;
  }

  /**
   * Display pager for the current page.
   *
   * @param int $pagerCurrent Page we are currently paged to
   * @param int $pagesCount The maximum page number
   *
   * @return string the links for paging through pages of teamcomments
   */
  function displayPager( $pagerCurrent, $pagesCount ) {
    // Middle is used to "center" pages around the current page.
    $pager_middle = ceil( $this->pagerLimit / 2 );
    // first is the first page listed by this pager piece (re quantity)
    $pagerFirst = $pagerCurrent - $pager_middle + 1;
    // last is the last page listed by this pager piece (re quantity)
    $pagerLast = $pagerCurrent + $this->pagerLimit - $pager_middle;

    // Prepare for generation loop.
    $i = $pagerFirst;
    if ( $pagerLast > $pagesCount ) {
      // Adjust "center" if at end of query.
      $i = $i + ( $pagesCount - $pagerLast );
      $pagerLast = $pagesCount;
    }
    if ( $i <= 0 ) {
      // Adjust "center" if at start of query.
      $pagerLast = $pagerLast + ( 1 - $i );
      $i = 1;
    }

    $output = '';
    if ( $pagesCount > 1 ) {
      $output .= '<ul class="c-pager">';
      $pagerEllipsis = '<li class="c-pager-item c-pager-ellipsis"><span>...</span></li>';

      // Whether to display the "Previous page" link
      if ( $pagerCurrent > 1 ) {
        $output .= '<li class="c-pager-item c-pager-previous">' .
          Html::rawElement(
            'a',
            [
              'rel' => 'nofollow',
              'class' => 'c-pager-link',
              'href' => '#cfirst',
              'data-' . $this->pageQuery => ( $pagerCurrent - 1 ),
            ],
            '&lt;'
          ) .
          '</li>';
      }

      // Whether to display the "First page" link
      if ( $i > 1 ) {
        $output .= '<li class="c-pager-item c-pager-first">' .
          Html::rawElement(
            'a',
            [
              'rel' => 'nofollow',
              'class' => 'c-pager-link',
              'href' => '#cfirst',
              'data-' . $this->pageQuery => 1,
            ],
            1
          ) .
          '</li>';
      }

      // When there is more than one page, create the pager list.
      if ( $i != $pagesCount ) {
        if ( $i > 2 ) {
          $output .= $pagerEllipsis;
        }

        // Now generate the actual pager piece.
        for ( ; $i <= $pagerLast && $i <= $pagesCount; $i++ ) {
          if ( $i == $pagerCurrent ) {
            $output .= '<li class="c-pager-item c-pager-current"><span>' .
              $i . '</span></li>';
          } else {
            $output .= '<li class="c-pager-item">' .
              Html::rawElement(
                'a',
                [
                  'rel' => 'nofollow',
                  'class' => 'c-pager-link',
                  'href' => '#cfirst',
                  'data-' . $this->pageQuery => $i,
                ],
                $i
              ) .
              '</li>';
          }
        }

        if ( $i < $pagesCount ) {
          $output .= $pagerEllipsis;
        }
      }

      // Whether to display the "Last page" link
      if ( $pagesCount > ( $i - 1 ) ) {
        $output .= '<li class="c-pager-item c-pager-last">' .
          Html::rawElement(
            'a',
            [
              'rel' => 'nofollow',
              'class' => 'c-pager-link',
              'href' => '#cfirst',
              'data-' . $this->pageQuery => $pagesCount,
            ],
            $pagesCount
          ) .
          '</li>';
      }

      // Whether to display the "Next page" link
      if ( $pagerCurrent < $pagesCount ) {
        $output .= '<li class="c-pager-item c-pager-next">' .
          Html::rawElement(
            'a',
            [
              'rel' => 'nofollow',
              'class' => 'c-pager-link',
              'href' => '#cfirst',
              'data-' . $this->pageQuery => ( $pagerCurrent + 1 ),
            ],
            '&gt;'
          ) .
          '</li>';
      }

      $output .= '</ul>';
    }

    return $output;
  }

  /**
   * Convert an array of teamcomment threads into an array of pages (arrays) of teamcomment threads
   * @param $teamcomments
   * @return array
   */
  function page( $teamcomments ) {
    return array_chunk( $teamcomments, $this->limit );
  }

  /**
   * Display all the teamcomments for the current page.
   * CSS and JS is loaded in TeamCommentsHooks.php
   */
  function display() {
    $output = '';

    $teamcommentThreads = $this->getTeamComments();

    $this->teamcomments = $teamcommentThreads;

    $teamcommentPages = $this->page( $teamcommentThreads );
    $currentPageNum = $this->getCurrentPagerPage();
    $numPages = count( $teamcommentPages );
    // Suppress random E_NOTICE about "Undefined offset: 0", which seems to
    // be breaking ProblemReports (at least on my local devbox, not sure
    // about prod). --Jack Phoenix, 13 July 2015
    Wikimedia\suppressWarnings();
    $currentPage = $teamcommentPages[$currentPageNum - 1];
    Wikimedia\restoreWarnings();

    if ( $currentPage ) {
      $pager = $this->displayPager( $currentPageNum, $numPages );
      $output .= $pager;
      $output .= '<a id="cfirst" name="cfirst" rel="nofollow"></a>';

      foreach ( $currentPage as $thread ) {
        foreach ( $thread as $teamcomment ) {
          $output .= $teamcomment->display();
        }
      }
      $output .= $pager;
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
        $output .= '<div class="c-form-title">' . wfMessage( 'teamcomments-submit' )->plain() . '</div>' . "\n";
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
          $output .= '<textarea name="teamcommentText" id="teamcomment" rows="5" cols="64"></textarea>' . "\n";
          $output .= '<div class="c-form-button"><input type="button" value="' .
            wfMessage( 'teamcomments-post' )->plain() . '" class="site-button" /></div>' . "\n";
        }
      }
      $output .= '<input type="hidden" name="action" value="purge" />' . "\n";
      $output .= '<input type="hidden" name="pageId" value="' . $this->id . '" />' . "\n";
      $output .= '<input type="hidden" name="teamcommentid" />' . "\n";
      $output .= '<input type="hidden" name="lastTeamCommentId" value="' . $this->getLatestTeamCommentID() . '" />' . "\n";
      $output .= '<input type="hidden" name="teamcommentParentId" />' . "\n";
      $output .= '<input type="hidden" name="' . $this->pageQuery . '" value="' . $this->getCurrentPagerPage() . '" />' . "\n";
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
