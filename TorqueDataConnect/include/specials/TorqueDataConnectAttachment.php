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
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig;
    $id = $this->getRequest()->getVal('id');
    $attachment = $this->getRequest()->getVal('attachment');
    $collection_name = $this->getRequest()->getVal('collection_name');

    $wiki_key = $wgTorqueDataConnectWikiKey;

    // If we're in multi wiki mode, set the wiki key for this collection
    if($wgTorqueDataConnectMultiWikiConfig &&
       array_key_exists($collection_name, $wgTorqueDataConnectMultiWikiConfig)) {
      $wiki_key = $wgTorqueDataConnectMultiWikiConfig[$collection_name];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      '/api/collections/' .
      $collection_name .
      '/documents/' .
      $id .
      '/attachments/' .
      urlencode($attachment) .
      "?group=" .
      $wgTorqueDataConnectGroup .
      "&wiki_key=" .
      $wiki_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec ($ch);

    header('Content-Type: ' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    header('Content-Length: ' . curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD));
    header('Content-Disposition: inline; filename="' . $attachment . '"');
    print($resp);
    curl_close ($ch);

    $out = $this->getOutput()->disable();
  }
}

?>
