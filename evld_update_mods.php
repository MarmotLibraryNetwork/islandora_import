<?php
/**
 * Updates MODS for items from EVLD's Past Perfect collection
 *
 * Created by PhpStorm.
 * User: jfields
 * Date: 6/1/2016
 * Time: 10:13 AM
 */
header('Content-type: text/html; charset=utf-8');

define('ROOT_DIR', __DIR__);
date_default_timezone_set('America/Denver');

ini_set('display_errors', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

ini_set('implicit_flush', true);


$config = parse_ini_file(ROOT_DIR . '/config.ini');
$sourceXMLFile = $config['sourceXMLFile'];
$fedoraPassword = $config['fedoraPassword'];
$fedoraUser = $config['fedoraUser'];
$fedoraUrl = $config['fedoraUrl'];
$solrUrl = $config['solrUrl'];
$oldModsLocation = $config['oldModsLocation'];
if (!file_exists($oldModsLocation)){
	mkdir($oldModsLocation);
}
$newModsLocation = $config['newModsLocation'];
if (!file_exists($newModsLocation)){
	mkdir($newModsLocation);
}

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
	echo("Connected to Tuque OK<br/>\r\n");

} catch (Exception $e) {
	echo("We could not connect to the fedora repository.");
	die;

}

//Open XML file

$xml = simplexml_load_file($sourceXMLFile);
if (!$xml) {
	echo("Failed to read XML, boo");
} else {

//Loop through each file in XML file
//$recordsProcessed = 0;
//$recordsRead = 0;

//For each record, find the record in Islandora using a Solr query (158 to 191 in evld_past_perfect.php


	foreach ($xml->export as $exportedItem) {
		$objectId = (string)$exportedItem->objectid;
		echo("Processing object $objectId<br/>\r\n");
		$solrQuery = "?q=mods_identifier_t:$objectId&fl=PID,dc.title,RELS_EXT_hasModel_uri_s";

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
			$MODSxml = new DOMDocument();
			$MODSxml->preserveWhiteSpace = false;
			$MODSxml->formatOutput = true;
			if (!$MODSxml->loadXML($MODScontent)){
				echo("Could not load XML for $objectId PID $existingPID");
				continue;
			}

			if (!file_exists($oldModsLocation . $objectId . '.xml')){
				//Save here but reformatted for easier comparison
				file_put_contents($oldModsLocation . $objectId . '.xml', $MODSxml->saveXML());
			}

			//Modify the MODS record to do what we want

			//FIX DATE CREATED

			//Get the Date Created
			$originInfo = $MODSxml->getElementsByTagName('originInfo');
			if ($originInfo->length != 0) {
				$originInfo = $originInfo->item(0);
				$datesCreated = $originInfo->childNodes;
				/** @var DOMElement $dateCreated */
				for  ($i = $datesCreated->length -1; $i >= 0; $i--){
					$dateCreated = $datesCreated->item($i);
					if ($i == 0){
						$dateCreated->setAttribute("point", "start");
						$dateStartValue = $dateCreated->textContent;
						if (substr($dateStartValue, 0, 1) == 'c'){
							$dateStartValue = substr($dateStartValue, 1);
							$dateCreated->nodeValue = $dateStartValue;
							$dateCreated->setAttribute('qualifier', 'approximate');
						}elseif (substr($dateStartValue, -1) == 's'){
							$dateStartValue = substr($dateStartValue, 0, -1);
							$dateCreated->setAttribute('qualifier', 'approximate');
							$dateCreated->nodeValue = $dateStartValue;
						}
					}else{
						if ($dateCreated->textContent == "0"){
							$originInfo->removeChild($dateCreated);
						}
					}
				}
			}

			/** @var DOMElement $modsNode */
			$modsNode = $MODSxml->getElementsByTagName("mods")->item(0);
			/** @var DOMElement $identifier */
			$identifier = $MODSxml->getElementsByTagName("identifier")->item(0);
			/** @var DOMElement $extension */
			$extension = $MODSxml->getElementsByTagName("extension")->item(0);
			/** @var DOMElement $marmotLocal */
			$marmotLocal = $extension->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'marmotLocal')->item(0);

			//FIX IDENTIFIER
			if ($marmotLocal && $identifier) {
				$modsNode->removeChild($identifier);
				$migratedIdentifier = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'migratedIdentifier', $identifier->textContent);
				$marmotLocal->appendChild($migratedIdentifier);
			}

			//FIX MIGRATED FILE NAME
			$migratedFilename = $extension->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'migratedFileName')->item(0);
			if ($marmotLocal && $migratedFilename) {
				$extension->removeChild($migratedFilename);
				$marmotLocal->appendChild($migratedFilename);
			}

			//FIX SUBJECTS
			$subjects = $modsNode->getElementsByTagName('subject');
			for ($i = $subjects->length - 1; $i >= 0; $i--) {
				/** @var DOMElement $subject */
				$subject = $subjects->item($i);
				/** @var DOMNodeList $topics */
				$topics = $subject->getElementsByTagName('topic');
				for ($j = 0; $j < $topics->length; $j++) {
					$topic = $topics->item($j);
					$newSubject = $MODSxml->createElement('subject');
					$newSubject->setAttribute("authority", 'local');
					$newTopic = $MODSxml->createElement('topic', trim($topic->textContent));
					$newSubject->appendChild($newTopic);
					$modsNode->insertBefore($newSubject, $subject);
				}
				$modsNode->removeChild($subject);
			}

			//FIX EXTENT & PHYSICAL DESCRIPTION
			/** @var DOMElement $physicalDescription */
			$physicalDescription = $modsNode->getElementsByTagName('physicalDescription')->item(0);
			if ($physicalDescription) {
				$extents = $physicalDescription->getElementsByTagName('extent');
				$fullExtent = '';
				for ($i = 0; $i < $extents->length; $i++) {
					/** @var DOMElement $extent */
					$extent = $extents->item($i);
					if (strlen($fullExtent) > 0){
						$fullExtent .= ' ';
					}
					$fullExtent .= $extent->textContent;
				}
				for ($i = $extents->length - 1; $i >= 0 ; $i--) {
					$extent = $extents->item($i);
					$physicalDescription->removeChild($extent);
				}

				$physicalDescription->appendChild($MODSxml->createElement('extent', $fullExtent));

				$note = $physicalDescription->getElementsByTagName('note')->item(0);
				if (strlen($exportedItem->condition) > 0){
					$condition = (string)$exportedItem->condition;
					if (strlen($exportedItem->condnotes) > 0){
						$condition .= ' (' . $exportedItem->condnotes . ')';
					}
					$note->nodeValue = $condition;
				}else{
					$physicalDescription->removeChild($note);
				}
			}

			//ADD GENRE
			$titleInfo = $modsNode->getElementsByTagName('titleInfo')->item(0);
			$genre = $MODSxml->createElement('genre', 'Image');
			$modsNode->insertBefore($genre, $titleInfo);

			//UPDATE SHELFLOCATOR
			$shelfLocator = $modsNode->getElementsByTagName('shelfLocator');
			if ($shelfLocator) {
				$fullLocation = '';
				for ($i = 0; $i < $shelfLocator->length; $i++) {
					/** @var DOMElement $location */
					$location = $shelfLocator->item($i);
					if (strlen($location->textContent) > 0){
						if (strlen($fullLocation) > 0){
							$fullLocation .= '; ';
						}
						$fullLocation .= $location->textContent;
					}
				}
				for ($i = $shelfLocator->length - 1; $i >= 0 ; $i--) {
					$location = $shelfLocator->item($i);
					$modsNode->removeChild($location);
				}

				$firstAccessCondition = $modsNode->getElementsByTagName('accessCondition')->item(0);
				$modsNode->insertBefore($MODSxml->createElement('shelfLocator', $fullLocation), $firstAccessCondition);

			}

			//Review updated XML using asXML() Function and write it into a file
			//echo $MODSxml->asXML();
			file_put_contents($newModsLocation . $objectId . '.xml', $MODSxml->saveXML());


			//echo $MODSxml->titleInfo->title;
			//$dateCreated=echo $MODSxml->originInfo->dateCreated[0];


			//Save the MODS record back to Fedora


		}
	}
}//Repeat for all objects in the collection
