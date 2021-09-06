<?php

/*
 * Special page that lists available collection fields
 */

class TorqueDataConnectFields extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueDataConnectFields', 'torquedataconnect-admin');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectCollectionName;
    global $wgTorqueDataConnectServerLocation;

    $this->setHeaders();
    $this->checkPermissions();
    $ch = curl_init();
    curl_setopt(
      $ch,
      CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      '/api/collections/' .
      $wgTorqueDataConnectCollectionName . '.json'
    );
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $collection = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $out = "== " . $wgTorqueDataConnectCollectionName . " ==\n";

    // this assumes there is at least one document in the collection
    foreach ($collection["fields"] as $field) {
      $out .= "* " . $field . "\n";
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
