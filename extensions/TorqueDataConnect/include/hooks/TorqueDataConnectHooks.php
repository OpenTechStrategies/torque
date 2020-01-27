<?php

class TorqueDataConnectHooks {
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook('tdcrender', [ self::class, 'loadLocation' ]);
	}

	public static function loadLocation($parser, $location) {
  	$parser->disableCache();

    # The combination of recursiveTagParse and isHTML set to true
    # was the winning set of things to do to get the #evu tags from
    # the video extension to correctly dump out html that's correctly
    # rendered.
    return [$parser->recursiveTagParse(file_get_contents("http://localhost:5000/api/" . $location)), "isHTML" => true];
	}
}

?>
