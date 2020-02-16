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
    global $wgTorqueDataConnectGroup;
    $id = $this->getRequest()->getVal('id');
    $attachment = $this->getRequest()->getVal('attachment');
    $sheet_name = $this->getRequest()->getVal('sheet_name');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 
      'http://localhost:5000/api/' .
      $sheet_name .
      '/attachment/' .
      $id .
      '/' .
      urlencode($attachment) .
      "?group=" .
      $wgTorqueDataConnectGroup);
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
