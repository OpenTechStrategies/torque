<?php
class TorqueDataConnectUploadAttachment extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    parent::checkUserRightsAny(["torquedataconnect-admin"]);
    # We use phpcurl here because it's really straightforward, and
    # research (stackoverflow) didn't produce a compelling native php method.
    $attachment = $this->getParameter("attachment");
    $data = [
      'attachment' => curl_file_create($attachment->getTempName()),
      'sheet_name' => $this->getParameter("sheet_name"),
      'attachment_name' => $this->getParameter("attachment_name"),
      'permissions_column' => $this->getParameter("permissions_column"),
      'object_id' => $this->getParameter("object_id")
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/upload/attachment');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec ($ch);
    curl_close ($ch);
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
      "permissions_column" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "object_id" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "sheet_name" => [
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
