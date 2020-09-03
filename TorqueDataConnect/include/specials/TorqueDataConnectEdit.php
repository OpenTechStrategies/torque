<?php
class TorqueDataConnectEdit extends SpecialPage {
	function __construct() {
		parent::__construct( 'TorqueDataConnectEdit' );
	}

	function execute( $par ) {
        global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;
		$request = $this->getRequest();
        $output = $this->getOutput();
        $output->setPageTitle("Torque Data Connect - View Field Edits");
        $output->addModules('ext.torquedataconnecteditspecial.js');
        $output->addModuleStyles('ext.torquedataconnecteditspecial.css');

        $sheetName = "DemoView";
        $url = $wgTorqueDataConnectServerLocation .
            '/api/' .
            $sheetName .
            '/edit-record/';
        $contents = file_get_contents($url);
        $response = json_decode($contents, true);
		$wikitable = '{| class="wikitable sortable"
|-
! Editor
! Field
! Key
! Date
! Value
|-
';
        # var_dump($response["edits"]);
        
        foreach($response["edits"] as $value) {
            # var_dump($value);
            $wikitable = $wikitable . 
            "| " . $value["editor"] .
            " || " . $value["field"] .
            " || [[" . $value["title"]["prefixedText"] . "]]" .
            " || " . $value["edit_timestamp"] .
            " || " . $value["new_value"] .
            " || <span data-field='" . $value["field"] . "' " .
                      "data-id='" . $value["id"] . "'>edit</span>";
            "\n|-\n";
        }
        $wikitable = $wikitable . '|}';
		$output->addWikiTextAsInterface( $wikitable );
	}
}