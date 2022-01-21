<?php

/*
 * Special page that allows the user to download csvs
 */

class TorqueDataConnectCsv extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueCSV');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectGroup, $wgTorqueDataConnectWikiKey, $wgTorqueDataConnectServerLocation,
      $wgTorqueDataConnectMultiWikiConfig, $wgTorqueDataConnectCollectionName;

    $this->getOutput()->addModules('ext.torquedataconnect.js');
    $this->getOutput()->addModuleStyles('ext.torquedataconnect.css');

    $wiki_key = $wgTorqueDataConnectWikiKey;
    $csv_information = false;
    $included_documents = false;

    if ($this->getRequest()->getVal('build')) {
      $url = $wgTorqueDataConnectServerLocation . '/csv';

      $ch = curl_init( $url );

      $documents = [];
      foreach ($this->getRequest()->getArray("document") as $document_str) {
        array_push($documents, explode("||", $document_str, 2));
      }
      $fields = $this->getRequest()->getArray("field");
      $payload = json_encode(
        array(
          "filename" => $this->getRequest()->getVal('filename'),
          "documents" => $documents,
          "fields" => $fields,
        )
      );
      curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
      curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
      # Return response instead of printing.
      curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
      # Send request.
      $resp = curl_exec($ch);
      $this->getOutput()->redirect("?c=" . json_decode($resp, true)["name"]);
    } else if($this->getRequest()->getVal('c')) {
/*      // If we're in multi wiki mode, set the wiki key for this collection
      if($wgTorqueDataConnectMultiWikiConfig &&
         array_key_exists($collection_name, $wgTorqueDataConnectMultiWikiConfig)) {
        $wiki_key = $wgTorqueDataConnectMultiWikiConfig[$collection_name];
      }*/

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL,
        $wgTorqueDataConnectServerLocation .
        '/csv/' .
        $this->getRequest()->getVal('c') .
        '.json' .
        "?group=" .
        $wgTorqueDataConnectGroup .
        "&wiki_key=" .
        $wiki_key);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $csv_information = json_decode(curl_exec($ch), true);
      $included_documents = $csv_information["documents"];
    } else if($this->getRequest()->getVal('s')) {
      $results = json_decode(file_get_contents(
        $wgTorqueDataConnectServerLocation .
        "/api/collections/" .  $wgTorqueDataConnectCollectionName . "/search.json" .
        "?group=" .  $wgTorqueDataConnectGroup .
        "&wiki_key=" .  $wgTorqueDataConnectWikiKey .
        "&q=" . urlencode($this->getRequest()->getVal('s')) .
        "&f=" . $this->getRequest()->getVal('f')
        ));

      $included_documents = [];
      foreach($results as $result) {
        array_push($included_documents, explode("/", $result, 5)[4]);
      }
      // Coming from search
    }

    if ($this->getRequest()->getVal('download')) {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL,
        $wgTorqueDataConnectServerLocation .
        '/csv/' .
        $this->getRequest()->getVal('c') .
        '.csv' .
        "?group=" .
        $wgTorqueDataConnectGroup .
        "&wiki_key=" .
        $wiki_key);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $resp = curl_exec($ch);

      header('Content-Type: ' . curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
      header('Content-Length: ' . curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD));
      header('Content-Disposition: inline; filename="' .  $csv_information["filename"] . '.csv"');
      print($resp);
      curl_close ($ch);

      $this->getOutput()->disable();
    } else {
      $ch = curl_init();
      curl_setopt(
        $ch,
        CURLOPT_URL,
        $wgTorqueDataConnectServerLocation .
        '/api/collections/' .
        $wgTorqueDataConnectCollectionName . '.json' .
        "?group=" .
        $wgTorqueDataConnectGroup .
        "&wiki_key=" .
        $wiki_key
      );

      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $collection_information = json_decode(curl_exec($ch), true);
      curl_close($ch);

      $documents_information = json_decode(file_get_contents(
        $wgTorqueDataConnectServerLocation .
        '/api/collections/' .
        $wgTorqueDataConnectCollectionName .
        '/documents.json' .
        "?group=" .
        $wgTorqueDataConnectGroup .
        "&wiki_key=" .
        $wiki_key
      ));

      $documents_as_templates = json_decode(file_get_contents(
        $wgTorqueDataConnectServerLocation .
        '/api/collections/' .
        $wgTorqueDataConnectCollectionName .
        '/documents.mwiki' .
        "?group=" .
        $wgTorqueDataConnectGroup .
        "&wiki_key=" .
        $wiki_key
      ), true);
      $out = $this->getOutput();

      $out->addHtml("<form method='POST'>\n");
      $out->addHtml("<input type='hidden' name='build' value='build'/>\n");
      if($csv_information) {
        $defaultfilename = $csv_information["filename"];
      } else {
        $defaultfilename = $wgTorqueDataConnectWikiKey . date("-Y-m-d");
      }
      $out->addHtml("<div>");
      $out->addHtml("<input type='text' name='filename' value='$defaultfilename'/>\n");
      $out->addHtml("<input type='submit' value='Download'/>\n");
      $out->addHtml("</div>");
      $out->addHtml("<div style='float:left;width:40%' class='fieldgroup'>");
      $csvFieldGroups = TorqueDataConnectConfig::getCsvFieldGroups();
      if(count($csvFieldGroups) > 0) {
        $out->addHtml("<select>");
        foreach(array_keys($csvFieldGroups) as $group) {
          $out->addHtml("<option value='$group'>$group</option>");
        }
        $out->addHtml("</select>");
        $out->addHtml("<button>Use</button><br>");
      }
      foreach ($collection_information["fields"] as $field) {
        $csv_groups = "";
        foreach($csvFieldGroups as $groupName => $group) {
          if(in_array($field, $group)) {
            $csv_groups = "$csv_groups|$groupName|";
          }
        }
        $checked = "checked=checked";
        if($csv_information && !array_search($field, $csv_information["fields"])) {
          $checked = "";
        }
        $out->addHtml( "<input type='checkbox' csvgroups='$csv_groups' name='field[]' value='$field' $checked>");
        $out->addHtml( "$field<br>");
      }
      $out->addHtml( "</div>");
      $out->addHtml("<div style='float:left;width:60%' class='documentgroup'>");
      $csvDocumentGroups = TorqueDataConnectConfig::getCsvDocumentGroups();
      if(count($csvDocumentGroups) > 0) {
        $out->addHtml("<select>");
        foreach(array_keys($csvDocumentGroups) as $group) {
          $out->addHtml("<option value='$group'>$group</option>");
        }
        $out->addHtml("</select>");
        $out->addHtml("<button>Use</button><br>");
      }
      foreach ($documents_information as $document) {
        $csv_groups = "";
        foreach($csvDocumentGroups as $groupName => $group) {
          if(in_array($document, $group)) {
            $csv_groups = "$csv_groups|$groupName|";
          }
        }
        $checked = "checked=checked";
        if($included_documents && !array_search($document, $included_documents)) {
          $checked = "";
        }
        $out->addHtml("<input type='checkbox' csvgroups='$csv_groups' name='document[]' value='$wgTorqueDataConnectCollectionName||$document' $checked>");
        $out->addHtml($out->parseInlineAsInterface($documents_as_templates[$document], false));
        $out->addHtml("<br>");
      }
      $out->addHtml( "</div>");
      $out->addHtml( "</form>\n");
      if($csv_information) {
         $out->addHtml( "<iframe style='visibility:hidden;position:absolute' src='?download=yes&c=" . $csv_information["name"] . "'></iframe>");
      }
    }
  }
}
