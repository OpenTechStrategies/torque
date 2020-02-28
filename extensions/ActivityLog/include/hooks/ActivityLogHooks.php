<?php

class ActivityLogExecutor {
  public $hookName;
  public $returnObject;

  public function __construct($hookName, $returnObject) {
    $this->hookName = $hookName;
    $this->returnObject = $returnObject;
  }

  public function execute(...$args) {
    global $wgTitle;
    $log = new LogPage('activitylog', false);
    $log->addEntry('activity', $wgTitle, $this->hookName);

    return $this->returnObject;
  }
}

class ActivityLogHooks {
  public static function onBeforeInitialize(...$args) {
    global $wgActivityLogHooksToWatch, $wgHooks;

    foreach($wgActivityLogHooksToWatch as $hookName => $returnObject) {
      $wgHooks[$hookName][] = [new ActivityLogExecutor($hookName, $returnObject), 'execute'];
    }
  }
}
