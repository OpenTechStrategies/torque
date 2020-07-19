<?php
class TorqueDataConnectUploadSheet extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $log = new LogPage('torquedataconnect-datachanges', false);
    $log->addEntry('sheetupload', $this->getTitle(), null);

    parent::checkUserRightsAny(["torquedataconnect-admin"]);
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $file = $this->getParameter("data_file");
    $data = [
      'data_file' => curl_file_create($file->getTempName()),
      'object_name' => $this->getParameter("object_name"),
      'sheet_name' => $this->getParameter("sheet_name"),
      'key_column' => $this->getParameter("key_column")
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/upload/sheet');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec ($ch);
    curl_close ($ch);
  }

  public function mustBePosted() {
    return true;
  }

  public function getAllowedParams() {
    return [
      "object_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "sheet_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "key_column" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "data_file" => [
        ApiBase::PARAM_TYPE => 'upload',
        ApiBase::PARAM_REQUIRED => 'true'
      ]
    ];
  }
}
?>
