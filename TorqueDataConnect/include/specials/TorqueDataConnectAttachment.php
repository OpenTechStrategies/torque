<?php

/*
 * Special Page to download attachments from torque data server.
 *
 * We just use a simple passthrough with php_curl since it does all
 * the heavy lifting for us, and we trust torquedata to set all the
 * header information correctly for a given file (go flask!)
**/

class TorqueDataConnectAttachment extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueDataConnectAttachment');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectMultiWikiConfig;
    $id = $this->getRequest()->getVal('id');
    $attachment = $this->getRequest()->getVal('attachment');
    $collection_name = $this->getRequest()->getVal('collection_name');

    $wiki_key = $wgTorqueDataConnectWikiKey;

    // If we're in multi wiki mode, set the wiki key for this collection
    if($wgTorqueDataConnectMultiWikiConfig &&
       array_key_exists($collection_name, $wgTorqueDataConnectMultiWikiConfig)) {
      $wiki_key = $wgTorqueDataConnectMultiWikiConfig[$collection_name];
    }

    $resp = TorqueDataConnect::get_file(
      '/api/collections/' .
      $collection_name .
      '/documents/' .
      $id .
      '/attachments/' .
      urlencode($attachment),
      $attachment,
      $query_args=["wiki_key" => $wiki_key]);
    print($resp);

    $out = $this->getOutput()->disable();
  }
}

?>
