<?php
class TorqueDataConnectUserLookup {
  public static function lookupByUsername($username) {
    return TorqueDataConnect::get_json("users/username/$username");
  }
}
?>
