
<?php
class TorqueDataConnectSubmitEdit extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $valid_group = TorqueDataConnectConfig::getValidGroup($this->getUser());
    global $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig;

    parent::checkUserRightsAny(["torquedataconnect-edit"]);
    $newValue = $this->getParameter('newValue');
    $field = $this->getParameter('field');
    $sheetName = $this->getParameter('sheetName');
    $wiki_key = $this->getParameter('wikiKey');
    $key = $this->getParameter('key');
    $title = Title::newFromText($this->getParameter('title'));
    $log = new LogPage('torquedataconnect-datachanges',false);
    $log->addEntry('edit', $title, null, $field);

    // We only allow wiki_key to be passed in if it's in the multi wiki config
    if(!$wiki_key ||
        !(
          $wgTorqueDataConnectMultiWikiConfig &&
          in_array($wiki_key, $wgTorqueDataConnectMultiWikiConfig)
        )) {
      $wiki_key = $wgTorqueDataConnectWikiKey;
    }

    $url = $wgTorqueDataConnectServerLocation .
      '/api/sheets/' .
      $sheetName .
      "/rows/" .
      $key .
      "/fields/" .
      urlencode($field) .
      ".json";

    $ch = curl_init( $url );
    # Setup request to send json via POST.
    $payload = json_encode(
      array(
        "new_value" => $newValue,
        "wiki_key" => $wiki_key,
        "group" => $valid_group,
      )
    );
    curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    # Return response instead of printing.
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    # Send request.
    $result = curl_exec($ch);
    curl_close($ch);

    $parser = \MediaWiki\MediaWikiServices::getInstance()->getParser();
    $text = (new WikiPage($title))->getContent()->getText();
    $po= $parser->parse($text, $title, ParserOptions::newFromUser($this->getUser()));
    $this->getResult()->addValue(null, 'html', $po->getText());
  }

  public function mustBePosted() {
    return true;
  }

  public function getAllowedParams() {
    return [
      "newValue" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "field" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "sheetName" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "wikiKey" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "key" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
      "title" => [
        ApiBase::PARAM_TYPE => 'string',
        ApiBase::PARAM_REQUIRED => 'true'
      ],
    ];
  }
}
?>
