<?php

use MediaWiki\MediaWikiServices;

/**
 * TeamComment class
 * Functions for managing teamcomments and everything related to them, including:
 * -blocking teamcomments from a given user
 * -counting the total amount of teamcomments in the database
 * -displaying the form for adding a new teamcomment
 * -getting all teamcomments for a given page
 *
 * @file
 * @ingroup Extensions
 */
class TeamComment extends ContextSource {
  public $page = null;
  public $text = null;
  public $date = null;
  public $id = 0;
  public $parentID = 0;
  public $username = '';
  public $ip = '';
  public $userID = 0;

  /**
   * TeamComment ID of the thread this teamcomment is in
   * this is the ID of the parent teamcomment if there is one,
   * or this teamcomment if there is not
   * Used for sorting
   */
  public $thread = null;

  public $timestamp = null;

  /**
   * Constructor - set the page ID
   *
   * @param TeamCommentsPage $page ID number of the current page
   * @param IContextSource|null $context
   * @param array $data Straight from the DB about the teamcomment
   */
  public function __construct( TeamCommentsPage $page, $context = null, $data ) {
    $this->page = $page;

    $this->setContext( $context );

    $this->username = $data['teamcomment_username'];
    $this->ip = $data['teamcomment_ip'];
    $this->text = $data['teamcomment_text'];
    $this->date = $data['teamcomment_date'];
    $this->userID = (int)$data['teamcomment_user_id'];
    $this->id = (int)$data['teamcomment_id'];
    $this->parentID = (int)$data['teamcomment_parent_id'];
    $this->thread = $data['thread'];
    $this->timestamp = $data['timestamp'];

  }

  public static function newFromID( $id ) {
    $context = RequestContext::getMain();
    $dbr = wfGetDB( DB_REPLICA );

    if ( !is_numeric( $id ) || $id == 0 ) {
      return null;
    }

    $tables = [];
    $params = [];

    // Defaults (for non-social wikis)
    $tables[] = 'teamcomments';
    $fields = [
      'teamcomment_username', 'teamcomment_ip', 'teamcomment_text',
      'teamcomment_date', 'teamcomment_date AS timestamp',
      'teamcomment_user_id', 'teamcomment_id', 'teamcomment_parent_id',
      'teamcomment_id', 'teamcomment_page_id'
    ];

    // Perform the query
    $res = $dbr->select(
      $tables,
      $fields,
      [ 'teamcomment_id' => $id ],
      __METHOD__,
      $params
    );

    $row = $res->fetchObject();

    if ( $row->teamcomment_parent_id == 0 ) {
      $thread = $row->teamcomment_id;
    } else {
      $thread = $row->teamcomment_parent_id;
    }
    $data = [
      'teamcomment_username' => $row->teamcomment_username,
      'teamcomment_ip' => $row->teamcomment_ip,
      'teamcomment_text' => $row->teamcomment_text,
      'teamcomment_date' => $row->teamcomment_date,
      'teamcomment_user_id' => $row->teamcomment_user_id,
      'teamcomment_id' => $row->teamcomment_id,
      'teamcomment_parent_id' => $row->teamcomment_parent_id,
      'thread' => $thread,
      'timestamp' => wfTimestamp( TS_UNIX, $row->timestamp )
    ];

    $page = new TeamCommentsPage( $row->teamcomment_page_id, $context );

    return new TeamComment( $page, $context, $data );
  }

  // This does a database lookup, because we only care about whether
  // we have children at delete time, and we don't have the full
  // reference of the page's comments at that time.
  public function hasChildren() {
    $dbr = wfGetDB( DB_REPLICA );

    $res = $dbr->select(
      'teamcomments',
      ['teamcomment_id'],
      ['teamcomment_parent_id' => $this->id]
    );

    return $res->numRows() > 0;
  }

  /**
   * Is the given User the owner (author) of this teamcomment?
   *
   * @param User $user
   * @return bool
   */
  public function isOwner( User $user ) {
    return ( $this->username === $user->getName() && $this->userID === $user->getId() );
  }

  /**
   * Parse and return the text for this teamcomment
   *
   * @return mixed|string
   * @throws MWException
   */
  function getText() {
    $parser = MediaWikiServices::getInstance()->getParser();

    $teamcommentText = trim( str_replace( '&quot;', "'", $this->text ) );
    $teamcomment_text_parts = explode( "\n", $teamcommentText );
    $teamcomment_text_fix = '';
    foreach ( $teamcomment_text_parts as $part ) {
      $teamcomment_text_fix .= ( ( $teamcomment_text_fix ) ? "\n" : '' ) . trim( $part );
    }

    if ( $this->getTitle()->getArticleID() > 0 ) {
      $teamcommentText = $parser->recursiveTagParse( $teamcomment_text_fix );
    } else {
      $teamcommentText = $this->getOutput()->parse( $teamcomment_text_fix );
    }

    // really bad hack because we want to parse=firstline, but don't want wrapping <p> tags
    if ( substr( $teamcommentText, 0, 3 ) == '<p>' ) {
      $teamcommentText = substr( $teamcommentText, 3 );
    }

    if ( substr( $teamcommentText, strlen( $teamcommentText ) - 4, 4 ) == '</p>' ) {
      $teamcommentText = substr( $teamcommentText, 0, strlen( $teamcommentText ) - 4 );
    }

    // make sure link text is not too long (will overflow)
    // this function changes too long links to <a href=#>http://www.abc....xyz.html</a>
    $teamcommentText = preg_replace_callback(
      "/(<a[^>]*>)(.*?)(<\/a>)/i",
      [ 'TeamCommentFunctions', 'cutTeamCommentLinkText' ],
      $teamcommentText
    );

    return $teamcommentText;
  }

  /**
   * Adds the teamcomment and all necessary info into the TeamComments table in the
   * database.
   *
   * @param string $text text of the teamcomment
   * @param TeamCommentsPage $page container page
   * @param User $user user teamcommenting
   * @param int $parentID ID of parent teamcomment, if this is a reply
   *
   * @return TeamComment the added teamcomment
   */
  static function add( $text, TeamCommentsPage $page, User $user, $parentID ) {
    $dbw = wfGetDB( DB_MASTER );
    $context = RequestContext::getMain();

    Wikimedia\suppressWarnings();
    $teamcommentDate = date( 'Y-m-d H:i:s' );
    Wikimedia\restoreWarnings();
    $dbw->insert(
      'teamcomments',
      [
        'teamcomment_page_id' => $page->id,
        'teamcomment_username' => $user->getName(),
        'teamcomment_user_id' => $user->getId(),
        'teamcomment_text' => $text,
        'teamcomment_date' => $teamcommentDate,
        'teamcomment_parent_id' => $parentID,
        'teamcomment_ip' => $_SERVER['REMOTE_ADDR']
      ],
      __METHOD__
    );
    $teamcommentId = $dbw->insertId();
    $id = $teamcommentId;

    $page->clearTeamCommentListCache();

    // Add a log entry.
    self::log( 'add', $user, $page->id, $teamcommentId, $text );

    if ( $parentID == 0 ) {
      $thread = $id;
    } else {
      $thread = $parentID;
    }
    $data = [
      'teamcomment_username' => $user->getName(),
      'teamcomment_ip' => $context->getRequest()->getIP(),
      'teamcomment_text' => $text,
      'teamcomment_date' => $teamcommentDate,
      'teamcomment_user_id' => $user->getId(),
      'teamcomment_id' => $id,
      'teamcomment_parent_id' => $parentID,
      'thread' => $thread,
      'timestamp' => strtotime( $teamcommentDate )
    ];

    $page = new TeamCommentsPage( $page->id, $context );
    $teamcomment = new TeamComment( $page, $context, $data );

    Hooks::run( 'TeamComment::add', [ $teamcomment, $teamcommentId, $teamcomment->page->id ] );

    return $teamcomment;
  }

  /**
   * Deletes entries from TeamComments tables and clears caches
   */
  function delete() {
    $dbw = wfGetDB( DB_MASTER );
    $dbw->startAtomic( __METHOD__ );
    if($this->hasChildren()) {
      $dbw->update(
        'teamcomments',
        [ 'teamcomment_text' => "[deleted]" ],
        [ 'teamcomment_id' => $this->id ],
        __METHOD__
      );
    } else {
      $dbw->delete(
        'teamcomments',
        [ 'teamcomment_id' => $this->id ],
        __METHOD__
      );
    }
    $dbw->endAtomic( __METHOD__ );

    // Log the deletion to Special:Log/teamcomments.
    self::log( 'delete', $this->getUser(), $this->page->id, $this->id );

    // Clear memcache & Squid cache
    $this->page->clearTeamCommentListCache();

    // Ping other extensions that may have hooked into this point (i.e. LinkFilter)
    Hooks::run( 'TeamComment::delete', [ $this, $this->id, $this->page->id ] );
  }

  /**
   * Log an action in the teamcomment log.
   *
   * @param string $action Action to log, can be either 'add' or 'delete'
   * @param User $user User who performed the action
   * @param int $pageId Page ID of the page that contains the teamcomment thread
   * @param int $teamcommentId TeamComment ID of the affected teamcomment
   * @param string|null $teamcommentText Supplementary log teamcomment, if any
   */
  static function log( $action, $user, $pageId, $teamcommentId, $teamcommentText = null ) {
    global $wgTeamCommentsInRecentChanges;
    $logEntry = new ManualLogEntry( 'teamcomments', $action );
    $logEntry->setPerformer( $user );
    $logEntry->setTarget( Title::newFromId( $pageId ) );
    if ( $teamcommentText !== null ) {
      $logEntry->setComment( $teamcommentText );
    }
    $logEntry->setParameters( [
      '4::teamcommentid' => $teamcommentId
    ] );
    $logId = $logEntry->insert();
    $logEntry->publish( $logId, ( $wgTeamCommentsInRecentChanges ? 'rcandudp' : 'udp' ) );
  }

  /**
   * Show the HTML for this teamcomment and ignore section
   *
   * @param array $anonList Map of IP addresses to names like anon#1, anon#2
   * @return string HTML
   */
  function display( $anonList ) {
    if ( $this->parentID == 0 ) {
      $container_class = 'full';
    } else {
      $container_class = 'reply';
    }

    $output = '';

    $output .= $this->showTeamComment( false, $container_class, $anonList );

    return $output;
  }

  /**
   * Show the teamcomment
   *
   * @param bool $hide If true, teamcomment is returned but hidden (display:none)
   * @param string $containerClass
   * @param array $anonList
   * @return string
   */
  function showTeamComment( $hide = false, $containerClass, $anonList ) {
    global $wgExtensionAssetsPath;

    $style = '';
    if ( $hide ) {
      $style = " style='display:none;'";
    }

    if ( $this->userID != 0 ) {
      $title = Title::makeTitle( NS_USER, $this->username );

      $teamcommentPoster = '<a href="' . htmlspecialchars( $title->getFullURL() ) .
        '" rel="nofollow">' . $this->username . '</a>';

      $TeamCommentReplyTo = $this->username;

      $user = User::newFromId( $this->userID );
      $TeamCommentReplyToGender = $user->getOption( 'gender', 'unknown' );
    } else {
      $anonMsg = $this->msg( 'teamcomments-anon-name' )->inContentLanguage()->plain();
      $teamcommentPoster = $anonMsg . ' #' . $anonList[$this->username];
      $TeamCommentReplyTo = $anonMsg;
      $TeamCommentReplyToGender = 'unknown'; // Undisclosed gender as anon user
    }

    // TeamComment delete button for privileged users
    $userObj = $this->getUser();
    $dlt = '';

    if ($this->isOwner($userObj)) {
      $dlt = ' | <span class="c-delete">' .
        '<a href="javascript:void(0);" rel="nofollow" class="teamcomment-delete-link" data-teamcomment-id="' .
        $this->id . '">' .
        $this->msg( 'teamcomments-delete-link' )->plain() . '</a></span>';
    }

    // Reply Link (does not appear on child teamcomments)
    $replyRow = '';
    if ( $userObj->isAllowed( 'teamcomment' ) ) {
      if ( $this->parentID == 0 ) {
        if ( $replyRow ) {
          $replyRow .= wfMessage( 'pipe-separator' )->plain();
        }
        $replyRow .= " | <a href=\"#end\" rel=\"nofollow\" class=\"teamcomments-reply-to\" data-teamcomment-id=\"{$this->id}\" data-teamcomments-safe-username=\"" .
          htmlspecialchars( $TeamCommentReplyTo, ENT_QUOTES ) . "\" data-teamcomments-user-gender=\"" .
          htmlspecialchars( $TeamCommentReplyToGender ) . '">' .
          wfMessage( 'teamcomments-reply' )->plain() . '</a>';
      }
    }

    if ( $this->parentID == 0 ) {
      $teamcomment_class = 'f-message';
    } else {
      $teamcomment_class = 'r-message';
    }

    $output = "<div id='teamcomment-{$this->id}' class='c-item {$containerClass}'{$style}>" . "\n";
    $output .= '<div class="c-container">' . "\n";
    $output .= '<div class="c-user">' . "\n";
    $output .= "{$teamcommentPoster}";

    Wikimedia\suppressWarnings(); // E_STRICT bitches about strtotime()
    $output .= '<div class="c-time">' .
      wfMessage(
        'teamcomments-time-ago',
        TeamCommentFunctions::getTimeAgo( strtotime( $this->date ) )
      )->parse() . '</div>' . "\n";
    Wikimedia\restoreWarnings();

    $output .= '</div>' . "\n";
    $output .= "<div class=\"c-teamcomment {$teamcomment_class}\">" . "\n";
    $output .= $this->getText();
    $output .= '</div>' . "\n";
    $output .= '<div class="c-actions">' . "\n";
    if ( $this->page->title ) { // for some reason doesn't always exist
      $output .= '<a href="' . htmlspecialchars( $this->page->title->getFullURL() ) . "#teamcomment-{$this->id}\" rel=\"nofollow\">" .
      $this->msg( 'teamcomments-permalink' )->plain() . '</a> ';
    }
    if ( $replyRow || $dlt ) {
      $output .= "{$replyRow} {$dlt}" . "\n";
    }
    $output .= '</div>' . "\n";
    $output .= '</div>' . "\n";
    $output .= '<div class="visualClear"></div>' . "\n";
    $output .= '</div>' . "\n";

    return $output;
  }
}
