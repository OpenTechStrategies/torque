<?php
class TorqueDataConnectUserLookup {
  public static function lookupByUsername($username) {
    global $wgTorqueDataConnectServerLocation;
    $contents = file_get_contents("$wgTorqueDataConnectServerLocation/users/username/$username");

    return json_decode($contents);
  }
}
?>
