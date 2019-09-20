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
	/**
	 * @var TeamCommentsPage: page of the page the <teamcomments /> tag is in
	 */
	public $page = null;

	/**
	 * @var Integer: total amount of teamcomments by distinct teamcommenters that the
	 *               current page has
	 */
	public $teamcommentTotal = 0;

	/**
	 * @var String: text of the current teamcomment
	 */
	public $text = null;

	/**
	 * Date when the teamcomment was posted
	 *
	 * @var null
	 */
	public $date = null;

	/**
	 * @var Integer: internal ID number (TeamComments.TeamCommentID DB field) of the
	 *               current teamcomment that we're dealing with
	 */
	public $id = 0;

	/**
	 * @var Integer: ID of the parent teamcomment, if this is a child teamcomment
	 */
	public $parentID = 0;

	/**
	 * The current vote from this user on this teamcomment
	 *
	 * @var int|boolean: false if no vote, otherwise -1, 0, or 1
	 */
	public $currentVote = false;

	/**
	 * @var string: teamcomment score (SUM() of all votes) of the current teamcomment
	 */
	public $currentScore = '0';

	/**
	 * Username of the user who posted the teamcomment
	 *
	 * @var string
	 */
	public $username = '';

	/**
	 * IP of the teamcomment poster
	 *
	 * @var string
	 */
	public $ip = '';

	/**
	 * ID of the user who posted the teamcomment
	 *
	 * @var int
	 */
	public $userID = 0;

	/**
	 * The amount of points the user has; fetched from the user_stats table if
	 * SocialProfile is installed, otherwise this remains 0
	 *
	 * @var int
	 */
	public $userPoints = 0;

	/**
	 * TeamComment ID of the thread this teamcomment is in
	 * this is the ID of the parent teamcomment if there is one,
	 * or this teamcomment if there is not
	 * Used for sorting
	 *
	 * @var null
	 */
	public $thread = null;

	/**
	 * Unix timestamp when the teamcomment was posted
	 * Used for sorting
	 * Processed from $date
	 *
	 * @var null
	 */
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

		$this->username = $data['TeamComment_Username'];
		$this->ip = $data['TeamComment_IP'];
		$this->text = $data['TeamComment_Text'];
		$this->date = $data['TeamComment_Date'];
		$this->userID = (int)$data['TeamComment_user_id'];
		$this->userPoints = $data['TeamComment_user_points'];
		$this->id = (int)$data['TeamCommentID'];
		$this->parentID = (int)$data['TeamComment_Parent_ID'];
		$this->thread = $data['thread'];
		$this->timestamp = $data['timestamp'];

		if ( isset( $data['current_vote'] ) ) {
			$vote = $data['current_vote'];
		} else {
			$dbr = wfGetDB( DB_REPLICA );
			$row = $dbr->selectRow(
				'TeamComments_Vote',
				[ 'TeamComment_Vote_Score' ],
				[
					'TeamComment_Vote_ID' => $this->id,
					'TeamComment_Vote_Username' => $this->getUser()->getName()
				],
				__METHOD__
			);
			if ( $row !== false ) {
				$vote = $row->TeamComment_Vote_Score;
			} else {
				$vote = false;
			}
		}

		$this->currentVote = $vote;

		$this->currentScore = isset( $data['total_vote'] )
			? $data['total_vote'] : $this->getScore();
	}

	public static function newFromID( $id ) {
		$context = RequestContext::getMain();
		$dbr = wfGetDB( DB_REPLICA );

		if ( !is_numeric( $id ) || $id == 0 ) {
			return null;
		}

		$tables = [];
		$params = [];
		$joinConds = [];

		// Defaults (for non-social wikis)
		$tables[] = 'TeamComments';
		$fields = [
			'TeamComment_Username', 'TeamComment_IP', 'TeamComment_Text',
			'TeamComment_Date', 'TeamComment_Date AS timestamp',
			'TeamComment_user_id', 'TeamCommentID', 'TeamComment_Parent_ID',
			'TeamCommentID', 'TeamComment_Page_ID'
		];

		// If SocialProfile is installed, query the user_stats table too.
		if (
			class_exists( 'UserProfile' ) &&
			$dbr->tableExists( 'user_stats' )
		) {
			$tables[] = 'user_stats';
			$fields[] = 'stats_total_points';
			$joinConds = [
				'TeamComments' => [
					'LEFT JOIN', 'TeamComment_user_id = stats_user_id'
				]
			];
		}

		// Perform the query
		$res = $dbr->select(
			$tables,
			$fields,
			[ 'TeamCommentID' => $id ],
			__METHOD__,
			$params,
			$joinConds
		);

		$row = $res->fetchObject();

		if ( $row->TeamComment_Parent_ID == 0 ) {
			$thread = $row->TeamCommentID;
		} else {
			$thread = $row->TeamComment_Parent_ID;
		}
		$data = [
			'TeamComment_Username' => $row->TeamComment_Username,
			'TeamComment_IP' => $row->TeamComment_IP,
			'TeamComment_Text' => $row->TeamComment_Text,
			'TeamComment_Date' => $row->TeamComment_Date,
			'TeamComment_user_id' => $row->TeamComment_user_id,
			'TeamComment_user_points' => ( isset( $row->stats_total_points ) ? number_format( $row->stats_total_points ) : 0 ),
			'TeamCommentID' => $row->TeamCommentID,
			'TeamComment_Parent_ID' => $row->TeamComment_Parent_ID,
			'thread' => $thread,
			'timestamp' => wfTimestamp( TS_UNIX, $row->timestamp )
		];

		$page = new TeamCommentsPage( $row->TeamComment_Page_ID, $context );

		return new TeamComment( $page, $context, $data );
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
			'TeamComments',
			[
				'TeamComment_Page_ID' => $page->id,
				'TeamComment_Username' => $user->getName(),
				'TeamComment_user_id' => $user->getId(),
				'TeamComment_Text' => $text,
				'TeamComment_Date' => $teamcommentDate,
				'TeamComment_Parent_ID' => $parentID,
				'TeamComment_IP' => $_SERVER['REMOTE_ADDR']
			],
			__METHOD__
		);
		$teamcommentId = $dbw->insertId();
		$id = $teamcommentId;

		$page->clearTeamCommentListCache();

		// Add a log entry.
		self::log( 'add', $user, $page->id, $teamcommentId, $text );

		$dbr = wfGetDB( DB_REPLICA );
		if (
			class_exists( 'UserProfile' ) &&
			$dbr->tableExists( 'user_stats' )
		) {
			$res = $dbr->select( // need this data for seeding a TeamComment object
				'user_stats',
				'stats_total_points',
				[ 'stats_user_id' => $user->getId() ],
				__METHOD__
			);

			$row = $res->fetchObject();
			$userPoints = number_format( $row->stats_total_points );
		} else {
			$userPoints = 0;
		}

		if ( $parentID == 0 ) {
			$thread = $id;
		} else {
			$thread = $parentID;
		}
		$data = [
			'TeamComment_Username' => $user->getName(),
			'TeamComment_IP' => $context->getRequest()->getIP(),
			'TeamComment_Text' => $text,
			'TeamComment_Date' => $teamcommentDate,
			'TeamComment_user_id' => $user->getId(),
			'TeamComment_user_points' => $userPoints,
			'TeamCommentID' => $id,
			'TeamComment_Parent_ID' => $parentID,
			'thread' => $thread,
			'timestamp' => strtotime( $teamcommentDate )
		];

		$page = new TeamCommentsPage( $page->id, $context );
		$teamcomment = new TeamComment( $page, $context, $data );

		Hooks::run( 'TeamComment::add', [ $teamcomment, $teamcommentId, $teamcomment->page->id ] );

		return $teamcomment;
	}

	/**
	 * Gets the score for this teamcomment from the database table TeamComments_Vote
	 *
	 * @return string
	 */
	function getScore() {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow(
			'TeamComments_Vote',
			[ 'SUM(TeamComment_Vote_Score) AS TeamCommentScore' ],
			[ 'TeamComment_Vote_ID' => $this->id ],
			__METHOD__
		);
		$score = '0';
		if ( $row !== false && $row->TeamCommentScore ) {
			$score = $row->TeamCommentScore;
		}
		return $score;
	}

	/**
	 * Adds a vote for a teamcomment if the user hasn't voted for said teamcomment yet.
	 *
	 * @param int $value Upvote or downvote (1 or -1)
	 */
	function vote( $value ) {
		$dbw = wfGetDB( DB_MASTER );

		if ( $value < -1 ) { // limit to range -1 -> 0 -> 1
			$value = -1;
		} elseif ( $value > 1 ) {
			$value = 1;
		}

		if ( $value == $this->currentVote ) { // user toggling off a preexisting vote
			$value = 0;
		}

		Wikimedia\suppressWarnings();
		$teamcommentDate = date( 'Y-m-d H:i:s' );
		Wikimedia\restoreWarnings();

		if ( $this->currentVote === false ) { // no vote, insert
			$dbw->insert(
				'TeamComments_Vote',
				[
					'TeamComment_Vote_id' => $this->id,
					'TeamComment_Vote_Username' => $this->getUser()->getName(),
					'TeamComment_Vote_user_id' => $this->getUser()->getId(),
					'TeamComment_Vote_Score' => $value,
					'TeamComment_Vote_Date' => $teamcommentDate,
					'TeamComment_Vote_IP' => $_SERVER['REMOTE_ADDR']
				],
				__METHOD__
			);
		} else { // already a vote, update
			$dbw->update(
				'TeamComments_Vote',
				[
					'TeamComment_Vote_Score' => $value,
					'TeamComment_Vote_Date' => $teamcommentDate,
					'TeamComment_Vote_IP' => $_SERVER['REMOTE_ADDR']
				],
				[
					'TeamComment_Vote_id' => $this->id,
					'TeamComment_Vote_Username' => $this->getUser()->getName(),
					'TeamComment_Vote_user_id' => $this->getUser()->getId(),
				],
				__METHOD__
			);
		}

		$score = $this->getScore();

		$this->currentVote = $value;
		$this->currentScore = $score;
	}

	/**
	 * Deletes entries from TeamComments and TeamComments_Vote tables and clears caches
	 */
	function delete() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );
		$dbw->delete(
			'TeamComments',
			[ 'TeamCommentID' => $this->id ],
			__METHOD__
		);
		$dbw->delete(
			'TeamComments_Vote',
			[ 'TeamComment_Vote_ID' => $this->id ],
			__METHOD__
		);
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
	 * Return the HTML for the teamcomment vote links
	 *
	 * @param int $voteType up (+1) vote or down (-1) vote
	 * @return string
	 */
	function getVoteLink( $voteType ) {
		global $wgExtensionAssetsPath;

		// Blocked users cannot vote, obviously
		if ( $this->getUser()->isBlocked() ) {
			return '';
		}
		if ( !$this->getUser()->isAllowed( 'teamcomment' ) ) {
			return '';
		}

		$voteLink = '';
		if ( $this->getUser()->isLoggedIn() ) {
			$voteLink .= '<a id="teamcomment-vote-link" data-teamcomment-id="' .
				$this->id . '" data-vote-type="' . $voteType .
				'" data-voting="' . $this->page->voting . '" href="javascript:void(0);">';
		} else {
			$login = SpecialPage::getTitleFor( 'Userlogin' ); // Anonymous users need to log in before they can vote
			$urlParams = [];
			// @todo FIXME: *when* and *why* is this null?
			if ( $this->page->title instanceof Title ) {
				$returnTo = $this->page->title->getPrefixedDBkey(); // Determine a sane returnto URL parameter
				$urlParams = [ 'returnto' => $returnTo ];
			}

			$voteLink .=
				"<a href=\"" .
				htmlspecialchars( $login->getLocalURL( $urlParams ) ) .
				"\" rel=\"nofollow\">";
		}

		$imagePath = $wgExtensionAssetsPath . '/TeamComments/resources/images';
		if ( $voteType == 1 ) {
			if ( $this->currentVote == 1 ) {
				$voteLink .= "<img src=\"{$imagePath}/up-voted.png\" border=\"0\" alt=\"+\" /></a>";
			} else {
				$voteLink .= "<img src=\"{$imagePath}/up-unvoted.png\" border=\"0\" alt=\"+\" /></a>";
			}
		} else {
			if ( $this->currentVote == -1 ) {
				$voteLink .= "<img src=\"{$imagePath}/down-voted.png\" border=\"0\" alt=\"+\" /></a>";
			} else {
				$voteLink .= "<img src=\"{$imagePath}/down-unvoted.png\" border=\"0\" alt=\"+\" /></a>";
			}
		}

		return $voteLink;
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
		global $wgUserLevels, $wgExtensionAssetsPath;

		$style = '';
		if ( $hide ) {
			$style = " style='display:none;'";
		}

		$teamcommentPosterLevel = '';

		if ( $this->userID != 0 ) {
			$title = Title::makeTitle( NS_USER, $this->username );

			$teamcommentPoster = '<a href="' . htmlspecialchars( $title->getFullURL() ) .
				'" rel="nofollow">' . $this->username . '</a>';

			$TeamCommentReplyTo = $this->username;

			if ( $wgUserLevels && class_exists( 'UserLevel' ) ) {
				$user_level = new UserLevel( $this->userPoints );
				$teamcommentPosterLevel = "{$user_level->getLevelName()}";
			}

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

		if (
			$userObj->isAllowed( 'teamcommentadmin' ) ||
			// Allow users to delete their own teamcomments if that feature is enabled in
			// site configuration
			// @see https://phabricator.wikimedia.org/T147796
			$userObj->isAllowed( 'teamcomment-delete-own' ) && $this->isOwner( $userObj )
		) {
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

		// Default avatar image, if SocialProfile extension isn't enabled
		global $wgTeamCommentsDefaultAvatar;
		$avatarImg = '<img src="' . $wgTeamCommentsDefaultAvatar . '" alt="" border="0" />';
		// If SocialProfile *is* enabled, then use its wAvatar class to get the avatars for each teamcommenter
		if ( class_exists( 'wAvatar' ) ) {
			$avatar = new wAvatar( $this->userID, 'ml' );
			$avatarImg = $avatar->getAvatarURL() . "\n";
		}

		$output = "<div id='teamcomment-{$this->id}' class='c-item {$containerClass}'{$style}>" . "\n";
		$output .= "<div class=\"c-avatar\">{$avatarImg}</div>" . "\n";
		$output .= '<div class="c-container">' . "\n";
		$output .= '<div class="c-user">' . "\n";
		$output .= "{$teamcommentPoster}";
		$output .= "<span class=\"c-user-level\">{$teamcommentPosterLevel}</span>" . "\n";

		Wikimedia\suppressWarnings(); // E_STRICT bitches about strtotime()
		$output .= '<div class="c-time">' .
			wfMessage(
				'teamcomments-time-ago',
				TeamCommentFunctions::getTimeAgo( strtotime( $this->date ) )
			)->parse() . '</div>' . "\n";
		Wikimedia\restoreWarnings();

		$output .= '<div class="c-score">' . "\n";
		$output .= $this->getScoreHTML();
		$output .= '</div>' . "\n";

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

	/**
	 * Get the HTML for the teamcomment score section of the teamcomment
	 *
	 * @return string
	 */
	function getScoreHTML() {
		$output = '';

		if ( $this->page->allowMinus == true || $this->page->allowPlus == true ) {
			$output .= '<span class="c-score-title">' .
				wfMessage( 'teamcomments-score-text' )->plain() .
				" <span id=\"TeamComment{$this->id}\">{$this->currentScore}</span></span>";

			// Voting is possible only when database is unlocked
			if ( !wfReadOnly() ) {
				// You can only vote for other people's teamcomments, not for your own
				if ( $this->getUser()->getName() != $this->username ) {
					$output .= "<span id=\"TeamCommentBtn{$this->id}\">";
					if ( $this->page->allowPlus == true ) {
						$output .= $this->getVoteLink( 1 );
					}

					if ( $this->page->allowMinus == true ) {
						$output .= $this->getVoteLink( -1 );
					}
					$output .= '</span>';
				} else {
					$output .= wfMessage( 'word-separator' )->plain() . wfMessage( 'teamcomments-you' )->plain();
				}
			}
		}

		return $output;
	}
}
