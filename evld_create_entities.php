<?php
/**
 * Imports entities for EVLD's past perfect export
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 12/21/2015
 * Time: 2:50 PM
 */
header( 'Content-type: text/html; charset=utf-8' );

define ('ROOT_DIR', __DIR__);
date_default_timezone_set('America/Denver');

ini_set('display_errors', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
session_write_close();
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

for($i = 0; $i < 40000; $i++)
{
	echo ' '; // extra spaces
}
echo("<html><body>This page will import all entities into Islandora.  You must first configure it using config.ini in the same path as this file. <br />
The page will show output while running. <br />
You can configure the page to import
<ul>
<li>People</li>
<li>Places</li>
<li>Events</li>
</ul></body>\r\n");
echo("Importing entities from EVLD Past Perfect Export<br/>\r\n");
flush();

$config = parse_ini_file(ROOT_DIR . '/config.ini');

global $solrUrl, $fedoraUser, $fedoraPassword;
$sourceXMLFile =  $config['sourceXMLFile'];
$baseImageLocation = $config['baseImageLocation'];
$fedoraPassword =  $config['fedoraPassword'];
$fedoraUser =  $config['fedoraUser'];
$fedoraUrl =  $config['fedoraUrl'];
$solrUrl =  $config['solrUrl'];
$maxRecordsToProcess = $config['maxRecordsToProcess'];
$loadPeople = $config['loadPeople'];
$maxPeopleToLoad = isset($config['maxPeopleToLoad']) ? $config['maxPeopleToLoad'] : -1;
$loadEvents = $config['loadEvents'];
$loadPlaces = $config['loadPlaces'];
$updateModsForExistingEntities = $config['updateModsForExistingEntities'];

//Read the XML File
$xml = simplexml_load_file($sourceXMLFile);
if (!$xml){
	echo("Failed to read XML, boo");
}else{
	//Include code we need to use Tuque without Drupal
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
		ob_flush();
		flush();
		usleep(100);
	}catch (Exception $e){
		echo("We could not connect to the fedora repository.");
		die;
	}

	//Process each record
	$i = 0;
	$numPeopleLoaded = 0;
	$numRead = 0;

	global $existingEntities;

	/** @var SimpleXMLElement $exportedItem */
	foreach ($xml->export as $exportedItem){
		$numRead++;
		echo('.');
		if ($loadPeople){
			//Find each person within the export & add to Islandora
			$people=preg_split('/\r\n|\r|\n/', $exportedItem->people);
			foreach($people as $person) {
				$person = trim($person);
				if (strlen($person) == 0){
					continue;
				}
				if ($numPeopleLoaded < $maxPeopleToLoad || $maxPeopleToLoad == -1){
					//Check to see if the entity exists already
					$new = false;
					$existingPID = doesEntityExist($person);
					if ($existingPID != false) {
						if (!$updateModsForExistingEntities){
							continue;
						}
						//Load the object
						$entity = $repository->getObject($existingPID);
					} else {
						//Create an entity within Islandora
						$entity = $repository->constructObject('person');
						$entity->models = array('islandora:personCModel');
						$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'marmot:people');
						$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:entity_collection');
						$new = true;
					}
					echo("$i) Processing Person $person <br/>");
					$entity->label = $person;

					//Add MADS data
					if ($entity->getDatastream('MODS') == null) {
						$modsDatastream = $entity->constructDatastream('MODS');
					} else {
						$modsDatastream = $entity->getDatastream('MODS');
					}
					$modsDatastream->label = 'MODS Record';
					$modsDatastream->mimetype = 'text/xml';
					$nameParts = explode(",", $person);
					$lastName = trim($nameParts[0]);
					$modsDatastream->setContentFromString("<?xml version=\"1.0\"?><mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><mods:titleInfo><mods:title>{$person}</mods:title></mods:titleInfo><mods:extension><marmot:marmotLocal><marmot:familyName>{$lastName}</marmot:familyName></marmot:marmotLocal></mods:extension></mods>");
					$entity->ingestDatastream($modsDatastream);

					if ($new) {
						try {
							$repository->ingestObject($entity);
							$existingEntities[$person] = $entity->id;
						} catch (Exception $e) {
							echo("error ingesting object $e</br>\r\n");
						}
					}
					$numPeopleLoaded++;
					$i++;
				}
			}
		}

		//Find each event within the export & add to Islandora
		if ($loadEvents){
			$events=preg_split('/\r\n|\r|\n/', $exportedItem->event);
			foreach($events as $event) {
				$event = trim($event);
				if (strlen($event) == 0){
					continue;
				}
				//Check to see if the entity exists already
				$new = false;
				$existingPID = doesEntityExist($event);
				if ($existingPID != false) {
					if (!$updateModsForExistingEntities){
						continue;
					}
					//Load the object
					$entity = $repository->getObject($existingPID);
				} else {
					//Create an entity within Islandora
					$entity = $repository->constructObject('event');
					$entity->models = array('islandora:eventCModel');
					$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'marmot:events');
					$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:entity_collection');
					$new = true;
				}
				echo("$i) Processing Event $event <br/>");
				$entity->label = $event;

				//Add MODS data
				if ($entity->getDatastream('MODS') == null) {
					$modsEventDatastream = $entity->constructDatastream('MODS');
				} else {
					$modsEventDatastream = $entity->getDatastream('MODS');
				}
				$modsEventDatastream->label = 'MODS Record';
				$modsEventDatastream->mimetype = 'text/xml';
				$modsEventDatastream->setContentFromString("<?xml version=\"1.0\"?><mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><mods:titleInfo><mods:title>{$event}</mods:title></mods:titleInfo></mods>");
				$entity->ingestDatastream($modsEventDatastream);

				if ($new) {
					try {
						$repository->ingestObject($entity);
						$existingEntities[$event] = $entity->id;
					} catch (Exception $e) {
						echo("error ingesting object $e</br>\r\n");
					}
				}
				$i++;
			}
		}

		//Find each place within the export & add to Islandora
		if ($loadPlaces){
			$places=preg_split('/\r\n|\r|\n/', $exportedItem->place);
			foreach($places as $place) {
				$place = trim($place);
				if (strlen($place) == 0){
					continue;
				}
				//Check to see if the entity exists already
				$new = false;
				$existingPID = doesEntityExist($place);
				if ($existingPID != false) {
					if (!$updateModsForExistingEntities){
						continue;
					}
					//Load the object
					$entity = $repository->getObject($existingPID);
				} else {
					//Create an entity within Islandora
					$entity = $repository->constructObject('place');
					$entity->models = array('islandora:placeCModel');
					$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'marmot:places');
					$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:entity_collection');
					$new = true;
				}
				echo("$i) Processing Place $place <br/>");
				$entity->label = $place;

				//Add MODS data
				if ($entity->getDatastream('MODS') == null) {
					$modsPlaceDatastream = $entity->constructDatastream('MODS');
				} else {
					$modsPlaceDatastream = $entity->getDatastream('MODS');
				}
				$modsPlaceDatastream->label = 'MODS Record';
				$modsPlaceDatastream->mimetype = 'text/xml';
				$modsPlaceDatastream->setContentFromString("<?xml version=\"1.0\"?><mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><mods:titleInfo><mods:title>{$place}</mods:title></mods:titleInfo></mods>");
				$entity->ingestDatastream($modsPlaceDatastream);

				if ($new) {
					try {
						$repository->ingestObject($entity);
						$existingEntities[$place] = $entity->id;
					} catch (Exception $e) {
						echo("error ingesting object $e</br>\r\n");
					}
				}
				$i++;
			}
		}
		//End processing this record
		if ($numRead % 100 == 0){
			echo("Processed $numRead Records\r\n");
			flush();
			set_time_limit(120);
		}
		flush();
	}
	echo('Done<br/></html>\r\n');
	flush();
}
