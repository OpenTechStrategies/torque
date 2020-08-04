<?php

/*
 * Special page that lists available spreadsheet columns
 */

class TorqueDataConnectColumns extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueDataConnectColumns', 'torquedataconnect-admin');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectGroup;
    global $wgTorqueDataConnectWikiKey;
    global $wgTorqueDataConnectSheetName;

    $this->setHeaders();
    $this->checkPermissions();
    $ch = curl_init();
    curl_setopt(
      $ch,
      CURLOPT_URL,
      'http:/localhost:5000/api/' .
      $wgTorqueDataConnectSheetName . '.json' .
      '?group=' . $wgTorqueDataConnectGroup .
      '&wiki_key=' . $wgTorqueDataConnectWikiKey
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $sheet = json_decode(curl_exec($ch), true)[$wgTorqueDataConnectSheetName];
    curl_close($ch);

    $out = "== " . $wgTorqueDataConnectSheetName . " ==\n";

    // this assumes there is at least one row in the spreadsheet
    foreach (array_keys($sheet[0]) as $column) {
      $out .= "* " . $column . "\n";
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
