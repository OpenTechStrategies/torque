<?php
class TorqueDataConnectConfig {
  public static $errors = [];

  public static function convertPagesToFieldConfig($pages) {
    $fields = [];
    foreach($pages as $page) {
      $title = Title::newFromText($page);
      if(!$title->exists()) {
        array_push(self::$errors, wfMessage("torquedataconnect-convert-field-page-ne", $page)->parse());
        return [];
      }
      $page = new WikiPage($title);
      foreach(explode("\n", $page->getContent()->getText()) as $line) {
        $matches = [];
        if(preg_match("/^\* (.*)$/", $line, $matches)) {
          $fields[] = $matches[1];
        }
      }
    }
    return $fields;
  }

  public static function convertPagesToIdConfig($pages) {
    $ids = [];
    foreach($pages as $page) {
      $title = Title::newFromText($page);
      if(!$title->exists()) {
        array_push(self::$errors, wfMessage("torquedataconnect-convert-id-page-ne", $page)->parse());
        return [];
      }
      $page = new WikiPage($title);
      foreach(explode("\n", $page->getContent()->getText()) as $line) {
        $matches = [];
        if(preg_match("/^\* ([^:]*):.*$/", $line, $matches)) {
          $ids[] = $matches[1];
        }
      }
    }
    return $ids;
  }

  public static function getMwikiTemplate($mwikiPage) {
    $title = Title::newFromText($mwikiPage);
    if(!$title->exists()) {
      array_push(self::$errors, wfMessage("torquedataconnect-mwiki-template-ne", $mwikiPage)->parse());
      return "";
    }
    $page = new WikiPage($title);
    return $page->getContent()->getText();
  }

  public static function commitGroupConfig($groupName, $fieldsPages, $proposalPages) {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      "/config/" .
      $wgTorqueDataConnectCollectionName .
      "/" .
      $wgTorqueDataConnectWikiKey .
      "/group");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
      [
        "group" => $groupName,
        "wiki_key" => $wgTorqueDataConnectWikiKey,
        "fields" => TorqueDataConnectConfig::convertPagesToFieldConfig($fieldsPages),
        "valid_ids" => TorqueDataConnectConfig::convertPagesToIdConfig($proposalPages)
      ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function commitTemplateConfig($templateName, $templatePage, $templateType) {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      "/config/" .
      $wgTorqueDataConnectCollectionName .
      "/" .
      $wgTorqueDataConnectWikiKey .
      "/template");

    // Raw View is a special type that's a view that's treated differently on the mediawiki side
    if($templateType == "Raw View") {
      $templateType = "View";
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
      [
        "name" => $templateName,
        "wiki_key" => $wgTorqueDataConnectWikiKey,
        "template" => TorqueDataConnectConfig::getMwikiTemplate($templatePage),
        "type" => $templateType
      ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function resetConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;
    file_get_contents(
      $wgTorqueDataConnectServerLocation .
      "/config/" .
      $wgTorqueDataConnectCollectionName .
      "/" .
      $wgTorqueDataConnectWikiKey .
      "/reset");
  }

  public static function commitWikiConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectWikiUsername, $wgTorqueDataConnectWikiPassword, $wgServer, $wgScriptPath;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      "/config/" .
      $wgTorqueDataConnectCollectionName .
      "/" .
      $wgTorqueDataConnectWikiKey .
      "/wiki");

    $data = [
      "username" => $wgTorqueDataConnectWikiUsername,
      "password" => $wgTorqueDataConnectWikiPassword,
      "script_path" => $wgScriptPath,
      "server" => $wgServer
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_exec ($ch);
    curl_close ($ch);
  }

  public static function completeConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation;
    file_get_contents(
      $wgTorqueDataConnectServerLocation .
      "/config/" .
      $wgTorqueDataConnectCollectionName .
      "/" .
      $wgTorqueDataConnectWikiKey .
      "/complete");
  }

  private static function parseConfig() {
    global $wgTorqueDataConnectConfigPage;
    if(!$wgTorqueDataConnectConfigPage) {
      array_push(self::$errors, wfMessage("torquedataconnect-config-ns", $wgTorqueDataConnectConfigPage)->parse());
      return [];
    }

    $configPage = Title::newFromText($wgTorqueDataConnectConfigPage);

    if(!$configPage->exists()) {
      array_push(self::$errors, wfMessage("torquedataconnect-config-ne", $wgTorqueDataConnectConfigPage)->parse());
      return [];
    }

    $configText = (new WikiPage($configPage))->getContent()->getText();

    $permissionsParser = new PermissionsSectionParser();
    $templateParser = new TemplatesSectionParser();
    $parser = false;

    foreach(explode("\n", $configText) as $line) {
      $line = trim($line);
      if(preg_match("/= *permissions *=/i", $line)) {
        $parser = $permissionsParser;
      } else if(preg_match("/= *templates *=/i", $line)) {
        $parser = $templateParser;
      } else if($parser) {
        $parser->parseLine($line);
      }
    }

    return [$permissionsParser->getConfig(), $templateParser->getConfig()];
  }

  public static function commitConfigToTorqueData() {
    global $wgTorqueDataConnectCache;
    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();

    TorqueDataConnectConfig::resetConfig();
    if($wgTorqueDataConnectCache) {
      TorqueDataConnectConfig::commitWikiConfig();
    }

    foreach($groupConfig as $group) {
      TorqueDataConnectConfig::commitGroupConfig(
        $group["groupName"],
        $group["fieldsPages"],
        $group["idsPages"]
      );
    }
    foreach($templateConfig as $template) {
      TorqueDataConnectConfig::commitTemplateConfig(
        $template["templateName"],
        $template["templatePage"],
        $template["templateType"]
      );
    }
    TorqueDataConnectConfig::completeConfig();
  }

  public static function isConfigPage($title) {
    global $wgTorqueDataConnectConfigPage;
    if($title->equals(Title::newFromText($wgTorqueDataConnectConfigPage))) {
      return true;
    }

    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();
    foreach($groupConfig as $config) {
      if($title->equals(Title::newFromText($config["fieldsPages"])) ||
         $title->equals(Title::newFromText($config["idsPages"]))) {
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
      TorqueDataConnectConfig::convertPagesToFieldConfig($group["fieldsPages"]);
      TorqueDataConnectConfig::convertPagesToIdConfig($group["idsPages"]);
    }
    return self::$errors;
  }

  public static function getAvailableViews() {
    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();
    $views = [];
    foreach($templateConfig as $config) {
      if($config["templateType"] == "View") {
        $views[] = $config["templateName"];
      }
    }
    return $views;
  }

  public static function isRawView($view) {
    [$groupConfig, $templateConfig] = TorqueDataConnectConfig::parseConfig();
    foreach($templateConfig as $config) {
      if($config["templateName"] == $view && $config["templateType"] == "Raw View") {
        return true;
      }
    }
    return false;
  }

  public static function getCurrentView() {
    if(array_key_exists("torqueview", $_COOKIE)) {
      $cookieView = $_COOKIE["torqueview"];

      if(in_array($cookieView, self::getAvailableViews())) {
        return $cookieView;
      }
    }

    return false;
  }

  public static function getValidGroup($user) {
    $manager = MediaWiki\MediaWikiServices::getInstance()->getUserGroupManager();
    $wikiGroups = $manager->getUserGroups($user);
    foreach(TorqueDataConnectConfig::parseConfig()[0] as $group) {
      if(in_array($group["groupName"], $wikiGroups) {
        return $group["groupName"];
      }
    }
  }
}

class PermissionsSectionParser {
  private $groupName = false;
  private $fieldPages = [];
  private $idsPages = [];
  private $groupConfig = [];
  private $cell = "";

  public function parseLine($line) {
    if (preg_match("/^\|/", $line)) {
      if($this->cell) {
        if(!$this->groupName) {
          $this->groupName = $this->cell;
        } else if(!$this->fieldPages) {
          foreach(explode("\n", $this->cell) as $potential) {
            $matches = [];
            if(preg_match("/\\[\\[(.*)\\]\\]/", $potential, $matches)) {
              array_push($this->fieldPages, $matches[1]);
            }
          }
        } else if(!$this->idsPages) {
          foreach(explode("\n", $this->cell) as $potential) {
            $matches = [];
            if(preg_match("/\\[\\[(.*)\\]\\]/", $potential, $matches)) {
              array_push($this->idsPages, $matches[1]);
            }
          }
        } else {
          // Do nothing here, since a fourth field is ok for user notes if they like
        }

        if($this->groupName && $this->fieldPages && $this->idsPages) {
          array_push($this->groupConfig, [
            "groupName" => $this->groupName,
            "fieldsPages" => $this->fieldPages,
            "idsPages" => $this->idsPages
          ]);
        }
      }

      $this->cell = trim(substr($line, 1));

      if(preg_match("/^\|\\-/", $line)) {
        $this->groupName = false;
        $this->fieldPages = [];
        $this->idsPages = [];
        $this->cell = "";
      }
    } else {
      $this->cell .= "\n" . $line;
    }
  }

  public function getConfig() {
    return $this->groupConfig;
  }
}

class TemplatesSectionParser {
  private $templateName = false;
  private $templatePage = false;
  private $templateType = false;
  private $templateConfig = [];
  private static $validTemplateTypes = ["View", "TOC", "Search", "Raw View"];

  public function parseLine($line) {
    if(preg_match("/^\|\\-/", $line)) {
      $this->templateName = false;
      $this->templatePage = false;
      $this->templateType = false;
    } else if (preg_match("/^\|/", $line)) {
      $line = trim(substr($line, 1));
      if(!$this->templateName) {
        $this->templateName = $line;
      } else if(!$this->templatePage) {
        $matches = [];
        if(preg_match("/\\[\\[(.*)\\]\\]/", $line, $matches)) {
          $this->templatePage = $matches[1];
        }
      } else if(!$this->templateType) {
        $this->templateType = $line;

        if(in_array($this->templateType, self::$validTemplateTypes)) {
          array_push($this->templateConfig, [
            "templateName" => $this->templateName,
            "templatePage" => $this->templatePage,
            "templateType" => $this->templateType
          ]);
        } else {
          array_push(TorqueDataConnectConfig::$errors, wfMessage("torquedataconnect-config-it", $templateType)->parse());
        }
      }
    }
  }

  public function getConfig() {
    return $this->templateConfig;
  }
}
?>
