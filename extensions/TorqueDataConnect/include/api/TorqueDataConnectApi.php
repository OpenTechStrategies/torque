<?php
class TorqueDataConnectAPI extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $proposals = json_decode(file_get_contents("http://localhost:5000"));
    $this->getResult()->addValue(null, "proposals", $proposals);
  }

  public function getAllowedParams() {
    return [
      "path" => "/"
    ];
  }
}
?>
