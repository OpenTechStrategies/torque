<?php

/*
 * Special page that lists available collection fields
 */

class TorqueFields extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueFields', 'torque-admin');
  }

  public function execute($subPage) {
    global $wgTorqueCollectionName;

    $this->setHeaders();
    $this->checkPermissions();
    $collection = Torque::get_json(
      '/api/collections/' .
      $wgTorqueCollectionName . '.json'
    );

    $out = "== " . $wgTorqueCollectionName . " ==\n";

    // this assumes there is at least one document in the collection
    sort($collection["fields"]);
    foreach ($collection["fields"] as $field) {
      $out .= "* " . $field . "\n";
    }

    $this->getOutput()->addWikiTextAsInterface($out);
  }
}
