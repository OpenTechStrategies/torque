<?php
class TorqueDataConnectConfig {
  private static $errors = [];

  public static function convertPageToColumnConfig($page) {
    $title = Title::newFromText($page);
    if(!$title->exists()) {
      array_push(self::$errors, $page . " does not exist, but is reference in config as a column config.");
      return [];
    }
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
    if(!$title->exists()) {
      array_push(self::$errors, $page . " does not exist, but is reference in config as a keys config.");
      return [];
    }
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
    if(!$title->exists()) {
      array_push(self::$errors, $mwikiPage . " does not exist, but is reference in config as a template.");
      return "";
    }
    $page = new WikiPage($title);
    return $page->getContent()->getText();
  }

  public static function commitGroupConfig($groupName, $columnPage, $proposalPage) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/config/group');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
      [
        "group" => $groupName,
        "columns" => TorqueDataConnectConfig::convertPageToColumnConfig($columnPage),
        "valid_ids" => TorqueDataConnectConfig::convertPageToIdConfig($proposalPage)
      ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function commitTemplateConfig($templateName, $templatePage, $templateType) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://localhost:5000/config/template');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
      [
        "name" => $templateName,
        "template" => TorqueDataConnectConfig::getMwikiTemplate($templatePage),
        "type" => $templateType
      ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function resetConfig() {
    file_get_contents("http://localhost:5000/config/reset");
  }

  private static function parseConfig() {
    global $wgTorqueDataConnectConfigPage;
    $configPage = Title::newFromText($wgTorqueDataConnectConfigPage);

    if(!$configPage->exists()) {
      array_push(self::$errors, "Config page ${wgTorqueDataConnectConfigPage} does not exist.");
      return [];
    }

    $configText = (new WikiPage($configPage))->getContent()->getText();

    $groupConfig = [];
    $templateConfig = [];
    // We have to parse this as best we can, because mediawiki doesn't really
    // give us a mwikiText->AST that we could loop over.
    //
    // First pass of parsing is just unrolling the state machine out
    // rather explicitly.  We could have a repertoire of regexes
    // and callbacks like a normal parser, but it's currently so simple
    // that the following is cleaner.
    $groupName = false;
    $columnPage = false;
    $proposalsPage = false;

    $templateName = false;
    $templatePage = false;
    $templateType = false;

    $permissionsSection = false;
    $templatesSection = false;

    foreach(explode("\n", $configText) as $line) {
      $line = trim($line);
      if(preg_match("/= *permissions *=/i", $line)) {
        $permissionsSection = true;
        $templatesSection = false;
      } else if(preg_match("/= *templates *=/i", $line)) {
        $templatesSection = true;
        $permissionsSection = false;
      } if(preg_match("/^\|\\-/", $line)) {
        if($permissionsSection) {
          $groupName = false;
          $columnPage = false;
          $proposalsPage = false;
        }else if($templatesSection) {
          $templateName = false;
          $templatePage = false;
          $templateType = false;
        }
      } else if (preg_match("/^\|/", $line)) {
        $line = trim(substr($line, 1));
        if($permissionsSection) {
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
          } else {
            // Do nothing here, since a fourth column is ok for user notes if they like
          }
          if($groupName && $columnPage && $proposalsPage) {
            array_push($groupConfig, [
              "groupName" => $groupName,
              "columnPage" => $columnPage,
              "proposalsPage" => $proposalsPage
            ]);
          }
        } else if($templatesSection) {
          if(!$templateName) {
            $templateName = $line;
          } else if(!$templatePage) {
            $matches = [];
            preg_match("/\\[\\[(.*)\\]\\]/", $line, $matches);
            $templatePage = $matches[1];
          } else if(!$templateType) {
            $templateType = $line;
          } else {
            // Do nothing here, since a fourth column is ok for user notes if they like
          }
          if($templateName && $templatePage && $templateType) {
            array_push($templateConfig, [
              "templateName" => $templateName,
              "templatePage" => $templatePage,
              "templateType" => $templateType
            ]);
          }
        }
      }

    }

    return [$groupConfig, $templateConfig];
  }

  public static function commitConfigToTorqueData() {
    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();


    TorqueDataConnectConfig::resetConfig();
    foreach($groupConfig as $group) {
      TorqueDataConnectConfig::commitGroupConfig(
        $group["groupName"],
        $group["columnPage"],
        $group["proposalsPage"]
      );
    }
    foreach($templateConfig as $template) {
      TorqueDataConnectConfig::commitTemplateConfig(
        $template["templateName"],
        $template["templatePage"],
        $template["templateType"]
      );
    }
  }

  public static function isConfigPage($title) {
    global $wgTorqueDataConnectConfigPage;
    if($title->equals(Title::newFromText($wgTorqueDataConnectConfigPage))) {
      return true;
    }

    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();
    foreach($groupConfig as $config) {
      if($title->equals(Title::newFromText($config["columnPage"])) ||
         $title->equals(Title::newFromText($config["proposalsPage"]))) {
        return true;
      }
    }
    foreach($templateConfig as $config) {
      if($title->equals(Title::newFromText($config["templatePage"]))) {
        return true;
      }
    }

    return false;
  }

  public static function checkForErrors() {
    self::$errors = [];
    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();
    foreach($groupConfig as $group) {
      TorqueDataConnectConfig::convertPageToColumnConfig($group["columnPage"]);
      TorqueDataConnectConfig::convertPageToIdConfig($group["proposalsPage"]);
    }
    return self::$errors;
  }

  public static function getValidGroup($user) {
    foreach(TorqueDataConnectConfig::parseConfig()[0] as $group) {
      if(in_array($group["groupName"], $user->getGroups())) {
        return $group["groupName"];
      }
    }
  }
}

?>
