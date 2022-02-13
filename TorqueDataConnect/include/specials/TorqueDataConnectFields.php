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

    $this->setHeaders();
    $this->checkPermissions();
    $collection = TorqueDataConnect::get_json(
      '/api/collections/' .
      $wgTorqueDataConnectCollectionName . '.json'
    );

    $out = "== " . $wgTorqueDataConnectCollectionName . " ==\n";

    // this assumes there is at least one document in the collection
    sort($collection["fields"]);
    foreach ($collection["fields"] as $field) {
      $out .= "* " . $field . "\n";
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
