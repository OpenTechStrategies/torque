<?php
class TorqueDataConnectQuery extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $log = new LogPage('torquedataconnect-apiaccess', false);
    $log->addEntry('apiaccess', $this->getTitle(), null, array($this->getParameter("path")));

    $valid_group = TorqueDataConnectConfig::getValidGroup($this->getUser());

    global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig, $wgTorqueDataConnectSheetName;

    $wiki_keys = "";
    $sheet_names = "";
    if($wgTorqueDataConnectMultiWikiConfig) {
      foreach($wgTorqueDataConnectMultiWikiConfig as $sheet_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $sheet_names .= "$sheet_name,";
      }
    } else {
      $wiki_keys .= "$wgTorqueDataConnectWikiKey";
      $sheet_names .= "$wgTorqueDataConnectSheetName";
    }

    $contents = file_get_contents(
      $wgTorqueDataConnectServerLocation .
      "/api" .
      $this->getParameter("path") .
      ".json" .
      "?group=" .
      $valid_group .
      "&wiki_key=" .
      $wgTorqueDataConnectWikiKey .
      "&wiki_keys=" . $wiki_keys .
      "&sheet_names=" . $sheet_names);

    $response = json_decode($contents);
    foreach($response as $name => $value) {
      $this->getResult()->addValue(null, $name, $value);
    }
  }

  public function getAllowedParams() {
    return [
      "path" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
    ];
  }
}
?>
