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
      $wgTorqueDataConnectGroup = TorqueDataConnectConfig::getValidGroup($parser->getUser());
    }

    // For legacy reasons, we strip off .mwiki if it's here.  Prior, it was correct
    // to specify the content type as the extensions from within the wiki pages
    // as part of the #tdcrender tag
    if(strlen($location) ? substr($location, -6) === ".mwiki" : false) {
      $location = substr($location, 0, strlen($location) - 6);
    }

    $path = $wgTorqueDataConnectServerLocation . "/api/" . "${location}";
    $args = "group=" . $wgTorqueDataConnectGroup .
      "&wiki_key=" . $wiki_key .
      ($view ? "&view=" . $view : "");

    // For now, this is only for the cached version
    $using_html = true;
    $contents = file_get_contents("${path}.html?${args}");

    if(strlen($contents) === 0) {
      $using_html = false;
      $contents = file_get_contents("${path}.mwiki?${args}");
    }
    
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
    } else if($wgTorqueDataConnectRaw || TorqueDataConnectConfig::isRawView($view) || $using_html) {
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
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectCollectionName,
      $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig;

    $output->addModuleStyles('ext.torquedataconnect.css');
    $output->addModules('ext.torquedataconnect.js');

    $offset = $specialSearch->getRequest()->getInt("offset", 0);

    if($wgTorqueDataConnectMultiWikiConfig) {
      $wiki_keys = "";
      $collection_names = "";
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $collection_names .= "$collection_name,";
      }
      $results = file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api/search.mwiki" .
        "?group=" . $wgTorqueDataConnectGroup .
        "&wiki_key=" .  $wgTorqueDataConnectWikiKey .
        "&collection_name=" . $wgTorqueDataConnectCollectionName .
        "&wiki_keys=" . $wiki_keys .
        "&collection_names=" . $collection_names .
        "&offset=" . $offset .
        "&q=" .  urlencode($term) .
        "&f=" . $specialSearch->getRequest()->getVal("f")
        );
    } else {
      $results = file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api/collections/" .  $wgTorqueDataConnectCollectionName . "/search.mwiki" .
        "?group=" .  $wgTorqueDataConnectGroup .
        "&wiki_key=" .  $wgTorqueDataConnectWikiKey .
        "&offset=" . $offset .
        "&q=" . urlencode($term) .
        "&f=" . $specialSearch->getRequest()->getVal("f")
        );
    }
    $decoded_results = json_decode($results, true);
    $num_results = $decoded_results["num_results"];
    $filter_results = $decoded_results["filter_results"];
    $mwiki_results = $decoded_results["mwiki_text"];

    $current_filter = json_decode(urldecode($specialSearch->getRequest()->getVal("f")), true);
    if(!$current_filter) {
      $current_filter = [];
    }

    $header = "<h2>$num_results results for '$term'";
    if($num_results > 20) {
      $header .= " (viewing ";
      $header .= ($offset + 1) . " - " . min($num_results, ($offset + 20));
      if($offset > 0) {
        $header .= " | ";
        $prev_20_url = $output->getTitle()->getFullUrl(["offset" => $offset - 20, "search" => $term, "f" => urlencode(json_encode($current_filter))]);
        $header .= "<a href='$prev_20_url'>Prev 20</a>";
      }
      if(($offset + 20) < $num_results) {
        $header .= " | ";
        $next_20_url = $output->getTitle()->getFullUrl(["offset" => $offset + 20, "search" => $term, "f" => urlencode(json_encode($current_filter))]);
        $header .= "<a href='$next_20_url'>Next 20</a>";
      }
      $header .= ")";
    }
    $header .= "</h2>";

    $filter_html = "<div class='torquedataconnect-searchfilters'><h1>";
    $filter_html .= wfMessage("torquedataconnect-filters");
    $filter_html .= "</h1>";
    $cleared_url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term]);
    $filter_html .= "<a href='$cleared_url'>";
    $filter_html .= wfMessage("torquedataconnect-filters-clear");
    $filter_html .= "</a>";
    foreach($filter_results as $filter_result) {
      $counts = $filter_result["counts"];
      $filter_html .= "<h3>" . $filter_result["display"] . "</h3>";
      foreach($counts as $count) {
        $name = $filter_result["name"];
        $filter_html .= "<div class='torquedataconnect-filterresult'><span class='torquedataconnect-filtername'>";
        $filter_html .= "<input autocomplete=off class='torquedataconnect-filtercheckbox'";
        if(in_array($count["name"], $current_filter[$name])) {
          $filter_html .= " checked=checked";
          $link_filter = $current_filter;
          $current_names = $current_filter[$name];
          $idx = array_search($count["name"], $current_names);
          unset($current_names[$idx]);
          $link_filter[$name] = $current_names;
          $filter_url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term, "f" => urlencode(json_encode($link_filter))]);
          $url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term, "f" => urlencode(json_encode($link_filter))]);
          $filter_html .= " url='$url'";
        } else {
          $link_filter = $current_filter;
          $current_names = $current_filter[$name];
          array_push($current_names, $count["name"]);
          $link_filter[$name] = $current_names;
          $url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term, "f" => urlencode(json_encode($link_filter))]);
          $filter_html .= " url='$url'";
        }
        $filter_html .=  " type='checkbox'>";
        $link_filter = $current_filter;
        $link_filter[$name] = [$count["name"]];
        $filter_url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term, "f" => urlencode(json_encode($link_filter))]);
        $filter_html .= "<a href='$filter_url'>";
        $filter_html .= $count["name"];
        $filter_html .= "</a>";
        $filter_html .= "</span> <span class='torquedataconnect-filtercount'>(";
        $filter_html .= $count["total"];
        $filter_html .= ")</span></div>\n";
      }
    }
    $filter_html .= "</div>\n";

    $output->addHTML($header);
    $output->addHTML($filter_html);
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
