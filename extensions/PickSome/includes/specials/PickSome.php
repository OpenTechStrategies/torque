<?php

class PickSome extends SpecialPage {

  public function __construct() {
    parent::__construct( 'PickSome' );
  }

  public function execute( $subPage ) {
    switch ($this->getRequest()->getVal('cmd')) {
      case 'pick':
        $this->addPickToDb();
        $this->
          getOutput()->
          redirect(
            WikiPage::newFromID($this->getRequest()->getVal('page'))->getTitle()->getFullURL()
          );
        return;
      case 'start':
        PickSomeSession::enable();
        $this->
          getOutput()->
          redirect(
            Title::newFromText($this->getRequest()->getVal('returnto'))->getFullURL()
          );
        return;
      case 'stop':
        PickSomeSession::disable();
        $this->
          getOutput()->
          redirect(
            Title::newFromText($this->getRequest()->getVal('returnto'))->getFullURL()
          );
        return;
      case 'remove':
        $this->removePickFromDb();
        $this->
          getOutput()->
          redirect(
            WikiPage::newFromID($this->getRequest()->getVal('returnto'))->getTitle()->getFullURL()
          );
        return;
    }
    $this->renderPickSomePage();
  }

  private function renderPickSomePage() {
    $out = $this->getOutput();

    $this->setHeaders();
    $out->setPageTitle(wfMessage("picksome-global-list"));
    $template = new PickSomeGlobalTemplate();
    $template->set('users_picked_pages', $this->usersPickedPages());
    $template->set('picked_pages', $this->allPickedPages());
    $out->addTemplate($template);
  }

  private function addPickToDb() {
    $dbw = wfGetDB(DB_MASTER);
    $page = $this->getRequest()->getVal('page');
    $user_id = $this->getUser()->getId();
    if(!$this->alreadyPickedPage($page, $user_id, $dbw) &&
      !$this->alreadyPickedTwoPages($user_id, $dbw)) {

      $dbw->insert(
        "PickSome",
        [
          "page_id" => $page,
          "user_id" => $user_id
        ]);
    }
  }

  private function alreadyPickedPage($page, $user_id, $dbw) {
    return
      ($dbw->numRows(
        $dbw->select(
          "PickSome",
          ["page_id"],
          [
            "page_id" => $this->getRequest()->getVal('page'),
            "user_id" => $this->getUser()->getId()
          ]))
      > 0);
  }

  private function alreadyPickedTwoPages($user_id, $dbw) {
    global $wgPickSomeNumberOfPicks;
    return (count($this->usersPickedPages()) >= $wgPickSomeNumberOfPicks);
  }

  private function removePickFromDb() {
    $dbw = wfGetDB(DB_MASTER);
    $dbw->delete(
      "PickSome",
      [
        "page_id" => $this->getRequest()->getVal('page'),
        "user_id" => $this->getUser()->getId()
      ]);
  }

  private function allPickedPages() {
    $dbw = wfGetDB(DB_MASTER);
    $res = $dbw->select("PickSome", ["page_id", "user_id"]);
    $picked_pages = [];
    foreach($res as $row) {
      $page_id = $row->page_id;
      if(!array_key_exists($page_id, $picked_pages)) {
        $picked_pages[$page_id] = [WikiPage::newFromID($page_id), []];
      }

      array_push($picked_pages[$page_id][1], User::newFromID($row->user_id));
    }
    return $picked_pages;

  }

  private function usersPickedPages() {
    $dbw = wfGetDB(DB_MASTER);
    $res = $dbw->select(
      "PickSome",
      ["page_id"],
      ["user_id" => $this->getUser()->getId()]);
    $picked_pages = [];
    foreach($res as $row) {
      array_push($picked_pages, WikiPage::newFromID($row->page_id));
    }
    return $picked_pages;
  }
}
