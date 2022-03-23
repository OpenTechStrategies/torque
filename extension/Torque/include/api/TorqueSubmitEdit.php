
<?php
class TorqueSubmitEdit extends APIBase {

  public function __construct($main, $action) {
    parent::__construct($main, $action);
  }

  public function execute() {
    $valid_group = TorqueConfig::getValidGroup($this->getUser());
    global $wgTorqueWikiKey, $wgTorqueMultiWikiConfig;

    parent::checkUserRightsAny(["torque-edit"]);
    $newValue = $this->getParameter('newValue');
    $field = $this->getParameter('field');
    $collectionName = $this->getParameter('collectionName');
    $wiki_key = $this->getParameter('wikiKey');
    $key = $this->getParameter('key');
    $title = Title::newFromText($this->getParameter('title'));
    $log = new LogPage('torque-datachanges',false);
    $log->addEntry('edit', $title, null, $field);

    // We only allow wiki_key to be passed in if it's in the multi wiki config
    if(!$wiki_key ||
        !(
          $wgTorqueMultiWikiConfig &&
          in_array($wiki_key, $wgTorqueMultiWikiConfig)
        )) {
      $wiki_key = $wgTorqueWikiKey;
    }

    $url = '/api/collections/' .
      $collectionName .
      "/documents/" .
      $key .
      "/fields/" .
      urlencode($field) .
      ".json";

    # Setup request to send json via POST.
    $payload = [
      "new_value" => $newValue,
      "wiki_key" => $wiki_key,
      "group" => $valid_group,
    ];
    $result = Torque::post_json($url, $payload);

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
      "collectionName" => [
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
