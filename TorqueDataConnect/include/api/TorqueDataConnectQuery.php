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
      $wgTorqueDataConnectMultiWikiConfig, $wgTorqueDataConnectCollectionName;

    $wiki_keys = "";
    $collection_names = "";
    if($wgTorqueDataConnectMultiWikiConfig) {
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $collection_names .= "$collection_name,";
      }
    } else {
      $wiki_keys .= "$wgTorqueDataConnectWikiKey";
      $collection_names .= "$wgTorqueDataConnectCollectionName";
    }

    if($this->getParameter("new_value") !== null) {
      parent::checkUserRightsAny(["torquedataconnect-edit"]);
      $url = $wgTorqueDataConnectServerLocation .
        '/api' .
        urlencode($this->getParameter("path")) .
        ".json";

      $ch = curl_init( $url );
      # Setup request to send json via POST.
      $payload = json_encode(
        array(
          "new_value" => $this->getParameter("new_value"),
          "wiki_key" => $wgTorqueDataConnectWikiKey,
          "wiki_keys" => $wiki_keys,
          "collection_names" => $collection_names,
          "group" => $valid_group,
        )
      );
      curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
      curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
      # Return response instead of printing.
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
      # Send request.
      curl_exec($ch);
      $this->getResult()->addValue(null, "result", "Success");
    } else {
      $extra_args = "";
      if($this->getParameter("q") !== null) {
        $extra_args .= "&q=" . urlencode($this->getParameter("q"));
      }
      $contents = file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api" .
        urlencode($this->getParameter("path")) .
        ".json" .
        "?group=" .
        $valid_group .
        "&wiki_key=" .
        $wgTorqueDataConnectWikiKey .
        "&collection_name=" . $wgTorqueDataConnectCollectionName .
        "&wiki_keys=" . $wiki_keys .
        "&collection_names=" . $collection_names .
        $extra_args);

      $response = json_decode($contents);
      $this->getResult()->addValue(null, "result", $response);
    }
  }

  public function getAllowedParams() {
    return [
      "path" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "new_value" => [
        ApiBase::PARAM_TYPE => 'string'
      ],
      "q" => [
        ApiBase::PARAM_TYPE => 'string'
      ],
    ];
  }
}
?>
