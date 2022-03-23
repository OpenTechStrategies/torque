<?php
class TorqueUserLookup {
  public static function lookupByUsername($username) {
    return Torque::get_json("users/username/$username");
  }
}
?>
