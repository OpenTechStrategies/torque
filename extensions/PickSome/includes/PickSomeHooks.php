<?php
class PickSomeHooks {

  public static function onSidebarBeforeOutput(Skin $skin, &$bar) {
    global $wgPickSomePage;

    if (!$skin->getUser()->isLoggedIn()) {
      return true;
    }

    if (!$skin->getUser()->isAllowed("picksome")) {
      return true;
    }

    $page_url = '';
    if (!is_null($skin->getTitle()) && $skin->getTitle()->isKnown()) {
      $page_url = $skin->getTitle()->getFullText();
    }

    $picksome_links = [];
    $title = $skin->getTitle();

    if ($skin->getUser()->isAllowed("picksome-write")) {
      if(PickSomeSession::isEnabled()) {
        $picksome_links[] = [
          "msg" => "picksome-stop",
          "href" => SpecialPage::getTitleFor('PickSome')->getLocalUrl(
            ['cmd' => 'stop', 'returnto' => $page_url]
          )
        ];
      } else {
        $picksome_links[] = [
          "msg" => "picksome-start",
          "href" => SpecialPage::getTitleFor('PickSome')->getLocalUrl(
            ['cmd' => 'start', 'returnto' => $page_url]
          )
        ];
      }
    }

    $picksome_links[] = [
      "msg" => "picksome-all",
      "href" => SpecialPage::getTitleFor('PickSome')->getLocalUrl()
    ];

    $bar['picksome-title'] = $picksome_links;

    return true;
  }

  public static function siteNoticeAfter( &$siteNotice, $skin ) {
    global $wgPickSomePage;

    if(!PickSomeSession::isEnabled()) {
      return true;
    }

    if (!($skin->getUser()->isAllowed("picksome") && $skin->getUser()->isAllowed("picksome-write"))) {
      return true;
    }

    $title = $skin->getTitle();

    if (!$title->exists()) {
      return true;
    }

    if (!$skin->getUser()->isLoggedIn()) {
      return true;
    }

    $dbw = wfGetDB(DB_MASTER);
    $res = $dbw->select(
      'PickSome',
      ['page_id'],
      'user_id = ' . $skin->getUser()->getId()
    );

    $can_add = PickSome::canAdd($title);
    $can_remove = PickSome::canRemove($title);

    if(!($can_add || $can_remove)) {
      return true;
    }

    $page_id = $skin->getWikiPage()->getId();
    $selected_pages = [];
    foreach($res as $row) {
      $selected_pages[$row->page_id] = WikiPage::newFromID($row->page_id);
    }

    $siteNotice .= self::renderPickSomeBox($title, $selected_pages, $page_id, $can_add, $can_remove);
    return true;
  }

  # Rendering via string concatenation is not ideal, but how to
  # delegate to the mediawiki templating system deserves more
  # discussion.
  public static function renderPickSomeBox($title, $selected_pages, $page_id, $can_add, $can_remove) {
    global $wgPickSomeNumberOfPicks;
    $html = "";
    $html .= "<div style='border:1px solid black;padding:10px;text-align:left;margin-top:10px;background-color:#F2F2F2'>";
    $html .= "<h2 style='margin-top:0px;border-bottom:0px'>";
    $html .= "<span style='text-decoration:underline'>" . wfMessage("picksome-choices") . "</span>";
    $html .= "<span style='font-size:80%'> (<a href='";
    $html .= SpecialPage::getTitleFor('PickSome')->getLocalUrl(
      ['cmd' => 'stop',  'returnto' => $title->getFullText()]
    );
    $html .= "'>" . wfMessage("picksome-close-window") . "</a>)</span>";
    $html .= "</h2>";

    $page_already_selected = false;

    $html .= "<ul>";
    if(count($selected_pages) > 0) {
      $html .= "<li>" . wfMessage("picksome-my-picks") . " (" . count($selected_pages) . "/" . $wgPickSomeNumberOfPicks . ")";
      $html .= "<ul>";
      if(count($selected_pages) >= $wgPickSomeNumberOfPicks && !array_key_exists($page_id, $selected_pages)) {
        $html .= "<li style='font-style:italic'>" . wfMessage("picksome-remove-below");
      }
      foreach($selected_pages as $selected_page_id => $selected_page) {
        $html .= "<li>";
        if($page_id == $selected_page_id) {
          $html .= "<span style='font-style:italic'>(" . wfMessage("picksome-current") . ")</span> ";
        } else {
          $html .= "<a href='" . $selected_page->getTitle()->getLocalUrl() . "'>";
        }
        $html .= $selected_page->getTitle()->getPrefixedText();
        if($page_id != $selected_page_id) {
          $html .= "</a>";
        }
        if($can_remove) {
          $html .= " (<a href='";
          $html .= SpecialPage::getTitleFor('PickSome')->getLocalUrl(
            ['cmd' => 'remove', 'page' => $selected_page_id, 'returnto' => $page_id]
          );
          $html .= "'>" . wfMessage("picksome-unpick") . "</a>)";
          $html .= "\n";
        }
      }
      $html .= "</ul>";
    }
    if (!(array_key_exists($page_id, $selected_pages)) && !(count($selected_pages) >= $wgPickSomeNumberOfPicks) && $can_add) {
      $html .= "<li><a rel='nofollow' href='";
      $html .= SpecialPage::getTitleFor('PickSome')->getLocalUrl(
        ['cmd' => 'pick', 'page' => $page_id]
      );
      $html .= "'>Pick this page</a>";
      $html .= " [" . $title->getPrefixedText() . "]";
    }
    $html .= "<li><a href='";
    $html .= SpecialPage::getTitleFor('PickSome')->getLocalUrl();
    $html .= "'>" . wfMessage("picksome-view-all") . "</a>";
    $html .= "</ul>";

    $html .= "</div>";

    return $html;
  }

  public static function onLoadExtensionSchemaUpdates( $updater ) {
    $updater->addExtensionTable("PickSome", __DIR__ . "/../sql/picksome.sql");
  }
}
?>
