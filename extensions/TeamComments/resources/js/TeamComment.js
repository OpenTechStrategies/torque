/**
 * JavaScript for the TeamComments extension.
 *
 * @file
 */
( function ( $, mw ) {
	var TeamComment = {
		submitted: 0,
		isBusy: false,
		timer: '', // has to have an initial value...
		updateDelay: 7000,
		LatestTeamCommentID: '',
		CurLatestTeamCommentID: '',
		pause: 0,

		/**
		 * When a teamcomment's author is ignored, "Show TeamComment" link will be
		 * presented to the user.
		 * If the user clicks on it, this function is called to show the hidden
		 * teamcomment.
		 *
		 * @param {string} id
		 */
		show: function ( id ) {
			$( '#ignore-' + id ).hide( 300 );
			$( '#teamcomment-' + id ).show( 300 );
		},

		/**
		 * This function is called whenever a user clicks on the "Delete TeamComment"
		 * link to delete a teamcomment.
		 *
		 * @param {number} teamcommentID TeamComment ID number
		 */
		deleteTeamComment: function ( teamcommentID ) {
			// eslint-disable-next-line no-alert
			if ( window.confirm( mw.msg( 'teamcomments-delete-warning' ) ) ) {
				( new mw.Api() ).postWithToken( 'csrf', {
					action: 'teamcommentdelete',
					teamcommentID: teamcommentID
				} ).done( function ( response ) {
					if ( response.teamcommentdelete.ok ) {
						$( '#teamcomment-' + teamcommentID ).hide( 2000 );
					}
				} );
			}
		},

		/**
		 * Vote for a teamcomment.
		 *
		 * @param {number} teamcommentID TeamComment ID number
		 * @param {number} voteValue Vote value
		 */
		vote: function ( teamcommentID, voteValue ) {
			( new mw.Api() ).postWithToken( 'csrf', {
				action: 'teamcommentvote',
				teamcommentID: teamcommentID,
				voteValue: voteValue
			} ).done( function ( response ) {
				$( '#teamcomment-' + teamcommentID + ' .c-score' )
					.html( response.teamcommentvote.html ) // this will still be escaped
					.html( $( '#teamcomment-' + teamcommentID + ' .c-score' ).text() ); // unescape
			} );
		},

		/**
		 * @param {number} pageID Page ID
		 * @param {string} order Sorting order
		 * @param {boolean} end Scroll to bottom after?
		 * @param {number} cpage TeamComment page number (used for pagination)
		 */
		viewTeamComments: function ( pageID, order, end, cpage ) {
			document.teamcommentForm.cpage.value = cpage;
			document.getElementById( 'allteamcomments' ).innerHTML = mw.msg( 'teamcomments-loading' ) + '<br /><br />';

			$.ajax( {
				url: mw.config.get( 'wgScriptPath' ) + '/api.php',
				data: { action: 'teamcommentlist', format: 'json', pageID: pageID, order: order, pagerPage: cpage },
				cache: false
			} ).done( function ( response ) {
				document.getElementById( 'allteamcomments' ).innerHTML = response.teamcommentlist.html;
				TeamComment.submitted = 0;
				if ( end ) {
					window.location.hash = 'end';
				}
			} );
		},

		/**
		 * Submit a new teamcomment.
		 */
		submit: function () {
			var pageID, parentID, teamcommentText;

			if ( TeamComment.submitted === 0 ) {
				TeamComment.submitted = 1;

				pageID = document.teamcommentForm.pageId.value;
				if ( !document.teamcommentForm.teamcommentParentId.value ) {
					parentID = 0;
				} else {
					parentID = document.teamcommentForm.teamcommentParentId.value;
				}
				teamcommentText = document.teamcommentForm.teamcommentText.value;

				( new mw.Api() ).postWithToken( 'csrf', {
					action: 'teamcommentsubmit',
					pageID: pageID,
					parentID: parentID,
					teamcommentText: teamcommentText
				} ).done( function ( response ) {
					var end;

					if ( response.teamcommentsubmit && response.teamcommentsubmit.ok ) {
						document.teamcommentForm.teamcommentText.value = '';
						end = 1;
						if ( mw.config.get( 'wgTeamCommentsSortDescending' ) ) {
							end = 0;
						}
						TeamComment.viewTeamComments( document.teamcommentForm.pageId.value, 0, end, document.teamcommentForm.cpage.value );
					} else {
						// eslint-disable-next-line no-alert
						window.alert( response.error.info );
						TeamComment.submitted = 0;
					}
				} );

				TeamComment.cancelReply();
			}
		},

		/**
		 * Toggle teamcomment auto-refreshing on or off
		 *
		 * @param {boolean} status
		 */
		toggleLiveTeamComments: function ( status ) {
			var msg;

			if ( status ) {
				TeamComment.pause = 0;
			} else {
				TeamComment.pause = 1;
			}
			if ( status ) {
				msg = mw.msg( 'teamcomments-auto-refresher-pause' );
			} else {
				msg = mw.msg( 'teamcomments-auto-refresher-enable' );
			}

			$( 'body' ).on( 'click', 'div#spy a', function () {
				TeamComment.toggleLiveTeamComments( ( status ) ? 0 : 1 );
			} );
			$( 'div#spy a' ).css( 'font-size', '10px' ).text( msg );

			if ( !TeamComment.pause ) {
				TeamComment.LatestTeamCommentID = document.teamcommentForm.lastTeamCommentId.value;
				TeamComment.timer = setTimeout(
					function () { TeamComment.checkUpdate(); },
					TeamComment.updateDelay
				);
			}
		},

		checkUpdate: function () {
			var pageID;

			if ( TeamComment.isBusy ) {
				return;
			}
			pageID = document.teamcommentForm.pageId.value;

			$.ajax( {
				url: mw.config.get( 'wgScriptPath' ) + '/api.php',
				data: { action: 'teamcommentlatestid', format: 'json', pageID: pageID },
				cache: false
			} ).done( function ( response ) {
				if ( response.teamcommentlatestid.id ) {
					// Get last new ID
					TeamComment.CurLatestTeamCommentID = response.teamcommentlatestid.id;
					if ( TeamComment.CurLatestTeamCommentID !== TeamComment.LatestTeamCommentID ) {
						TeamComment.viewTeamComments( document.teamcommentForm.pageId.value, 0, 1, document.teamcommentForm.cpage.value );
						TeamComment.LatestTeamCommentID = TeamComment.CurLatestTeamCommentID;
					}
				}

				TeamComment.isBusy = false;
				if ( !TeamComment.pause ) {
					clearTimeout( TeamComment.timer );
					TeamComment.timer = setTimeout(
						function () { TeamComment.checkUpdate(); },
						TeamComment.updateDelay
					);
				}
			} );

			TeamComment.isBusy = true;
			return false;
		},

		/**
		 * Show the "reply to user X" form
		 *
		 * @param {number} parentId Parent teamcomment (the one we're replying to) ID
		 * @param {string} poster Name of the person whom we're replying to
		 * @param {string} posterGender Gender of the person whom we're replying to
		 */
		reply: function ( parentId, poster, posterGender ) {
			$( '#replyto' ).text(
				mw.msg( 'teamcomments-reply-to', poster, posterGender ) + ' ('
			);
			$( '<a>', {
				class: 'teamcomments-cancel-reply-link',
				style: 'cursor:pointer',
				text: mw.msg( 'teamcomments-cancel-reply' )
			} ).appendTo( '#replyto' );
			$( '#replyto' ).append( ') <br />' );

			document.teamcommentForm.teamcommentParentId.value = parentId;
		},

		cancelReply: function () {
			document.getElementById( 'replyto' ).innerHTML = '';
			document.teamcommentForm.teamcommentParentId.value = '';
		}
	};

	$( function () {
		// Important note: these are all using $( 'body' ) as the selector
		// instead of the class/ID/whatever so that they work after viewTeamComments()
		// has been called (i.e. so that "Delete teamcomment", reply, etc. links
		// continue working after you've submitted a teamcomment yourself)

		// "Sort by X" feature
		$( 'body' )
			.on( 'change', 'select[name="TheOrder"]', function () {
				TeamComment.viewTeamComments(
					mw.config.get( 'wgArticleId' ), // or we could use $( 'input[name="pid"]' ).val(), too
					$( this ).val(),
					0,
					document.teamcommentForm.cpage.value
				);
			} )

			// TeamComment auto-refresher
			.on( 'click', 'div#spy a', function () {
				TeamComment.toggleLiveTeamComments( 1 );
			} )

			// Voting links
			.on( 'click', 'a#teamcomment-vote-link', function () {
				var that = $( this );
				TeamComment.vote(
					that.data( 'teamcomment-id' ),
					that.data( 'vote-type' )
				);
			} )

			// "Delete TeamComment" links
			.on( 'click', 'a.teamcomment-delete-link', function () {
				TeamComment.deleteTeamComment( $( this ).data( 'teamcomment-id' ) );
			} )

			// "Show this hidden teamcomment" -- teamcomments made by people on the user's
			// personal block list
			.on( 'click', 'div.c-ignored-links a', function () {
				TeamComment.show( $( this ).data( 'teamcomment-id' ) );
			} )

			// Reply links
			.on( 'click', 'a.teamcomments-reply-to', function () {
				TeamComment.reply(
					$( this ).data( 'teamcomment-id' ),
					$( this ).data( 'teamcomments-safe-username' ),
					$( this ).data( 'teamcomments-user-gender' )
				);
			} )

			// "Reply to <username>" links
			.on( 'click', 'a.teamcomments-cancel-reply-link', function () {
				TeamComment.cancelReply();
			} )

			// Handle clicks on the submit button (previously this was an onclick attr)
			.on( 'click', 'div.c-form-button input[type="button"]', function () {
				TeamComment.submit();
			} )

			// Change page
			.on( 'click', 'li.c-pager-item a.c-pager-link', function () {
				var ordCrtl, ord = 0,
					teamcommentsBody = $( this ).parents( 'div.teamcomments-body:first' );

				if ( teamcommentsBody.length > 0 ) {
					ordCrtl = teamcommentsBody.first().find( 'select[name="TheOrder"]:first' );
					if ( ordCrtl.length > 0 ) {
						ord = ordCrtl.val();
					}
				}

				TeamComment.viewTeamComments(
					mw.config.get( 'wgArticleId' ), // or we could use $( 'input[name="pid"]' ).val(), too
					ord,
					0,
					$( this ).data( 'cpage' )
				);
			} );
	} );

}( jQuery, mediaWiki ) );
