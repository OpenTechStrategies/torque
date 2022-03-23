<?php

class TorqueHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location, $view = false, $wiki_key = false, $view_wiki_key = false) {
    $parser->getOutput()->updateCacheExpiry(0);
    $po = $parser->getOutput();
    $po->addModules('ext.torque.js');
    $po->addModuleStyles('ext.torque.css');

    global $wgTorqueGroup, $wgTorqueRenderToHTML, $wgTorqueView,
      $wgTorqueRaw, $wgTorqueWikiKey, $wgTorqueMultiWikiConfig;

    // Let the tdcrender view be top priority
    if(!$view || $view == "false") {
      $view = $wgTorqueView;
    } else if($view_wiki_key && $view_wiki_key != "false") {
      $view = json_encode(array("wiki_key" => $view_wiki_key, "view" => $view));
    } else if($wiki_key && $wiki_key != "false") {
      $view = json_encode(array("wiki_key" => $wiki_key, "view" => $view));
    }

    // We only allow wiki_key to be passed in if it's in the multi wiki config
    if(!$wiki_key ||
        !(
          $wgTorqueMultiWikiConfig &&
          in_array($wiki_key, $wgTorqueMultiWikiConfig)
        )) {
      $wiki_key = $wgTorqueWikiKey;
    }

    // If this isn't set, that means we've gotten here through some other means, and we
    // should just grab whatever group is correct for the current user.
    if(!$wgTorqueGroup) {
      $wgTorqueGroup = TorqueConfig::getValidGroup($parser->getUser());
    }

    // For legacy reasons, we strip off .mwiki if it's here.  Prior, it was correct
    // to specify the content type as the extensions from within the wiki pages
    // as part of the #tdcrender tag
    if(strlen($location) ? substr($location, -6) === ".mwiki" : false) {
      $location = substr($location, 0, strlen($location) - 6);
    }

    $path = "/api/" . "${location}";
    $query_args = ["wiki_key" => $wiki_key];
    if($view) {
      $query_args["view"] = $view;
    }

    // For now, this is only for the cached version
    $using_html = true;
    $contents = Torque::get_raw("${path}.html", $query_args);

    if(strlen($contents) === 0) {
      $using_html = false;
      $contents = Torque::get_raw("${path}.mwiki", $query_args);
    }
    
    $contents = $contents . '<span id="page-info" data-location="' . $location . '" data-wiki-key="' . $wiki_key . '"></span>';

    # If there are parser hooks in the output of the template, then
    # then we need to parse it fully, and let mediawiki know that
    # we're sending html as output.
    #
    # Discovered this when using the #evu tag which creates iframes
    # for videos.
    if(!$contents) {
      global $wgTorqueNotFoundMessage;
      return $wgTorqueNotFoundMessage;
    } else if($wgTorqueRaw || TorqueConfig::isRawView($view) || $using_html) {
      # We need to remove newlines and extra spaces because mediawiki adds a bunch of
      # <p> tags # when it hits them.  Since we want the output to be completely raw,
      # we trick mediawiki into doing just that by putting it all on one line
      $contents = preg_replace("/\s+/", " ", $contents);
      return [$contents, "isHTML" => true];
    } else if($wgTorqueRenderToHTML) {
      return [$parser->recursiveTagParseFully($contents), "isHTML" => true];
    } else {
      return $contents;
    }
	}

  public static function onPageContentSaveComplete(
    $wikiPage, $user, $mainContent, $summaryText, $isMinor, $isWatch, $section,
    &$flags, $revision, $status, $originalRevId, $undidRevId
  ) {
    global $wgTorqueConfigPage;
    if(TorqueConfig::isConfigPage($wikiPage->getTitle())) {
      TorqueConfig::commitConfigToTorque();
    }
  }

  public static function onBeforeInitialize(&$title, &$article = null, &$output, &$user, $request, $mediaWiki) {
    global $wgTorqueGroup, $wgTorqueView;
    if($user && !$wgTorqueGroup) {
      $wgTorqueGroup = TorqueConfig::getValidGroup($user);
    }
  }

  public static function siteNoticeAfter(&$siteNotice, $skin) {
    if (!$skin->getUser()->isAllowed("torque-admin")) {
      return true;
    }

    $configErrors = TorqueConfig::checkForErrors();

    if(sizeof($configErrors) > 0) {
      $html = "";
      $html .= "<div style='border:2px solid #AA3333;padding:10px;text-align:left;margin-top:10px;background-color:#FFEEEE'>";
      $html .= "<h2 style='margin-top:0px;border-bottom:0px'>" . wfMessage("torque-data-config-alert")->plain() . "</h2>";

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
    global $wgTorqueCollectionName, $wgTorqueMultiWikiConfig;

    $output->addModuleStyles('ext.torque.css');
    $output->addModules('ext.torque.js');

    $offset = $specialSearch->getRequest()->getInt("offset", 0);
    $query_args = [
      "offset" => $offset,
      "q" => $term
    ];

    $current_filter = [];
    if($specialSearch->getRequest()->getVal("f")) {
      $query_args["f"] = urldecode($specialSearch->getRequest()->getVal("f"));
      $current_filter = json_decode(urldecode($specialSearch->getRequest()->getVal("f")), true);
    }

    if($wgTorqueMultiWikiConfig) {
      $wiki_keys = "";
      $collection_names = "";
      foreach($wgTorqueMultiWikiConfig as $collection_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $collection_names .= "$collection_name,";
      }
      $query_args["collection_name"] = $wgTorqueCollectionName;
      $query_args["wiki_keys"] = $wiki_keys;
      $query_args["collection_names"] = $collection_names;
      $results = Torque::get_raw("/api/search.mwiki", $query_args);
    } else {
      $results = Torque::get_raw(
        "/api/collections/" .  $wgTorqueCollectionName . "/search.mwiki",
        $query_args);
    }
    $decoded_results = json_decode($results, true);
    $num_results = $decoded_results["num_results"];
    $filter_results = $decoded_results["filter_results"];
    $mwiki_results = $decoded_results["mwiki_text"];

    $header = "<h2>$num_results results for '$term'";
    if($num_results > 20) {
      $header .= " (viewing ";
      $header .= ($offset + 1) . " - " . min($num_results, ($offset + 20));
      if($offset > 0) {
        $header .= " | ";
        $prev_20_url = $output->getTitle()->getFullUrl(["offset" => $offset - 20, "search" => $term, "f" => urlencode(json_encode($current_filter, JSON_FORCE_OBJECT))]);
        $header .= "<a href='$prev_20_url'>Prev 20</a>";
      }
      if(($offset + 20) < $num_results) {
        $header .= " | ";
        $next_20_url = $output->getTitle()->getFullUrl(["offset" => $offset + 20, "search" => $term, "f" => urlencode(json_encode($current_filter, JSON_FORCE_OBJECT))]);
        $header .= "<a href='$next_20_url'>Next 20</a>";
      }
      $header .= ")";
    }
    $header .= "</h2>";

    $csv_download = "<div class='torque-searchcsv'><h1>";
    $csv_download .= wfMessage("torque-searchcsv-title");
    $csv_download .= "</h1>";
    $csv_download .= "<a href='";
    $csvPage = Title::newFromText("Special:TorqueCSV");
    $csv_download .= $csvPage->getFullUrl(["s" => $term, "f" => $specialSearch->getRequest()->getVal("f")]);
    $csv_download .= "'>";
    $csv_download .= wfMessage("torque-searchcsv-download");
    $csv_download .= "</a></div>";

    $filter_html = "<div class='torque-searchfilters'><h1>";
    $filter_html .= wfMessage("torque-filters");
    $filter_html .= "</h1>";
    $cleared_url = $output->getTitle()->getFullUrl(["offset" => 0, "search" => $term]);
    $filter_html .= "<a href='$cleared_url'>";
    $filter_html .= wfMessage("torque-filters-clear");
    $filter_html .= "</a>";
    foreach($filter_results as $filter_result) {
      $counts = $filter_result["counts"];
      $filter_html .= "<h3>" . $filter_result["display"] . "</h3>";
      foreach($counts as $count) {
        $name = $filter_result["name"];
        $filter_html .= "<div class='torque-filterresult'><span class='torque-filtername'>";
        $filter_html .= "<input autocomplete=off class='torque-filtercheckbox'";
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
          if($current_names) {
            array_push($current_names, $count["name"]);
          } else {
            $current_names = [$count["name"]];
          }
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
        $filter_html .= "</span> <span class='torque-filtercount'>(";
        $filter_html .= $count["total"];
        $filter_html .= ")</span></div>\n";
      }
    }
    $filter_html .= "</div>\n";

    $output->addHTML($header);

    $output->addHtml("<div class='torque-searchrightmenu'>");
    $output->addHTML($csv_download);
    $output->addHtml("<div class='torque-searchspacer'></div>");
    $output->addHTML($filter_html);
    $output->addHtml("</div>");
    $output->addWikiTextAsInterface($mwiki_results);

    return false;
  }

  public static function onSidebarBeforeOutput(Skin $skin, &$bar) {
    global $wgTorqueConfigPage;

    $configPage = Title::newFromText($wgTorqueConfigPage);
    $csvPage = Title::newFromText("Special:TorqueCSV");
    if($wgTorqueConfigPage && $configPage->exists()) {
      $bar["TOOLBOX"][] = [
        "msg" => "torque-sidebar-configpage",
        "id" => "t-torque-config",
        "href" => $configPage->getLocalUrl()
      ];
      $bar["TOOLBOX"][] = [
        "msg" => "torque-sidebar-csv",
        "id" => "t-torque-csv",
        "href" => $csvPage->getLocalUrl()
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
