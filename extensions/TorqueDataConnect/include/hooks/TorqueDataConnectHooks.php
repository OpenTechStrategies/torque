<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location) {
  	$parser->disableCache();

    global $wgTorqueDataConnectGroup;
    $contents = file_get_contents(
      "http://localhost:5000/api/" .
      $location .
      "?group=" .
      $wgTorqueDataConnectGroup
      );

    # The combination of recursiveTagParse and isHTML set to true
    # was the winning set of things to do to get the #evu tags from
    # the video extension to correctly dump out html that's correctly
    # rendered.
    return [$parser->recursiveTagParse($contents), "isHTML" => true];
	}

  public static function onPageContentSaveComplete(
    $wikiPage, $user, $mainContent, $summaryText, $isMinor, $isWatch, $section,
    &$flags, $revision, $status, $originalRevId, $undidRevId
  ) {
    global $wgTorqueDataConnectConfigPage;
    if($wikiPage->getTitle()->equals(Title::newFromText($wgTorqueDataConnectConfigPage))) {
      TorqueDataConnectConfig::commitConfigToTorqueData();
    }
  }

  public static function onBeforeInitialize(&$title, &$article = null, &$output, &$user, $request, $mediaWiki) {
    global $wgTorqueDataConnectGroup;
    if($user && !$wgTorqueDataConnectGroup) {
      $wgTorqueDataConnectGroup = TorqueDataConnectConfig::getValidGroup($user);
    }
  }
}

?>
