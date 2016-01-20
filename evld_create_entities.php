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

ini_set('implicit_flush', true);

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
$loadEvents = $config['loadEvents'];
$loadPlaces = $config['loadPlaces'];

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

	//Connect to tuque
	try {
		$serializer = new FedoraApiSerializer();
		$cache = new SimpleCache();

		$connection = new RepositoryConnection($fedoraUrl, $fedoraUser, $fedoraPassword);
		$connection->verifyPeer = false;
		$api = new FedoraApi($connection, $serializer);
		$repository = new FedoraRepository($api, $cache);
		echo("Connected to Tuque OK");
	}catch (Exception $e){
		echo("We could not connect to the fedora repository.");
		die;
	}

	//Process each record
	$i = 0;

	/** @var SimpleXMLElement $exportedItem */
	foreach ($xml->export as $exportedItem){
		if ($loadPeople){
			//Find each person within the export & add to Islandora
			$people=preg_split('/\r\n|\r|\n/', $exportedItem->people);
			foreach($people as $person) {
				$person = trim($person);
				if (strlen($person) == 0){
					continue;
				}
				echo("$i) Processing $person <br/>");
				//Check to see if the entity exists already
				$new = false;
				$existingPID = doesEntityExist($person);
				if ($existingPID != false) {
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
				$entity->label = $person;

				//Add MADS data
				if ($entity->getDatastream('MADS') == null) {
					$madsDatastream = $entity->constructDatastream('MADS');
				} else {
					$madsDatastream = $entity->getDatastream('MADS');
				}
				$madsDatastream->label = 'MADS Record';
				$madsDatastream->mimetype = 'text/xml';
				$nameParts = explode(",", $person);
				$lastName = trim($nameParts[0]);
				$madsDatastream->setContentFromString("<mads><authority><name type=\"personal\"><namePart type=\"given\"></namePart><namePart type=\"family\">$lastName</namePart><namePart type=\"date\"/></name><titleInfo><title>$person</title></titleInfo></authority><variant><name><namePart type=\"given\"/><namePart type=\"family\"/></name></variant><affiliation><organization/><position/><email/><phone/><dateValid point=\"start\"/><dateValid point=\"end\"/></affiliation><fieldOfActivity/><identifier type=\"u1\"/><note type=\"status\"/><note type=\"history\"/><note/><note type=\"address\"/><url/></mads>");
				$entity->ingestDatastream($madsDatastream);

				if ($new) {
					try {
						$repository->ingestObject($entity);
					} catch (Exception $e) {
						echo("error ingesting object $e</br>");
					}
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
				echo("$i) Processing $event <br/>");
				//Check to see if the entity exists already
				$new = false;
				$existingPID = doesEntityExist($event);
				if ($existingPID != false) {
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
				$entity->label = $event;

				//Add MADS data
				if ($entity->getDatastream('EAC-CPF') == null) {
					$eacCpfDatastream = $entity->constructDatastream('EAC-CPF');
				} else {
					$eacCpfDatastream = $entity->getDatastream('EAC-CPF');
				}
				$eacCpfDatastream->label = 'EAC-CPF Record';
				$eacCpfDatastream->mimetype = 'text/xml';
				$eacCpfDatastream->setContentFromString("<eac-cpf><cpfDescription><identity><entityType>person</entityType><nameEntry localType=\"primary\"><part localType=\"forename\">$event</part></nameEntry></identity><description><existDates><dateRange><fromDate notBefore=\"\" notAfter=\"\" standardDate=\"\"/><toDate notBefore=\"\" notAfter=\"\" standardDate=\"\"/></dateRange></existDates><biogHist><p/></biogHist></description></cpfDescription></eac-cpf>");
				$entity->ingestDatastream($eacCpfDatastream);

				if ($new) {
					try {
						$repository->ingestObject($entity);
					} catch (Exception $e) {
						echo("error ingesting object $e</br>");
					}
				}
			}
		}

		//Find each place within the export & add to Islandora


	}
	echo('Done<br/>');
}

/**
 * @param String $name
 *
 * @return bool|string Returns false if the object does not exist, or the PID if it does exist
 */
function doesEntityExist($name){
	global $solrUrl, $fedoraUser, $fedoraPassword;
	$solrQuery = "?q=fgs_label_s:\"" . urlencode($name) . "\"&fl=PID,dc.title";

	//echo($solrUrl . $solrQuery);

	$context = stream_context_create(array(
			'http' => array(
					'header'  => "Authorization: Basic " . base64_encode("$fedoraUser:$fedoraPassword")
			)
	));
	$solrResponse = file_get_contents($solrUrl . $solrQuery, false, $context);

	if (!$solrResponse){
		die();
	}else{
		$solrResponse = json_decode($solrResponse);
		if ($solrResponse->response->numFound == 0){
			return false;
		}else{
			return $solrResponse->response->docs[0]->PID;
		}
	}
}