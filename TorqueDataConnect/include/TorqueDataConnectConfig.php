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
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey;
    TorqueDataConnect::post_json(
      "/config/" .  $wgTorqueDataConnectCollectionName .  "/" .  $wgTorqueDataConnectWikiKey .  "/group",
      [
        "group" => $groupName,
        "fields" => TorqueDataConnectConfig::convertPagesToFieldConfig($fieldsPages),
        "valid_ids" => TorqueDataConnectConfig::convertPagesToIdConfig($proposalPages)
      ]
    );
  }

  public static function commitTemplateConfig($templateName, $templatePage, $templateType, $default) {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey;

    // Raw View is a special type that's a view that's treated differently on the mediawiki side
    if($templateType == "Raw View") {
      $templateType = "View";

      // Raw views can't be the default view, because then there would be two default
      // views (if there's already another View).  If Raw View is the only kind that
      // exists, then this will break, and we will need to add more logic.
      $default = False;
    }

    TorqueDataConnect::post_json(
      "/config/" .  $wgTorqueDataConnectCollectionName .  "/" .  $wgTorqueDataConnectWikiKey .  "/template",
      [
        "name" => $templateName,
        "template" => TorqueDataConnectConfig::getMwikiTemplate($templatePage),
        "type" => $templateType,
        "default" => $default
      ]);
  }

  public static function resetConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey;
    TorqueDataConnect::get_raw(
      "/config/" .  $wgTorqueDataConnectCollectionName .  "/" .  $wgTorqueDataConnectWikiKey .  "/reset"
    );
  }

  public static function commitWikiConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey,
      $wgTorqueDataConnectWikiUsername, $wgTorqueDataConnectWikiPassword, $wgServer, $wgScriptPath;

    TorqueDataConnect::post_raw(
      "/config/" .  $wgTorqueDataConnectCollectionName .  "/" .  $wgTorqueDataConnectWikiKey .  "/wiki",
      [
        "username" => $wgTorqueDataConnectWikiUsername,
        "password" => $wgTorqueDataConnectWikiPassword,
        "script_path" => $wgScriptPath,
        "server" => $wgServer
      ]);
  }

  public static function completeConfig() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey;
    TorqueDataConnect::get_raw(
      "/config/" .  $wgTorqueDataConnectCollectionName .  "/" .  $wgTorqueDataConnectWikiKey .  "/complete"
    );
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
    $csvGroupsParser = new CsvGroupsSectionParser();
    $parser = false;

    foreach(explode("\n", $configText) as $line) {
      $line = trim($line);
      if(preg_match("/= *permissions *=/i", $line)) {
        $parser = $permissionsParser;
      } else if(preg_match("/= *templates *=/i", $line)) {
        $parser = $templateParser;
      } else if(preg_match("/= *csv groups *=/i", $line)) {
        $parser = $csvGroupsParser;
      } else if($parser) {
        $parser->parseLine($line);
      }
    }

    return [$permissionsParser->getConfig(), $templateParser->getConfig(), $csvGroupsParser->getConfig()];
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
    $typesSeen = [];
    foreach($templateConfig as $template) {
      $default = False;
      if(array_search($template["templateType"], $typesSeen) === False) {
        $default = True;
        array_push($typesSeen, $template["templateType"]);
      }
      TorqueDataConnectConfig::commitTemplateConfig(
        $template["templateName"],
        $template["templatePage"],
        $template["templateType"],
        $default
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
    [$groupConfig, $templateConfig, $csvGroupsConfig] = TorqueDataConnectConfig::parseConfig();
    foreach($groupConfig as $group) {
      TorqueDataConnectConfig::convertPagesToFieldConfig($group["fieldsPages"]);
      TorqueDataConnectConfig::convertPagesToIdConfig($group["idsPages"]);
    }
    foreach($csvGroupsConfig as $csvGroup) {
      if($csvGroup["groupType"] == "Field") {
        TorqueDataConnectConfig::convertPagesToFieldConfig($csvGroup["groupPages"]);
      }
      if($csvGroup["groupType"] == "Document") {
        TorqueDataConnectConfig::convertPagesToIdConfig($csvGroup["groupPages"]);
      }
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

  public static function getValidGroup($user) {
    $manager = MediaWiki\MediaWikiServices::getInstance()->getUserGroupManager();
    $wikiGroups = $manager->getUserGroups($user);
    foreach(TorqueDataConnectConfig::getConfiguredGroups() as $group) {
      if(in_array($group, $wikiGroups)) {
        return $group;
      }
    }
  }

  public static function getConfiguredGroups() {
    $groupNames = [];
    foreach(TorqueDataConnectConfig::parseConfig()[0] as $group) {
      array_push($groupNames, $group["groupName"]);
    }
    return $groupNames;
  }

  public static function getCsvFieldGroups() {
    $groups = [];
    foreach(TorqueDataConnectConfig::parseConfig()[2] as $csvGroup) {
      if($csvGroup["groupType"] == "Field") {
        $groups[$csvGroup["groupName"]] = TorqueDataConnectConfig::convertPagesToFieldConfig($csvGroup["groupPages"]);
      }
    }
    return $groups;
  }

  public static function getCsvDocumentGroups() {
    $groups = [];
    foreach(TorqueDataConnectConfig::parseConfig()[2] as $csvGroup) {
      if($csvGroup["groupType"] == "Document") {
        $groups[$csvGroup["groupName"]] = TorqueDataConnectConfig::convertPagesToIdConfig($csvGroup["groupPages"]);
      }
    }
    return $groups;
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
  private static $validTemplateTypes = ["View", "TOC", "CSV", "Search", "Raw View"];

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

class CsvGroupsSectionParser {
  private $groupType = false;
  private $groupName = false;
  private $groupPages = [];
  private $cell = "";
  private $csvGroupsConfig = [];
  private static $validGroupTypes = ["Field", "Document"];

  public function parseLine($line) {
    if (preg_match("/^\|/", $line)) {
      if($this->cell) {
        if(!$this->groupName) {
          $this->groupName = $this->cell;
        } else if(!$this->groupType) {
          $this->groupType = $this->cell;
          if(!in_array($this->groupType, self::$validGroupTypes)) {
            # We continue on here, because it doesn't hurt anything to do so,
            # as it's just used by the csv special page, and will end up being discarded
            # from a functional point of view.
            # 
            # We still need to error about it!
            array_push(TorqueDataConnectConfig::$errors, wfMessage("torquedataconnect-config-gt", $this->groupType)->parse());
          }
        } else if(!$this->groupPages) {
          foreach(explode("\n", $this->cell) as $potential) {
            $matches = [];
            if(preg_match("/\\[\\[(.*)\\]\\]/", $potential, $matches)) {
              array_push($this->groupPages, $matches[1]);
            }
          }
        } else {
          // Do nothing here, since a fourth field is ok for user notes if they like
        }

        if($this->groupType && $this->groupPages && $this->groupName) {
          array_push($this->csvGroupsConfig, [
            "groupName" => $this->groupName,
            "groupType" => $this->groupType,
            "groupPages" => $this->groupPages
          ]);
        }
      }

      $this->cell = trim(substr($line, 1));

      if(preg_match("/^\|\\-/", $line)) {
        $this->groupName = false;
        $this->groupPages = [];
        $this->groupType = false;
        $this->cell = "";
      }
    } else {
      $this->cell .= "\n" . $line;
    }
  }

  public function getConfig() {
    return $this->csvGroupsConfig;
  }
}
?>
