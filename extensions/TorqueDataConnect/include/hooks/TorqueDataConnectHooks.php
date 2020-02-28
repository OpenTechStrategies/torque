<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location) {
  	$parser->disableCache();

    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectRenderToHTML, $wgTorqueDataConnectView;
    $contents = file_get_contents(
      "http://localhost:5000/api/" .
      $location .
      "?group=" .
      $wgTorqueDataConnectGroup .
      ($wgTorqueDataConnectView ? "&view=" . $wgTorqueDataConnectView : "")
      );

    # If there are parser hooks in the output of the template, then
    # then we need to parse it fully, and let mediawiki know that
    # we're sending html as output.
    #
    # Discovered this when using the #evu tag which creates iframes
    # for videos.
    if(!$contents) {
      global $wgTorqueDataConnectNotFoundMessage;
      return $wgTorqueDataConnectNotFoundMessage;
    } else if($wgTorqueDataConnectRenderToHTML) {
      return [$parser->recursiveTagParseFully($contents), "isHTML" => true];
    } else {
      return $contents;
    }
	}

  public static function onPageContentSaveComplete(
    $wikiPage, $user, $mainContent, $summaryText, $isMinor, $isWatch, $section,
    &$flags, $revision, $status, $originalRevId, $undidRevId
  ) {
    global $wgTorqueDataConnectConfigPage;
    if(TorqueDataConnectConfig::isConfigPage($wikiPage->getTitle())) {
      TorqueDataConnectConfig::commitConfigToTorqueData();
    }
  }

  public static function onBeforeInitialize(&$title, &$article = null, &$output, &$user, $request, $mediaWiki) {
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectView;
    if($user && !$wgTorqueDataConnectGroup) {
      $wgTorqueDataConnectGroup = TorqueDataConnectConfig::getValidGroup($user);
    }

    if(!$wgTorqueDataConnectView) {
      $wgTorqueDataConnectView = TorqueDataConnectConfig::getCurrentView();
    }
  }

  public static function siteNoticeAfter(&$siteNotice, $skin) {
    if (!$skin->getUser()->isAllowed("torquedataconnect-admin")) {
      return true;
    }

    $configErrors = TorqueDataConnectConfig::checkForErrors();

    if(sizeof($configErrors) > 0) {
      $html = "";
      $html .= "<div style='border:2px solid #AA3333;padding:10px;text-align:left;margin-top:10px;background-color:#FFEEEE'>";
      $html .= "<h2 style='margin-top:0px;border-bottom:0px'>Torque Data Configuration Alert!</h2>";

      $html .= "<ul>";
      foreach($configErrors as $configError) {
        $html .= "<li>${configError}\n";
      }
      $html .= "</ul>";
      $html .= "</div>";
      $siteNotice .= $html;
    }

    return true;
  }

  public static function onSpecialSearchResultsPrepend($specialSearch, $output, $term) {
    global $wgTorqueDataConnectGroup;

    $output->addWikiText("== Torque Results for '" . $term . "' ==");

    $results = file_get_contents(
      "http://localhost:5000/search/proposals" .
      "?group=" .
      $wgTorqueDataConnectGroup .
      "&q=" .
      urlencode($term)
      );
    $output->addWikiText($results);

    return false;
  }

  public static function onSidebarBeforeOutput(Skin $skin, &$bar) {
    # Do this all inline here for now because it's quick, and it would actually
    # be more confusing to set up the entire javascript infrastructure.  The moment
    # we do more things with js, this should all get broken out and modularized
    # correctly!
    #
    # Also depending on jquery here, which should get loaded by mediawiki.
    $out  = "<div style='line-height: 1.125em; font-size: 0.75em'>";
    $out .= "<select autocomplete=off id='torque-view-select' style='width:130px'";
    $out .= "onchange='";
    $out .= "var view = $(\"#torque-view-select\").children(\"option:selected\").val();";
    $out .= "document.cookie = \"torqueview=\" + view + \"; path=/;\";";
    $out .= "window.location = window.location;";
    $out .= "'>";
    foreach(TorqueDataConnectConfig::getAvailableViews() as $view) {
      $selected = $view == $_COOKIE["torqueview"] ? " selected=selected" : "";
      $out .= "<option $selected value='$view'>$view</option>";
    }
    $out .= "</select>";
    $out .= "</div>";
    $bar['View'] = $out;

    return true;
  }
}

?>
