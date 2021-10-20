<?php
class TorqueDataConnectUploadAttachment extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    global $wgTorqueDataConnectServerLocation;
    parent::checkUserRightsAny(["torquedataconnect-admin"]);
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $attachment = $this->getParameter("attachment");
    $temp = tempnam(sys_get_temp_dir(), 'TDC');
    file_put_contents($temp, $attachment->getStream()->getContents());
    $data = [
      'attachment' => curl_file_create($temp),
      'collection_name' => $this->getParameter("collection_name"),
      'attachment_name' => $this->getParameter("attachment_name"),
      'permissions_field' => $this->getParameter("permissions_field"),
      'object_id' => $this->getParameter("object_id")
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$wgTorqueDataConnectServerLocation/upload/attachment");
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
      "attachment_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "permissions_field" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "object_id" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "collection_name" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "attachment" => [
        ApiBase::PARAM_TYPE => 'upload',
        ApiBase::PARAM_REQUIRED => 'true'
      ]
    ];
  }
}
?>
