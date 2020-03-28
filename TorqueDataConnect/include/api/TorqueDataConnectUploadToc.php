<?php
class TorqueDataConnectUploadToc extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $json = $this->getParameter("json");
    $template = $this->getParameter("template");
    $data = [
      'json' => curl_file_create($json->getTempName()),
      'template' => curl_file_create($template->getTempName()),
      'sheet_name' => $this->getParameter("sheet_name"),
      'toc_name' => $this->getParameter("toc_name")
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/upload/toc');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec ($ch);
    curl_close ($ch);
  }

  public function mustBePosted() {
    return true;
  }

  public function getAllowedParams() {
    return [
      "toc_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "sheet_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "json" => [
        ApiBase::PARAM_TYPE => 'upload',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "template" => [
        ApiBase::PARAM_TYPE => 'upload',
        ApiBase::PARAM_REQUIRED => 'true'
      ]
    ];
  }
}
?>
