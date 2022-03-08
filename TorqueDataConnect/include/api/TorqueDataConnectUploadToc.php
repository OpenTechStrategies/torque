<?php
class TorqueDataConnectUploadToc extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    parent::checkUserRightsAny(["torquedataconnect-admin"]);
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $json = $this->getParameter("json");
    $template = $this->getParameter("template");
    $jsontemp = tempnam(sys_get_temp_dir(), 'TDC');
    $templatetemp = tempnam(sys_get_temp_dir(), 'TDC');
    file_put_contents($jsontemp, $json->getStream()->getContents());
    file_put_contents($templatetemp, $template->getStream()->getContents());

    TorqueDataConnect::post_raw("/upload/toc",
      [
        'json' => curl_file_create($jsontemp),
        'template' => curl_file_create($templatetemp),
        'collection_name' => $this->getParameter("collection_name"),
        'toc_name' => $this->getParameter("toc_name"),
        'raw' => $this->getParameter("raw_toc")
      ]);
    unlink($jsontemp);
    unlink($templatetemp);
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
      "collection_name" => [
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
      ],
      "raw_toc" => [
        ApiBase::PARAM_TYPE => 'boolean',
        ApiBase::PARAM_REQUIRED => 'false'
      ]
    ];
  }
}
?>
