<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location, $view = false) {
    $parser->disableCache();
    $po = $parser->getOutput();
    $po->addModules('ext.torquedataconnect.js');
    $po->addModuleStyles('ext.torquedataconnect.css');

    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectRenderToHTML, $wgTorqueDataConnectView,
      $wgTorqueDataConnectRaw, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;

    // Let the tdcrender view be top priority
    if(!$view) {
      $view = $wgTorqueDataConnectView;
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
      $wgTorqueDataConnectWikiKey .
      ($view ? "&view=" . $view : "")
      );
    
    $contents = $contents . '<span id="page-info" data-location="' . $location . '"></span>';

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
      $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;

    $results = file_get_contents(
      $wgTorqueDataConnectServerLocation .
      "/search/" .
      $wgTorqueDataConnectSheetName.
      "?group=" .
      $wgTorqueDataConnectGroup .
      "&wiki_key=" .
      $wgTorqueDataConnectWikiKey .
      "&q=" .
      urlencode($term)
      );
    $output->addWikiTextAsInterface($results);

    return false;
  }

  public static function onSidebarBeforeOutput(Skin $skin, &$bar) {
    global $wgTorqueDataConnectSidebarHTML;

    if ($wgTorqueDataConnectSidebarHTML == "") {
        return true;
    }

    $views = TorqueDataConnectConfig::getAvailableViews();
    if (empty($views)) {
        return true; // no need for a sidebar (issue #22)
    }

    $options = "";
    foreach ($views as $view) {
      if (array_key_exists("torqueview", $_COOKIE) && $view == $_COOKIE["torqueview"]) {
        $options .= "<option selected=selected value='$view'>$view</option>";
      } else {
        $options .= "<option value='$view'>$view</option>";
      }
    }

    $bar['View'] = preg_replace("/{{\s*options\s*}}/", $options, $wgTorqueDataConnectSidebarHTML);

    return true;
  }

  # This will stop working in mediawiki 1.35, however this is the only way to make this work
  # in mediawiki 1.33 which is what we're currently targeting.  The recommended way to attack
  # this in 1.35 isn't set up yet (using onSidebarBeforeOutput)
  #
  # See https://www.mediawiki.org/wiki/Manual:Hooks/BaseTemplateToolbox
  public static function onBaseTemplateToolbox(BaseTemplate $baseTemplate, array &$toolbox) {
    global $wgTorqueDataConnectConfigPage;

    $configPage = Title::newFromText($wgTorqueDataConnectConfigPage);
    if($wgTorqueDataConnectConfigPage && $configPage->exists()) {
      $toolbox["torqueconfig"] = [
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
