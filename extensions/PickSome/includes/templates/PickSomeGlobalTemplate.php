<?php

class PickSomeGlobalTemplate extends QuickTemplate {
  public function execute() {
    global $wgUser;
  ?>
    <h2><?php echo wfMessage("picksome-my-picks"); ?></h2>
  <?php
    if(count($this->data['users_picked_pages']) == 0) {
      echo wfMessage("picksome-no-picks");
    } else {
  ?>
      <ul>
  <?php
      foreach($this->data['users_picked_pages'] as $picked_page) {
        echo "<li>";
        echo "<a href='" . $picked_page->getTitle()->getLocalUrl() . "'>";
        echo $picked_page->getTitle()->getPrefixedText();
        echo "</a>";
        echo "\n";
      }
    }
  ?>
  </ul>
  <h2><?php echo wfMessage("picksome-all"); ?></h2>
  <ul>
  <?php
    foreach($this->data['picked_pages'] as $picked_page) {
      echo "<li>";
      echo "<a href='" . $picked_page[0]->getTitle()->getLocalUrl() . "'>";
      echo $picked_page[0]->getTitle()->getPrefixedText();
      echo "</a>";
      echo " - (";
      $names = [];
      foreach($picked_page[1] as $user) {
        if($user->getRealName()) {
          $name = $user->getRealName();
        } else {
          $name = $user->getName();
        }

        $adminremove = '';
        if($wgUser->isAllowed("picksome-admin")) {
          $adminremove = '[';
          $adminremove .= "<a href='";
          $adminremove .= SpecialPage::getTitleFor('PickSome')->getLocalUrl(
            [
              'cmd' => 'adminremove',
              'page' => $picked_page[0]->getId(),
              'user' => $user->getId()
            ]
          );
          $adminremove .= "'>";
          $adminremove .= wfMessage("picksome-unpick");
          $adminremove .= "</a>";
          $adminremove .= ']';
        }

        array_push($names, $name . $adminremove);
      }
      echo join(", ", $names);
      echo ")";
    }
  ?>
  </ul>
  <?php
  }
}
?>
