<?php
/**
 * Updates MODS for items from EVLD's Past Perfect collection
 *
 * Created by PhpStorm.
 * User: jfields
 * Date: 6/1/2016
 * Time: 10:13 AM
 */
header( 'Content-type: text/html; charset=utf-8' );

define ('ROOT_DIR', __DIR__);
date_default_timezone_set('America/Denver');

ini_set('display_errors', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

ini_set('implicit_flush', true);


$config = parse_ini_file(ROOT_DIR . '/config.ini');
$sourceXMLFile =  $config['sourceXMLFile'];
$fedoraPassword =  $config['fedoraPassword'];
$fedoraUser =  $config['fedoraUser'];
$fedoraUrl =  $config['fedoraUrl'];
$solrUrl =  $config['solrUrl'];


require_once(ROOT_DIR . '/tuque/Cache.php');
require_once(ROOT_DIR . '/tuque/FedoraApi.php');
require_once(ROOT_DIR . '/tuque/FedoraApiSerializer.php');
require_once(ROOT_DIR . '/tuque/Object.php');
require_once(ROOT_DIR . '/tuque/HttpConnection.php');
require_once(ROOT_DIR . '/tuque/Repository.php');
require_once(ROOT_DIR . '/tuque/RepositoryConnection.php');
require_once(ROOT_DIR . '/utils.php');

//Connect to tuque
try {
    $serializer = new FedoraApiSerializer();
    $cache = new SimpleCache();

    $connection = new RepositoryConnection($fedoraUrl, $fedoraUser, $fedoraPassword);
    $connection->verifyPeer = false;
    $api = new FedoraApi($connection, $serializer);
    $repository = new FedoraRepository($api, $cache);
    echo("Connected to Tuque OK");

}catch (Exception $e) {
    echo("We could not connect to the fedora repository.");
    die;

}

//Open XML file

$xml = simplexml_load_file($sourceXMLFile);
if (!$xml){
    echo("Failed to read XML, boo");
}else{

//Loop through each file in XML file
//$recordsProcessed = 0;
//$recordsRead = 0;

//For each record, find the record in Islandora using a Solr query (158 to 191 in evld_past_perfect.php


    foreach ($xml->export as $exportedItem) {
        $objectId = (string)$exportedItem->objectid;
        $solrQuery = "?q=mods_identifier_t:$objectId&fl=PID,dc.title,RELS_EXT_hasModel_uri_s";
       // $context = stream_context_create(array(
         //   'http' => array(
           //     'header' => "Authorization: Basic " . base64_encode("$fedoraUser:$fedoraPassword")
            //)
        //)
   // );

        $solrResponse = file_get_contents($solrUrl . $solrQuery, false);
        if (!$solrResponse) {
            die();
        } else {
            $solrResponse = json_decode($solrResponse);
            $existingPID = $solrResponse->response->docs[0]->PID;
            //Find the same object in Fedora using Tuque
            $fedoraObject = $repository->getObject($existingPID);
            //Get a copy of MODS record for the object from Fedora
            $MODS = $fedoraObject->getDatastream('MODS');
            $MODScontent = $MODS->content;
            //Parse the MODS record using simple XML
            $MODSxml=simplexml_load_file($MODScontent, 'SimpleXmlElement', 0, 'http://www.loc.gov/mods/v3', false);




        }
    }}









//Modify the MODS record to do what we want

//Save the MODS record back to Fedora

//Repeat for all objects in the collection

