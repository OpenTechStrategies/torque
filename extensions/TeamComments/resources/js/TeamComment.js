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
     * This function is called whenever a user clicks on the "Edit"
     * link to delete a teamcomment.
     *
     * @param {number} teamcommentID TeamComment ID number
     */
    editTeamComment: function ( teamcommentID ) {
      var containerDiv = document.getElementById('teamcomment-' + teamcommentID);
      containerDiv.className = containerDiv.className + ' teamcomment-showedit';
    },

    /**
     * This function is called whenever a user clicks on the "Edit"
     * link to delete a teamcomment.
     *
     * @param {number} teamcommentID TeamComment ID number
     */
    saveEditTeamComment: function (callingButton) {
      var editareaContainer = $(callingButton).closest(".teamcomment-editarea");
      var teamcommentID = editareaContainer.attr('data-teamcomment-id');
      var teamcommentText = editareaContainer.find('textarea').val();

      ( new mw.Api() ).postWithToken( 'csrf', {
        action: 'teamcommentedit',
        teamcommentID: teamcommentID,
        teamcommentText: teamcommentText
      } ).done( function ( response ) {
        var end;

        if(response.teamcommentedit && response.teamcommentedit.newFormattedText) {
          var containerDiv = $(document.getElementById('teamcomment-' + teamcommentID));
          containerDiv.removeClass('teamcomment-showedit');
          containerDiv.find('.c-teamcomment').html(response.teamcommentedit.newFormattedText);
        }
      } );
    },

    /**
     * @param {number} pageID Page ID
     * @param {boolean} end Scroll to bottom after?
     */
    viewTeamComments: function ( pageID, end ) {
      document.getElementById( 'allteamcomments' ).innerHTML = mw.msg( 'teamcomments-loading' ) + '<br /><br />';

      $.ajax( {
        url: mw.config.get( 'wgScriptPath' ) + '/api.php',
        data: { action: 'teamcommentlist', format: 'json', pageID: pageID },
        cache: false
      } ).done( function ( response ) {
        document.getElementById( 'allteamcomments' ).innerHTML = response.teamcommentlist.html;
        TeamComment.LatestTeamCommentID = response.teamcommentlist.latestCommentID;
        TeamComment.submitted = 0;
        if ( end ) {
          window.location.hash = 'end';
        }
        $(".teamcomments-refresh-banner-container").hide();
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
            TeamComment.viewTeamComments( document.teamcommentForm.pageId.value, end );
          } else {
            // eslint-disable-next-line no-alert
            window.alert( response.error.info );
            TeamComment.submitted = 0;
          }
        } );

        TeamComment.cancelReply();
      }
    },

    checkUpdate: function () {
      var pageID;

      if ( TeamComment.isBusy || TeamComment.updateStarted ) {
        return;
      }
      pageID = document.teamcommentForm.pageId.value;

      $.ajax( {
        url: mw.config.get( 'wgScriptPath' ) + '/api.php',
        data: { action: 'teamcommentnumnew', format: 'json', pageID: pageID, latestID: TeamComment.LatestTeamCommentID },
        cache: false
      } ).done( function ( response ) {
        if ( response.teamcommentnumnew.numnew ) {
          if(response.teamcommentnumnew.numnew > 0) {
            var banner = $(".teamcomments-refresh-banner-container");
            banner.find("#teamcomments-number-of-comments").html(response.teamcommentnumnew.numnew);
            banner.fadeIn();
          }
        }

        TeamComment.isBusy = false;
        clearTimeout( TeamComment.timer );
        TeamComment.timer = setTimeout(
          function () { TeamComment.checkUpdate(); },
          TeamComment.updateDelay
        );
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
    reply: function ( parentId, poster, replyon, posterGender ) {
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
      $("#teamcomment").val('');
    },

    highlightComment: function () {
      var hash = window.location.hash;

      if(hash && hash.startsWith("#teamcomment")) {
        if(TeamComment.highlightedComment) {
          TeamComment.highlightedComment.removeClass("teamcomment-highlighted");
        }

        TeamComment.highlightedComment = $(hash);
        TeamComment.highlightedComment.addClass("teamcomment-highlighted");
      }
    }
  };

  $( function () {
    // Important note: these are all using $( 'body' ) as the selector
    // instead of the class/ID/whatever so that they work after viewTeamComments()
    // has been called (i.e. so that "Delete teamcomment", reply, etc. links
    // continue working after you've submitted a teamcomment yourself)

    TeamComment.LatestTeamCommentID = $('.teamcomments-body').data('latestid');
    TeamComment.checkUpdate();

    // "Sort by X" feature
    $( 'body' )
      // "Delete TeamComment" links
      .on( 'click', 'a.teamcomment-delete-link', function () {
        TeamComment.deleteTeamComment( $( this ).data( 'teamcomment-id' ) );
      } )

      // "Edit TeamComment" links
      .on( 'click', 'a.teamcomment-edit-link', function () {
        TeamComment.editTeamComment( $( this ).data( 'teamcomment-id' ) );
      } )

      // "Save the Edit" links
      .on( 'click', 'button.teamcomment-save-button', function () {
        TeamComment.saveEditTeamComment($(this ));
      } )

      // Refresh page link
      .on( 'click', 'a.teamcomments-banner-refresh', function () {
        TeamComment.viewTeamComments(mw.config.get( 'wgArticleId' ), 0);
      } )

      // Reply links
      .on( 'click', 'a.teamcomments-reply-to', function () {
        TeamComment.reply(
          $( this ).data( 'teamcomment-id' ),
          $( this ).data( 'teamcomments-safe-username' ),
          $( this ).data( 'teamcomments-safe-replyon' ),
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
    $(window).on('beforeunload', function() {
      if(document.teamcommentForm.teamcommentText.value != '') {
        return true;
      }
    });
    $(window).on( 'hashchange', TeamComment.highlightComment);
    TeamComment.highlightComment();
  } );
}( jQuery, mediaWiki ) );
