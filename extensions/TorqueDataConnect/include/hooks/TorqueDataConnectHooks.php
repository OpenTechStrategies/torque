<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location) {
  	$parser->disableCache();

    $user = $parser->getUser();

    $valid_group = "";
    foreach($user->getGroups() as $group) {
      if(in_array($group, ["LFCTorque", "LFCTorqueAdmin", "PseudoBoardMembers", "BoardMembers", "LFCConsultants"])) {
        $valid_group = $group;
        break;
      }
    }

    $contents = file_get_contents(
      "http://localhost:5000/api/" .
      $location .
      "?group=" .
      $valid_group
      );

    # The combination of recursiveTagParse and isHTML set to true
    # was the winning set of things to do to get the #evu tags from
    # the video extension to correctly dump out html that's correctly
    # rendered.
    return [$parser->recursiveTagParse($contents), "isHTML" => true];
	}
}

?>
