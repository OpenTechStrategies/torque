<?php

class TorqueDataConnectConfig {
  public static function convertPageToColumnConfig($page) {
    $title = Title::newFromText($page);
    $page = new WikiPage($title);
    $columns = [];
    foreach(explode("\n", $page->getContent()->getText()) as $line) {
      $matches = [];
      if(preg_match("/^\* (.*)$/", $line, $matches)) {
        $columns[] = $matches[1];
      }
    }
    return $columns;
  }

  public static function convertPageToIdConfig($page) {
    $title = Title::newFromText($page);
    $page = new WikiPage($title);
    $ids = [];
    foreach(explode("\n", $page->getContent()->getText()) as $line) {
      $matches = [];
      if(preg_match("/^\* ([^:]*):.*$/", $line, $matches)) {
        $ids[] = $matches[1];
      }
    }
    return $ids;
  }

  public static function getMwikiTemplate($mwikiPage) {
    $title = Title::newFromText($mwikiPage);
    $page = new WikiPage($title);
    return $page->getContent()->getText();
  }

  public static function commitGroupConfig($groupName, $columnPage, $proposalPage, $mwikiPage) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/config/group');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
      [
        "group" => $groupName,
        "columns" => TorqueDataConnectConfig::convertPageToColumnConfig($columnPage),
        "valid_ids" => TorqueDataConnectConfig::convertPageToIdConfig($proposalPage),
        "template" => TorqueDataConnectConfig::getMwikiTemplate($mwikiPage)
      ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function commitConfigToTorqueData() {
    global $wgTorqueDataConnectConfigPage;
    $configPage = Title::newFromText($wgTorqueDataConnectConfigPage);
    $configText = (new WikiPage($configPage))->getContent()->getText();

    // We have to parse this as best we can, because mediawiki doesn't really
    // give us a mwikiText->AST that we could loop over.
    $groupName = false;
    $columnPage = false;
    $proposalsPage = false;
    $templatePage = false;

    foreach(explode("\n", $configText) as $line) {
      $line = trim($line);
      if(preg_match("/^\|\\-/", $line)) {
        $groupName = false;
        $columnPage = false;
        $proposalsPage = false;
        $templatePage = false;
      } else if (preg_match("/^\|/", $line)) {
        $line = trim(substr($line, 1));
        if(!$groupName) {
          $groupName = $line;
        } else if(!$columnPage) {
          $matches = [];
          preg_match("/\\[\\[(.*)\\]\\]/", $line, $matches);
          $columnPage = $matches[1];
        } else if(!$proposalsPage) {
          $matches = [];
          preg_match("/\\[\\[(.*)\\]\\]/", $line, $matches);
          $proposalsPage = $matches[1];
        } else if(!$templatePage) {
          $matches = [];
          preg_match("/\\[\\[(.*)\\]\\]/", $line, $matches);
          $templatePage = $matches[1];
        } else {
          // Do nothing here, since a fifth column is ok for user notes if they like
        }
      }

      if($groupName && $columnPage && $proposalsPage && $templatePage) {
        TorqueDataConnectConfig::commitGroupConfig($groupName, $columnPage, $proposalsPage, $templatePage);
      }
    }
  }
}

?>
