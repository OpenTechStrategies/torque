<?php

class DisplayTeamComments {

  /**
   * Callback function for onParserFirstCallInit(),
   * displays teamcomments.
   *
   * @param $input
   * @param array $args
   * @param Parser $parser
   * @return string HTML
   */
  public static function getParserHandler( $input, $args, $parser ) {
    global $wgTeamCommentsEnabled;

    if(! $wgTeamCommentsEnabled) {
      return "";
    }

    $po = $parser->getOutput();
    $po->updateCacheExpiry( 0 );
    // If an unclosed <teamcomments> tag is added to a page, the extension will
    // go to an infinite loop...this protects against that condition.
    $parser->setHook( 'teamcomments', [ __CLASS__, 'nonDisplayTeamComments' ] );

    $title = $parser->getTitle();
    if ( $title->getArticleID() == 0 && $title->getDBkey() == 'TeamCommentListGet' ) {
      return self::nonDisplayTeamComments( $input, $args, $parser );
    }

    // Add required CSS & JS via ResourceLoader
    $po->addModuleStyles( 'ext.teamcomments.css' );
    $po->addModules( 'ext.teamcomments.js' );

    // Parse arguments
    // The preg_match() lines here are to support the old-style way of
    // adding arguments:
    // <teamcomments>
    // Allow=Foo,Bar
    // </teamcomments>
    // whereas the normal, standard MediaWiki style, which this extension
    // also supports is: <teamcomments allow="Foo,Bar" />
    $allow = '';
    if ( preg_match( '/^\s*Allow\s*=\s*(.*)/mi', $input, $matches ) ) {
      $allow = htmlspecialchars( $matches[1] );
    } elseif ( !empty( $args['allow'] ) ) {
      $allow = $args['allow'];
    }

    // Create a new context to execute the TeamCommentsPage
    $context = new RequestContext;
    $context->setTitle( $title );
    $context->setRequest( new FauxRequest() );
    $context->setUser( $parser->getUser() );
    $context->setLanguage( $parser->getTargetLanguage() );

    $teamcommentsPage = new TeamCommentsPage( $title->getArticleID(), $context );
    $teamcommentsPage->allow = $allow;

    $output = '<div class="teamcomments-body" data-latestid="' .
      $teamcommentsPage->getLatestTeamCommentID() .
      '">';

    $output .= $teamcommentsPage->displayHeader();

    $output .= '<div id="allteamcomments">' . $teamcommentsPage->display() . '</div>';

    // If the database is in read-only mode, display a message informing the
    // user about that, otherwise allow them to teamcomment
    if ( !wfReadOnly() ) {
      $output .= $teamcommentsPage->displayForm();
    } else {
      $output .= wfMessage( 'teamcomments-db-locked' )->parse();
    }
    $output .= '<a id="end" rel="nofollow"></a>';

    $output .= '</div>'; // div.teamcomments-body

    return $output;
  }

  public static function nonDisplayTeamComments( $input, $args, $parser ) {
    $attr = [];

    foreach ( $args as $name => $value ) {
      $attr[] = htmlspecialchars( $name ) . '="' . htmlspecialchars( $value ) . '"';
    }

    $output = '&lt;teamcomments';
    if ( count( $attr ) > 0 ) {
      $output .= ' ' . implode( ' ', $attr );
    }

    if ( !is_null( $input ) ) {
      $output .= '&gt;' . htmlspecialchars( $input ) . '&lt;/teamcomments&gt;';
    } else {
      $output .= ' /&gt;';
    }

    return $output;
  }
}
