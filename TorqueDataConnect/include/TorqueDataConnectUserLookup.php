<?php
class TorqueDataConnectUserLookup {
  public static function lookupByUsername($username) {
    $contents = file_get_contents("http://localhost:5000/users/username/$username");

    return json_decode($contents);
  }
}
?>
