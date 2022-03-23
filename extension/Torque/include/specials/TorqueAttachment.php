<?php

/*
 * Special Page to download attachments from torque data server.
 *
 * We just use a simple passthrough with php_curl since it does all
 * the heavy lifting for us, and we trust torque to set all the
 * header information correctly for a given file
**/

class TorqueAttachment extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueAttachment');
  }

  public function execute($subPage) {
    global $wgTorqueWikiKey, $wgTorqueMultiWikiConfig;
    $id = $this->getRequest()->getVal('id');
    $attachment = $this->getRequest()->getVal('attachment');
    $collection_name = $this->getRequest()->getVal('collection_name');

    $wiki_key = $wgTorqueWikiKey;

    // If we're in multi wiki mode, set the wiki key for this collection
    if($wgTorqueMultiWikiConfig &&
       array_key_exists($collection_name, $wgTorqueMultiWikiConfig)) {
      $wiki_key = $wgTorqueMultiWikiConfig[$collection_name];
    }

    $resp = Torque::get_file(
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
