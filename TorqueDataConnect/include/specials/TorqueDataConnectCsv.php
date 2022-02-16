<?php

/*
 * Special page that allows the user to download csvs
 */

class TorqueDataConnectCsv extends SpecialPage {
  public function __construct() {
    parent::__construct('TorqueCSV');
  }

  public function execute($subPage) {
    global $wgTorqueDataConnectMultiWikiConfig, $wgTorqueDataConnectCollectionName;

    $this->getOutput()->addModules('ext.torquedataconnect.js');
    $this->getOutput()->addModuleStyles('ext.torquedataconnect.css');
    $this->getOutput()->setPageTitle("Torque CSV");

    $this->csv_information = false;
    $this->included_fields = false;
    $this->included_documents = false;

    if ($this->getRequest()->getVal('build')) {
      $this->buildCsvAndRedirect();
    } else if($this->getRequest()->getVal('c')) {
      $this->retrieveCsvInformation();
    } else if($this->getRequest()->getVal('s')) {
      $this->retrieveSearchInformation();
    }

    if ($this->getRequest()->getVal('download')) {
      $this->downloadCsv();
    } else {
      $this->createPage();
    }
  }

  private function buildCsvAndRedirect() {
    $documents = [];
    foreach ($this->getRequest()->getArray("document") as $document_str) {
      array_push($documents, explode("||", $document_str, 2));
    }
    $fields = $this->getRequest()->getArray("field");

    $payload = [
      "filename" => $this->getRequest()->getVal('filename'),
      "documents" => $documents,
      "fields" => $fields,
    ];

    $resp = TorqueDataConnect::post_json("/csv", $payload);
    $this->getOutput()->redirect("?c=" . $resp["name"]);
  }

  private function retrieveCsvInformation() {
    $this->csv_information = TorqueDataConnect::get_json(
      '/csv/' . $this->getRequest()->getVal('c') .  '.json'
    );
    $this->included_fields = $this->csv_information["fields"];
    $this->included_documents = $this->csv_information["documents"];
  }

  private function retrieveSearchInformation() {
    global $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectMultiWikiConfig;
    if($wgTorqueDataConnectMultiWikiConfig) {
      $wiki_keys = "";
      $collection_names = "";
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection_name => $wiki_key) {
        $wiki_keys .= "$wiki_key,";
        $collection_names .= "$collection_name,";
      }
      $results = TorqueDataConnect::get_json(
        "/api/search.json",
        [
          "collection_name" => $wgTorqueDataConnectCollectionName,
          "wiki_keys" => $wiki_keys,
          "collection_names" => $collection_names,
          "q" => $this->getRequest()->getVal('s'),
          "f" => urldecode($this->getRequest()->getVal('f'))
        ]);
    } else {
      $results = TorqueDataConnect::get_json(
        "/api/collections/" .  $wgTorqueDataConnectCollectionName . "/search.json",
        [
          "q" => $this->getRequest()->getVal('s'),
          "f" => urldecode($this->getRequest()->getVal('f'))
        ]);
    }

    $this->included_documents = [];
    foreach($results as $result) {
      $exploded_result = explode("/", $result, 5);
      $collection = $exploded_result[2];
      if(!array_key_exists($collection, $this->included_documents)) {
        $this->included_documents[$collection] = [];
      }
      array_push($this->included_documents[$collection], $exploded_result[4]);
    }
  }

  private function downloadCsv() {
    $resp = TorqueDataConnect::get_file(
      '/csv/' .  $this->getRequest()->getVal('c') .  '.csv',
      $this->csv_information["filename"] . ".csv",
      [],
      "attachment"
    );
    print($resp);
    $this->getOutput()->disable();
  }

  private function createPage() {
    global $wgTorqueDataConnectMultiWikiConfig, $wgTorqueDataConnectCollectionName, $wgTorqueDataConnectWikiKey;
    if($wgTorqueDataConnectMultiWikiConfig) {
      $documents_information = [];
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection => $wiki) {
        $documents_information[$collection] = TorqueDataConnect::get_json(
          '/api/collections/' .  $collection .  '/documents.json',
          $query_args = ["wiki_key" => $wiki ]
        );
      }

      $documents_as_templates = [];
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection => $wiki) {
        $documents_as_templates[$collection] = TorqueDataConnect::get_json(
          '/api/collections/' .  $collection .  '/documents.mwiki',
          $query_args = ["wiki_key" => $wiki]
        );
      }

      $fields = [];
      foreach($wgTorqueDataConnectMultiWikiConfig as $collection => $wiki) {
        $fields = array_merge(
          $fields,
          TorqueDataConnect::get_json(
            '/api/collections/' .  $collection . '.json',
            $query_args=["wiki_key" =>  $wiki]
          )["fields"]);
      }
      $fields = array_unique($fields);
    } else {
      $documents_information = [];
      $documents_information[$wgTorqueDataConnectCollectionName] = TorqueDataConnect::get_json(
        '/api/collections/' . $wgTorqueDataConnectCollectionName . '/documents.json'
      );
      $documents_as_templates = [];
      $documents_as_templates[$wgTorqueDataConnectCollectionName] = TorqueDataConnect::get_json(
        '/api/collections/' . $wgTorqueDataConnectCollectionName . '/documents.mwiki'
      );

      $fields = TorqueDataConnect::get_json('/api/collections/' . $wgTorqueDataConnectCollectionName . '.json')["fields"];
    }
    sort($fields);

    $out = $this->getOutput();

    $action = $out->getTitle()->getFullUrl();
    $out->addHtml("<div class='torquedataconnectcsv'>");
    $out->addHtml("<form method='POST' action='$action'>\n");
    $out->addHtml("<input type='hidden' name='build' value='build'/>\n");
    if($this->csv_information) {
      $defaultfilename = $this->csv_information["filename"];
    } else {
      $defaultfilename = $wgTorqueDataConnectWikiKey . date("-Y-m-d");
    }
    $out->addHtml("<div class='csvheader'>");
    $out->addHtml(wfMessage("torquedataconnect-csv-filename"));
    $out->addHtml(": <input class='filename' type='text' name='filename' value='$defaultfilename'/>\n");
    $out->addHtml("<input class='download' type='submit' value='" . wfMessage("torquedataconnect-csv-download") ."'/>\n");
    $out->addHtml("</div>");
    $out->addHtml("<div class='fieldgroup'>");
    $out->addHtml("<h1>" . wfMessage("torquedataconnect-csv-field-header") . "</h1>");
    $csvFieldGroups = TorqueDataConnectConfig::getCsvFieldGroups();
    $use_default = false;
    foreach($csvFieldGroups as $groupName => $group) {
      if(!$this->included_fields && $groupName == "Default") {
        $this->included_fields = $group;
        $use_default = true;
      }
    }

    $out->addHtml("<select autocomplete=off>");
    $out->addHtml("<option value='All'");
    if(!$this->included_fields && !$use_default) {
      $out->addHtml(" selected");
    }
    $out->addHtml(">" . wfMessage('torquedataconnect-csv-all-fields') . "</option>");
    $out->addHtml("<option value='None'>" . wfMessage('torquedataconnect-csv-none-fields') . "</option>");
    foreach(array_keys($csvFieldGroups) as $group) {
      $out->addHtml("<option value='$group'");
      if($use_default && $group == "Default") {
        $out->addHtml(" selected");
      }
      $out->addHtml(">");
      $out->addHtml(wfMessage('torquedataconnect-csv-template-fields', $group));
      $out->addHtml("</option>");
    }
    $out->addHtml("<option value='Custom'");
    if($this->included_fields && !$use_default) {
      $out->addHtml(" selected");
    }
    $out->addHtml(">" . wfMessage('torquedataconnect-csv-custom-fields') . "</option>");
    $out->addHtml("</select><br>");
    $primary_fields = "";
    $secondary_fields = "";
    foreach ($fields as $field) {
      $csv_groups = "|All|";
      foreach($csvFieldGroups as $groupName => $group) {
        if(in_array($field, $group)) {
          $csv_groups = "$csv_groups|$groupName|";
        }
      }
      if($this->included_fields) {
        if(array_search($field, $this->included_fields) !== False) {
          $primary_fields .= "<input type='checkbox' csvgroups='$csv_groups' name='field[]' value='$field' checked=checked>";
          $primary_fields .= "$field<br>";
        } else {
          $secondary_fields .= "<input type='checkbox' csvgroups='$csv_groups' name='field[]' value='$field'>";
          $secondary_fields .= "$field<br>";
        }
      } else {
        $primary_fields .= "<input type='checkbox' csvgroups='$csv_groups' name='field[]' value='$field' checked=checked>";
        $primary_fields .= "$field<br>";
      }
    }
    $out->addHtml($primary_fields);
    $out->addHtml($secondary_fields);
    $out->addHtml("</div>");
    $out->addHtml("<div class='documentgroup'>");
    $out->addHtml("<h1>" . wfMessage("torquedataconnect-csv-document-header") . "</h1>");


    $csvDocumentGroups = TorqueDataConnectConfig::getCsvDocumentGroups();
    $use_default = false;
    foreach($csvDocumentGroups as $groupName => $group) {
      if(!$this->included_documents && $groupName == "Default") {
        $this->included_documents = $group;
        $use_default = true;
      }
    }

    $out->addHtml("<select autocomplete=off>");
    $out->addHtml("<option value='All'");
    if(!$this->included_documents && !$use_default) {
      $out->addHtml(" selected");
    }
    $out->addHtml(">" . wfMessage('torquedataconnect-csv-all-documents') . "</option>");
    $out->addHtml("<option value='None'>" . wfMessage('torquedataconnect-csv-none-documents') . "</option>");
    foreach(array_keys($csvDocumentGroups) as $group) {
      $out->addHtml("<option value='$group'");
      if($use_default && $group == "Default") {
        $out->addHtml(" selected");
      }
      $out->addHtml(">");
      $out->addHtml(wfMessage('torquedataconnect-csv-template-documents', $group));
      $out->addHtml("</option>");
    }
    $out->addHtml("<option value='Custom'");
    if($this->included_documents && !$use_default) {
      $out->addHtml(" selected");
    }
    $out->addHtml(">" . wfMessage('torquedataconnect-csv-custom-documents') . "</option>");
    $out->addHtml("</select><br>");

    foreach ($documents_information as $collection => $documents) {
      $templates = $documents_as_templates[$collection];
      foreach($documents as $document) {
        $csv_groups = "|All|";
        foreach($csvDocumentGroups as $groupName => $group) {
          if(in_array($document, $group)) {
            $csv_groups = "$csv_groups|$groupName|";
          }
        }

        if(!$this->included_documents ||
           (array_key_exists($collection, $this->included_documents) &&
            array_search($document, $this->included_documents[$collection]) !== false)) {
          $out->addHtml("<input type='checkbox' csvgroups='$csv_groups' name='document[]' value='$collection||$document' checked=checked>");
          $out->addHtml("<span class='torquedataconnect-csv-collection-name'>");
          $out->addHtml($collection . ": ");
          $out->addHtml("</span>");
          if(array_key_exists($document, $templates)) {
            $out->addHtml($templates[$document]);
          } else {
            $out->addHtml($document);
          }
          $out->addHtml("<br>");
        }
      }
    }
    $out->addHtml("</div>");
    $out->addHtml("</form>\n");
    if($this->csv_information) {
       $src = $out->getTitle()->getFullUrl(["download" => "yes", "c" => $this->csv_information["name"]]);
       $out->addHtml("<iframe src='" . $src . "'></iframe>");
    }
    $out->addHtml("</div>");
  }
}
