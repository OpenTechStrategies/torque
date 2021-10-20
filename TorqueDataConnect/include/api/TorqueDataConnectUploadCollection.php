<?php
class TorqueDataConnectUploadCollection extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    global $wgTorqueDataConnectServerLocation;
    $log = new LogPage('torquedataconnect-datachanges', false);
    $log->addEntry('collectionupload', $this->getTitle(), null);

    parent::checkUserRightsAny(["torquedataconnect-admin"]);
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $file = $this->getParameter("data_file");
    $temp = tempnam(sys_get_temp_dir(), 'TDC');
    file_put_contents($temp, $file->getStream()->getContents());
    $data = [
      'data_file' => curl_file_create($temp),
      'object_name' => $this->getParameter("object_name"),
      'collection_name' => $this->getParameter("collection_name"),
      'key_field' => $this->getParameter("key_field")
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$wgTorqueDataConnectServerLocation/upload/collection");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec ($ch);
    curl_close ($ch);
    unlink($temp);
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
      "collection_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "key_field" => [
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
