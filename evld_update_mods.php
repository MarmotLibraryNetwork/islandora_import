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

$logPath = $config['logPath'];
if(!file_exists($logPath)){
	mkdir($logPath);
}
$logFile = fopen($logPath . "mods_conversion_". time() . ".log", 'w');
$processedIdLog = fopen($logPath . "processed_ids.log", 'a+');
$idsNotInIslandoraLog = fopen($logPath . "ids_not_in_islandora.log", 'a+');

$curLine = fgets($processedIdLog);
$processedIds = array();
while ($curLine != false){
	$processedIds[trim($curLine)] = trim($curLine);
	$curLine = fgets($processedIdLog);
}

$curLine = fgets($idsNotInIslandoraLog);
$idsNotInIslandora = array();
while ($curLine != false){
	$idsNotInIslandora[trim($curLine)] = trim($curLine);
	$curLine = fgets($idsNotInIslandoraLog);
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
	$replacementPids = array();

	foreach ($xml->export as $exportedItem) {
		set_time_limit(60);
		$objectId = (string)$exportedItem->objectid;
		if (array_key_exists($objectId, $processedIds)){
			continue;
		}
		if (array_key_exists($objectId, $idsNotInIslandora)){
			continue;
		}
		echo("Processing object $objectId");
		fwrite($logFile, "Processing object $objectId");
		$solrQuery = "?q=mods_identifier_t:\"$objectId\"&fl=PID,dc.title,RELS_EXT_hasModel_uri_s";

		$solrResponse = file_get_contents($solrUrl . $solrQuery, false);
		if (!$solrResponse) {
			die();
		} else {
			$solrResponse = json_decode($solrResponse);
			if (!$solrResponse->response || $solrResponse->response->numFound == 0){
				$solrQuery = "?q=mods_extension_marmotLocal_migratedIdentifier_t:\"$objectId\"&fl=PID,dc.title,RELS_EXT_hasModel_uri_s";

				$solrResponse = file_get_contents($solrUrl . $solrQuery, false);
				if (!$solrResponse) {
					die();
				} else {
					$solrResponse = json_decode($solrResponse);
					if (!$solrResponse->response || $solrResponse->response->numFound == 0){
						echo("<br/>\r\n--WARNING: $objectId has not been imported into Islandora yet\r\n<br/>");
						fwrite($logFile, "\r\n--WARNING: $objectId has not been imported into Islandora yet\r\n");
						fwrite($idsNotInIslandoraLog, "$objectId\r\n");

					}else{
						$existingPID = $solrResponse->response->docs[0]->PID;
						echo(" ($existingPID) ALREADY UPDATED \r\n<br/>");
						fwrite($logFile, " ($existingPID) ALREADY UPDATED \r\n");
						fwrite($processedIdLog, "$objectId\r\n");
					}
					//Wait before processing the next record so we don't overload the islandora server :(
					sleep(7);
					continue;
				}

			}
			if ($solrResponse->response->numFound > 1){
				echo("<br/>\r\n--WARNING: Found more than one possible match within Islandora, not changing\r\n<br/>");
				fwrite($logFile, "\r\n--WARNING: Found more than one possible match within Islandora, not changing\r\n");
				foreach ($solrResponse->response->docs as $doc){
					echo("--{$doc->PID}\r\n<br/>");
					fwrite($logFile, "--{$doc->PID}\r\n");
				}
				continue;
			}
			$existingPID = $solrResponse->response->docs[0]->PID;
			echo(" ($existingPID) \r\n<br/>");
			fwrite($logFile, " ($existingPID) \r\n");
			//Find the same object in Fedora using Tuque
			$fedoraObject = $repository->getObject($existingPID);
			//Get a copy of MODS record for the object from Fedora
			$MODS = $fedoraObject->getDatastream('MODS');
			$MODScontent = $MODS->content;

			//Parse the MODS record using simple XML
			$MODSxml = new DOMDocument();
			$MODSxml->preserveWhiteSpace = false;
			$MODSxml->formatOutput = true;
			if (!$MODSxml->loadXML($MODScontent)) {
				echo("Could not load XML for $objectId PID $existingPID");
				fwrite($logFile, "Could not load XML for $objectId PID $existingPID");
				continue;
			}

			if (!file_exists($oldModsLocation . $objectId . '.xml')) {
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
				for ($i = $datesCreated->length - 1; $i >= 0; $i--) {
					$dateCreated = $datesCreated->item($i);
					if ($i == 0) {
						$dateCreated->setAttribute("point", "start");
						$dateStartValue = $dateCreated->textContent;
						if (strlen($dateStartValue) > 5 && substr($dateStartValue, 0, 5) == 'circa') {
							$dateStartValue = trim(substr($dateStartValue, 5));
							$dateCreated->nodeValue = $dateStartValue;
							$dateCreated->setAttribute('qualifier', 'approximate');
						}elseif (substr($dateStartValue, 0, 1) == 'c') {
							$dateStartValue = substr($dateStartValue, 1);
							$dateCreated->nodeValue = $dateStartValue;
							$dateCreated->setAttribute('qualifier', 'approximate');
						} elseif (substr($dateStartValue, -1) == 's') {
							$dateStartValue = substr($dateStartValue, 0, -1);
							$dateCreated->setAttribute('qualifier', 'approximate');
							$dateCreated->nodeValue = $dateStartValue;
						} elseif (substr($dateStartValue, -1) == '?') {
							$dateStartValue = substr($dateStartValue, 0, -1);
							$dateCreated->setAttribute('qualifier', 'approximate');
							$dateCreated->nodeValue = $dateStartValue;
						} elseif (!is_numeric($dateStartValue) && strlen($dateStartValue) > 0){
							echo("--Need to handle date qualifier " . $dateStartValue . "<br/>\r\n");
						}
					} else {
						if ($dateCreated->textContent == "0") {
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
			if ($identifier) {
				$modsNode->removeChild($identifier);
				$migratedIdentifier = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'migratedIdentifier', $identifier->textContent);
				$marmotLocal->appendChild($migratedIdentifier);
			}

			//FIX MIGRATED FILE NAME
			$migratedFilename = $marmotLocal->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'migratedFileName')->item(0);
			if ($migratedFilename != null && strlen(trim($migratedFilename->textContent)) == 0){
				$marmotLocal->removeChild($migratedFilename);
			}
			$migratedFilename = $extension->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'migratedFileName')->item(0);
			if ($marmotLocal && $migratedFilename != null) {
				$migratedFilename->parentNode->removeChild($migratedFilename);
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
					$newTopic = $MODSxml->createElement('topic', str_replace('&', '&amp;', $topic->textContent));
					$newSubject->appendChild($newTopic);
					$modsNode->insertBefore($newSubject, $subject);
				}
				$subject->parentNode->removeChild($subject);
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
					if (strlen($fullExtent) > 0) {
						$fullExtent .= ' ';
					}
					$fullExtent .= $extent->textContent;
				}
				for ($i = $extents->length - 1; $i >= 0; $i--) {
					$extent = $extents->item($i);
					$physicalDescription->removeChild($extent);
				}

				$physicalDescription->appendChild($MODSxml->createElement('extent', $fullExtent));

				$note = $physicalDescription->getElementsByTagName('note')->item(0);
				if (strlen($exportedItem->condition) > 0) {
					$condition = (string)$exportedItem->condition;
					if (strlen($exportedItem->condnotes) > 0) {
						$condition .= ' (' . $exportedItem->condnotes . ')';
					}
					$note->nodeValue = $condition;
				} else if ($note != null && $physicalDescription != null) {
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
					if (strlen($location->textContent) > 0) {
						if (strlen($fullLocation) > 0) {
							$fullLocation .= '; ';
						}
						$fullLocation .= $location->textContent;
					}
				}
				for ($i = $shelfLocator->length - 1; $i >= 0; $i--) {
					$location = $shelfLocator->item($i);
					$location->parentNode->removeChild($location);
				}

				$firstAccessCondition = $modsNode->getElementsByTagName('accessCondition')->item(0);
				$modsNode->insertBefore($MODSxml->createElement('shelfLocator', str_replace('&', '&amp;', $fullLocation)), $firstAccessCondition);

			}

			//Fix copyright information
			$accessConditions = $modsNode->getElementsByTagName('accessCondition');
			for ($i = $accessConditions->length - 1; $i >= 0; $i--) {
				$modsNode->removeChild($accessConditions->item($i));
			}

			$copyRightStatement = '';
			if (strlen($exportedItem->legal) > 0) {
				$copyRightStatement = (string)$exportedItem->legal;
			} else {
				$copyRightStatement = (string)$exportedItem->copyright;
			}

			//Get the name node for relative positioning
			$nameNode = $modsNode->getElementsByTagName('name')->item(0);
			if (strlen($copyRightStatement) > 0) {
				$copyrightHolderName = '';
				if (stripos($copyRightStatement, 'Eagle County Historical Society') !== false) {
					$copyrightHolderName = 'Eagle County Historical Society';
				} else {
					echo("Unknown copyright holder: $copyRightStatement \r\n<br/>");
					fwrite($logFile, "Unknown copyright holder: $copyRightStatement \r\n");
				}
				$accessCondition = $MODSxml->createElement('accessCondition');
				$accessCondition->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'typeOfStatement', 'local'));
				$accessCondition->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'rightsStatement', $copyRightStatement));

				if ($copyrightHolderName) {
					$rightsHolderElement = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'rightsHolder');
					$rightsHolderPid = doesEntityExist($copyrightHolderName);
					if (!$rightsHolderPid) {
						$rightsHolderPid = createOrganization($repository, $copyrightHolderName);
					}
					$rightsHolderElement->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPid', $rightsHolderPid));
					$rightsHolderElement->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityTitle', $copyrightHolderName));
					$accessCondition->appendChild($rightsHolderElement);
				}
				$modsNode->insertBefore($accessCondition, $nameNode);
			}

			$provenance = (string)$exportedItem->provenance;
			if (strlen($provenance) > 0) {
				$noteNode = $MODSxml->createElement('notes', str_replace('&', '&amp;', $provenance));
				if ($nameNode != null){
					$modsNode->replaceChild($noteNode, $nameNode);
				}else{
					$modsNode->insertBefore($noteNode, $extension);
				}
			}

			//Do processing of the related entities to convert to the new format
			/** @var DOMElement $publisher */
			$publisher = $marmotLocal->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'hasPublisher')->item(0);
			if (isset($publisher)){
				$publisherTitle = $publisher->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'entityTitle')->item(0)->textContent;
				$publisherPid = $publisher->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'entityPid')->item(0)->textContent;
				if (strlen($publisherTitle) > 0){
					$newPublisher = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'relatedPersonOrg');
					$newPublisher->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'role', 'publisher'));
					$newPublisher->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPid', $publisherPid));
					$newPublisher->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityTitle', str_replace('&', '&amp;', $publisherTitle)));
					$marmotLocal->replaceChild($newPublisher, $publisher);
				}else{
					$marmotLocal->removeChild($publisher);
				}
			}

			$relatedEntities = $marmotLocal->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'relatedEntity');
			$entitiesToReplace = array();
			for ($i = 0; $i < $relatedEntities->length; $i++){
				/** @var DOMElement $relatedEntity */
				$relatedEntity = $relatedEntities->item($i);
				$type = $relatedEntity->getAttribute('type');
				$entityTitle = trim($relatedEntity->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'entityTitle')->item(0)->textContent);
				$entityPid = trim($relatedEntity->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'entityPid')->item(0)->textContent);
				if (strlen($entityPid) == 0){
					$entitiesToReplace[] = array(null, $relatedEntity);
				}else{
					if ($type == 'person'){
						$relatedPersonOrg = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'relatedPersonOrg');
						$relatedPersonOrg->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPid', $entityPid));
						$originalTitle = $entityTitle;
						if (strpos($entityTitle, ',')){
							$nameParts = explode(',', $entityTitle);
							$entityTitle = trim($nameParts[1]) . ' ' . $nameParts[0];
						}
						//Load the actual entity to see if we need to fix the entity name and to see if it a duplicate
						$entity = getFedoraObjectByPid($repository, $entityPid);
						if ($originalTitle == $entity->label){
							if ($entity->label != $entityTitle){
								fixEntityName($entity, $entityPid, $entityTitle, $oldModsLocation, $newModsLocation);
							}
						}else{
							$entityTitle = $entity->label;
						}
						list($entityPid, $entityTitle) = checkForReplacedEntity($repository, $entity, $entityPid, $entityTitle, $replacementPids, $logFile);

						$relatedPersonOrg->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityTitle', $entityTitle));
						$entitiesToReplace[] = array($relatedPersonOrg, $relatedEntity);
					}elseif ($type == 'place'){
						$relatedPlace = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'relatedPlace');
						$relatedPlaceEntity = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPlace');
						$relatedPlace->appendChild($relatedPlaceEntity);

						$entity = getFedoraObjectByPid($repository, $entityPid);
						list($entityPid, $entityTitle) = checkForReplacedEntity($repository, $entity, $entityPid, $entityTitle, $replacementPids, $logFile);

						$relatedPlaceEntity->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPid', $entityPid));
						$relatedPlaceEntity->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityTitle', $entityTitle));
						$entitiesToReplace[] = array($relatedPlace, $relatedEntity);
					}elseif ($type == 'event'){
						$relatedEvent = $MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'relatedEvent');

						$entity = getFedoraObjectByPid($repository, $entityPid);
						list($entityPid, $entityTitle) = checkForReplacedEntity($repository, $entity, $entityPid, $entityTitle, $replacementPids, $logFile);

						$relatedEvent->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityPid', $entityPid));
						$relatedEvent->appendChild($MODSxml->createElementNS('http://marmot.org/local_mods_extension', 'entityTitle', $entityTitle));
						$entitiesToReplace[] = array($relatedEvent, $relatedEntity);
					}else{
						echo "Unhandled entity type $type<br/>\r\n";
						fwrite($logFile, "Unhandled entity type $type\r\n");
					}
				}

			}
			foreach ($entitiesToReplace as $entityToReplace){
				if ($entityToReplace[0] == null){
					$marmotLocal->removeChild($entityToReplace[1]);
				}else{
					$marmotLocal->replaceChild($entityToReplace[0], $entityToReplace[1]);
				}
			}

			//DONE

			//Review updated XML using asXML() Function and write it into a file
			file_put_contents($newModsLocation . $objectId . '.xml', $MODSxml->saveXML());

			//Save the MODS record back to Fedora
			$MODS->setContentFromString($MODSxml->saveXML());
			fwrite($logFile, "--Finished update pausing\r\n");
			fflush($logFile);

			fwrite($processedIdLog, "$objectId\r\n");
			fflush($processedIdLog);

			//Wait 10 seconds before processing the next record
			sleep(60);

		}
	}
}//Repeat for all objects in the collection

fclose($processedIdLog);
fclose($logFile);

/**
 * @param FedoraObject $entity
 * @param string $entityPid
 * @param string $newName
 */
function fixEntityName($entity, $entityPid, $newName, $oldModsLocation, $newModsLocation){
	$entityMods = $entity->getDatastream('MODS');
	$entityDoc = new DOMDocument();
	$entityDoc->preserveWhiteSpace = false;
	$entityDoc->formatOutput = true;
	if (!$entityDoc->loadXML($entityMods->content)) {
		echo("Could not load XML for $entityPid");
		return;
	}
	if (!file_exists($oldModsLocation . $entityPid . '.xml')) {
		//Save here but reformatted for easier comparison
		file_put_contents($oldModsLocation . str_replace(':', '_', $entityPid) . '.xml', $entityDoc->saveXML());
	}

	/** @var DOMElement $modsTitle */
	$modsTitle = $entityDoc->getElementsByTagNameNS('http://www.loc.gov/mods/v3', 'title')->item(0);
	$modsTitle->nodeValue = $newName;

	//Review updated XML using asXML() Function and write it into a file
	file_put_contents($newModsLocation . str_replace(':', '_', $entityPid) . '.xml', $entityDoc->saveXML());

	$entity->label = $newName;
	$entityMods->setContentFromString($entityDoc->saveXML());
}

/**
 * @param FedoraObject $entity
 * @param string $entityPid
 *
 * @return string
 */
function checkForReplacedEntity($repository, $entity, $entityPid, $entityTitle, &$replacementPids, $logFile){
	if (array_key_exists($entityPid, $replacementPids)){
		return $replacementPids[$entityPid];
	}
	$entityMods = $entity->getDatastream('MODS');
	$entityDoc = new DOMDocument();
	if (!$entityDoc->loadXML($entityMods->content)) {
		echo("Could not load XML for $entityPid");
		return array($entityPid, $entityTitle);
	}
	$personNotes = $entityDoc->getElementsByTagNameNS("http://marmot.org/local_mods_extension", 'personNotes')->item(0);
	$newEntityPid = null;
	if ($personNotes != null){
		$personNotes = $personNotes->textContent;
		if (strlen($personNotes) > 0){
			if (strpos($personNotes, 'replace_old') != false){
				if (preg_match('/(evld:\d+)/', $personNotes, $matches)){
					$newEntityPid = $matches[1];
				}
			}
		}
	}
	$placeNotes = $entityDoc->getElementsByTagNameNS("http://marmot.org/local_mods_extension", 'placeNotes')->item(0);
	if ($placeNotes != null){
		$placeNotes = $placeNotes->textContent;
		if (strlen($placeNotes) > 0){
			if (strpos($placeNotes, 'replace_old') != false){
				if (preg_match('/(evld:\d+)/', $placeNotes, $matches)){
					$newEntityPid = $matches[1];
				}
			}
		}
	}
	$abstract = $entityDoc->getElementsByTagNameNS("http://www.loc.gov/mods/v3", 'abstract')->item(0);
	if ($abstract != null){
		$abstract = $abstract->textContent;
		if (strlen($abstract) > 0){
			if (strpos($abstract, 'replace_old') != false){
				if (preg_match('/(evld:\d+)/', $abstract, $matches)){
					$newEntityPid = $matches[1];
				}
			}
		}
	}

	if ($newEntityPid == null){
		$replacementPids[$entityPid] = array($entityPid, $entityTitle);
	}else{
		list($oldNamespace, $oldId) = explode(':', $entityPid);
		list($newNamespace, $newId) = explode(':', $newEntityPid);
		if ($oldNamespace != $newNamespace){
			//echo("--Warning namespace for replacement PID changed $entityPid to $newEntityPid using old namespace<br/>\r\n");
			$newEntityPid = $oldNamespace . ':' . $newId;
		}
		$newEntity = getFedoraObjectByPid($repository, $newEntityPid);
		echo("--Changing Entity ($entityPid) $entityTitle to ($newEntityPid) {$newEntity->label}<br/>\r\n");
		fwrite($logFile,"--Changing Entity ($entityPid) $entityTitle to ($newEntityPid) {$newEntity->label}\r\n");
		$replacementPids[$entityPid] = array($newEntityPid, $newEntity->label);
	}
	return $replacementPids[$entityPid];

}