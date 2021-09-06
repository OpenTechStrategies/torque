<?php

/*
 * Special page that lists available spreadsheet columns
 */

class TorqueDataConnectColumns extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueDataConnectColumns', 'torquedataconnect-admin');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectSheetName;
    global $wgTorqueDataConnectServerLocation;

    $this->setHeaders();
    $this->checkPermissions();
    $ch = curl_init();
    curl_setopt(
      $ch,
      CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      '/api/sheets/' .
      $wgTorqueDataConnectSheetName . '.json'
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $sheet = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $out = "== " . $wgTorqueDataConnectSheetName . " ==\n";

    // this assumes there is at least one row in the spreadsheet
    foreach ($sheet["columns"] as $column) {
      $out .= "* " . $column . "\n";
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
