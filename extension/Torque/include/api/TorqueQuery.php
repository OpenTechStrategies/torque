<?php
class TorqueQuery extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $log = new LogPage('torque-apiaccess', false);
    $log->addEntry('apiaccess', $this->getTitle(), null, array($this->getParameter("path")));

    $valid_group = TorqueConfig::getValidGroup($this->getUser());

    global $wgTorqueWikiKey, $wgTorqueMultiWikiConfig,
      $wgTorqueCollectionName;

    $wiki_keys = "";
    $collection_names = "";
    if($wgTorqueMultiWikiConfig) {
      foreach($wgTorqueMultiWikiConfig as $collection_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $collection_names .= "$collection_name,";
      }
    } else {
      $wiki_keys .= "$wgTorqueWikiKey";
      $collection_names .= "$wgTorqueCollectionName";
    }

    if($this->getParameter("new_value") !== null) {
      parent::checkUserRightsAny(["torque-edit"]);
      Torque::post_json(
        '/api' .  urlencode($this->getParameter("path")) .  ".json",
        [
          "new_value" => json_decode($this->getParameter("new_value")),
          "wiki_key" => $wgTorqueWikiKey,
          "wiki_keys" => $wiki_keys,
          "collection_names" => $collection_names,
          "group" => $valid_group,
        ]);
      $this->getResult()->addValue(null, "result", "Success");
    } else {
      $query_args = [
        "group" => $valid_group,
        "collection_name" => $wgTorqueCollectionName,
        "wiki_keys" => $wiki_keys,
        "collection_names" => $collection_names
      ];
      if($this->getParameter("q") !== null) {
        $query_args["q"] = urlencode($this->getParameter("q"));
      }

      $response = Torque::get_json(
        "/api" .  urlencode($this->getParameter("path")) .  ".json",
        $query_args);
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
