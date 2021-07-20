<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location, $view = false, $wiki_key = false) {
    $parser->getOutput()->updateCacheExpiry(0);
    $po = $parser->getOutput();
    $po->addModules('ext.torquedataconnect.js');
    $po->addModuleStyles('ext.torquedataconnect.css');

    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectRenderToHTML, $wgTorqueDataConnectView,
      $wgTorqueDataConnectRaw, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig;

    // Let the tdcrender view be top priority
    if(!$view || $view == "false") {
      $view = $wgTorqueDataConnectView;
    }

    // We only allow wiki_key to be passed in if it's in the multi wiki config
    if(!$wiki_key ||
        !(
          $wgTorqueDataConnectMultiWikiConfig &&
          in_array($wiki_key, $wgTorqueDataConnectMultiWikiConfig)
        )) {
      $wiki_key = $wgTorqueDataConnectWikiKey;
    }

    // If this isn't set, that means we've gotten here through some other means, and we
    // should just grab whatever group is correct for the current user.
    if(!$wgTorqueDataConnectGroup) {
      global $wgUser;
      $wgTorqueDataConnectGroup = TorqueDataConnectConfig::getValidGroup($wgUser);
    }

    $contents = file_get_contents(
      $wgTorqueDataConnectServerLocation .
      "/api/" .
      $location .
      "?group=" .
      $wgTorqueDataConnectGroup .
      "&wiki_key=" .
      $wiki_key .
      ($view ? "&view=" . $view : "")
      );
    
    $contents = $contents . '<span id="page-info" data-location="' . $location . '" data-wiki-key="' . $wiki_key . '"></span>';

    # If there are parser hooks in the output of the template, then
    # then we need to parse it fully, and let mediawiki know that
    # we're sending html as output.
    #
    # Discovered this when using the #evu tag which creates iframes
    # for videos.
    if(!$contents) {
      global $wgTorqueDataConnectNotFoundMessage;
      return $wgTorqueDataConnectNotFoundMessage;
    } else if($wgTorqueDataConnectRaw || TorqueDataConnectConfig::isRawView($view)) {
      # We need to remove newlines and extra spaces because mediawiki adds a bunch of
      # <p> tags # when it hits them.  Since we want the output to be completely raw,
      # we trick mediawiki into doing just that by putting it all on one line
      $contents = preg_replace("/\s+/", " ", $contents);
      return [$contents, "isHTML" => true];
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
      $html .= "<h2 style='margin-top:0px;border-bottom:0px'>" . wfMessage("torquedataconnect-data-config-alert")->plain() . "</h2>";

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
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectSheetName,
      $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig;

    $offset = $specialSearch->getRequest()->getInt("offset", 0);

    if($wgTorqueDataConnectMultiWikiConfig) {
      $wiki_keys = "";
      $sheet_names = "";
      foreach($wgTorqueDataConnectMultiWikiConfig as $sheet_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $sheet_names .= "$sheet_name,";
      }
      $results = file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api/search.mwiki" .
        "?group=" . $wgTorqueDataConnectGroup .
        "&wiki_key=" .  $wgTorqueDataConnectWikiKey .
        "&sheet_name=" . $wgTorqueDataConnectSheetName .
        "&wiki_keys=" . $wiki_keys .
        "&sheet_names=" . $sheet_names .
        "&offset=" . $offset .
        "&q=" .  urlencode($term)
        );
    } else {
      $results = file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api/sheets/" .  $wgTorqueDataConnectSheetName . "/search.mwiki" .
        "?group=" .  $wgTorqueDataConnectGroup .
        "&wiki_key=" .  $wgTorqueDataConnectWikiKey .
        "&offset=" . $offset .
        "&q=" .  urlencode($term)
        );
    }
    $split_point = strpos($results, " ");
    $num_results = intval(substr($results, 0, $split_point));
    $mwiki_results = substr($results, $split_point + 1);
    $request = $specialSearch->getRequest();

    $header = "<h2>$num_results results for '$term'";
    if($num_results > 20) {
      $header .= " (viewing ";
      $header .= ($offset + 1) . " - " . min($num_results, ($offset + 20));
      if($offset > 0) {
        $header .= " | ";
        $request->appendQueryValue("offset", ($offset - 20));
        $prev_20_url = $output->getTitle()->getFullUrl(["offset" => $offset - 20, "search" => $term]);
        $header .= "<a href='$prev_20_url'>Prev 20</a>";
      }
      if(($offset + 20) < $num_results) {
        $header .= " | ";
        $request->appendQueryValue("offset", ($offset + 20));
        $next_20_url = $output->getTitle()->getFullUrl(["offset" => $offset + 20, "search" => $term]);
        $header .= "<a href='$next_20_url'>Next 20</a>";
      }
      $header .= ")";
    }
    $header .= "</h2>";

    $output->addHTML($header);
    $output->addWikiTextAsInterface($mwiki_results);

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
    $out .= "window.location.reload(true);";
    $out .= "'>";
    foreach(TorqueDataConnectConfig::getAvailableViews() as $view) {
      $selected = (array_key_exists("torqueview", $_COOKIE) && $view == $_COOKIE["torqueview"]) ? " selected=selected" : "";
      $out .= "<option $selected value='$view'>$view</option>";
    }
    $out .= "</select>";
    $out .= "</div>";
    $bar['View'] = $out;

    global $wgTorqueDataConnectConfigPage;

    $configPage = Title::newFromText($wgTorqueDataConnectConfigPage);
    if($wgTorqueDataConnectConfigPage && $configPage->exists()) {
      $bar["TOOLBOX"][] = [
        "msg" => "torquedataconnect-sidebar-configpage",
        "href" => $configPage->getLocalUrl()
      ];
    }

    return true;
  }

  public static function onBeforePageDisplay(OutputPage $out, Skin $skin) {
    $rights = $skin->getUser()->getRights();
    $script = "<script>window.userRights = [\"" . implode("\",\"", $rights) . "\"];</script>";
    $out->addScript($script);
  }
}

?>
