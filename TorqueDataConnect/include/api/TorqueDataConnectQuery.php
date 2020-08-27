<?php
class TorqueDataConnectQuery extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $log = new LogPage('torquedataconnect-apiaccess', false);
    $log->addEntry('apiaccess', $this->getTitle(), null, array($this->getParameter("path")));

    $valid_group = TorqueDataConnectConfig::getValidGroup($this->getUser());

    global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;

    $contents = file_get_contents(
      $wgTorqueDataConnectServerLocation;
      "/api" .
      $this->getParameter("path") .
      ".json" .
      "?group=" .
      $valid_group .
      "&wiki_key=" .
      $wgTorqueDataConnectWikiKey);

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
