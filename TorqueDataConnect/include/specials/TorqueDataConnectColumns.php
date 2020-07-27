<?php

/*
 * Special page that lists available spreadsheet columns
 */

class TorqueDataConnectColumns extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueDataConnectColumns');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectWikiKey;
    $this->setHeaders();
    $ch = curl_init();
    curl_setopt(
      $ch,
      CURLOPT_URL,
      'http:/localhost:5000/api/sheets' .
      '?group=' . $wgTorqueDataConnectGroup .
      '&wiki_key=' . $wgTorqueDataConnectWikiKey
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $sheets = json_decode(curl_exec($ch), true);

    $out = '';

    foreach ($sheets as $sheet) {
      $out .= "== " . $sheet['name'] . " ==\n";
      foreach ($sheet['columns'] as $column) {
        $out .= "* " . $column . "\n";
      }
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
