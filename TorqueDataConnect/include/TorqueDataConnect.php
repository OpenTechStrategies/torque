<?php
/*
 * The class that does that actual connecting.  With utilities
 * and whatnot for talking to the backend server.
 */
class TorqueDataConnect {
  private static function query_with_curl($uri, $query_args=[], $payload=false, $payload_json=true) {
    global $wgTorqueDataConnectServerLocation, $wgTorqueDataConnectGroup, $wgTorqueDataConnectWikiKey;

    if(!array_key_exists("wiki_key", $query_args)) {
      $query_args["wiki_key"] = $wgTorqueDataConnectWikiKey;
    }

    if(!array_key_exists("group", $query_args)) {
      $query_args["group"] = $wgTorqueDataConnectGroup;
    }

    $query = "?";
    foreach($query_args as $key => $value) {
      $query .= urlencode($key) . "=" . urlencode($value) . "&";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,
      $wgTorqueDataConnectServerLocation .
      $uri .
      $query);

    # Return response instead of printing.
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if($payload) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      if($payload_json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
      }
    }
    $resp = curl_exec ($ch);

    return [$ch, $resp];
  }

  public static function get_file($uri, $filename, $query_args=[], $disposition="inline") {
    [$ch, $resp] = TorqueDataConnect::query_with_curl($uri, $query_args);

    header('Content-Type: ' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    header('Content-Length: ' . curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD));
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');

    curl_close ($ch);

    return $resp;
  }

  public static function get_json($uri, $query_args=[]) {
    [$ch, $resp] = TorqueDataConnect::query_with_curl($uri, $query_args);
    curl_close ($ch);
    return json_decode($resp, True);
  }

  public static function post_json($uri, $payload, $query_args=[]) {
    [$ch, $resp] = TorqueDataConnect::query_with_curl($uri, $query_args, json_encode($payload));
    curl_close ($ch);
    return json_decode($resp, True);
  }
  
  public static function post_raw($uri, $payload, $query_args=[]) {
    [$ch, $resp] = TorqueDataConnect::query_with_curl($uri, $query_args, $payload, false);
    curl_close ($ch);
    return json_decode($resp, True);
  }

  public static function get_raw($uri, $query_args=[]) {
    [$ch, $resp] = TorqueDataConnect::query_with_curl($uri, $query_args);
    curl_close ($ch);
    return $resp;
  }
}
?>
