
<?php
class TorqueDataConnectQueryCell extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $valid_group = TorqueDataConnectConfig::getValidGroup($this->getUser());
    global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;

    #/api/<sheet_name>/id/<key>/<field>
    $sheetName = $this->getParameter('sheetName');
    $key = $this->getParameter('key');
    $field = $this->getParameter('field');

    $url = $wgTorqueDataConnectServerLocation .
      '/api/' .
      $sheetName .
      '/id/' .
      $key . "/" .
      rawurlencode($field) .
      "?group=" . $valid_group .
      "&wiki_key=" . $wgTorqueDataConnectWikiKey;

    $contents = file_get_contents($url);

    $response = json_decode($contents);
    foreach($response as $name => $value) {
      $this->getResult()->addValue(null, $name, $value);
    }
  }

  public function getAllowedParams() {
    return [
      "sheetName" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "key" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "field" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
    ];
  }
}
?>
