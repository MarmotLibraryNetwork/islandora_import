<?php
/**
 * Check all datastreams to be sure they exist generate a report of any that are missing, and try to fix.
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 7/27/2016
 * Time: 2:47 PM
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

$baseImageLocation = $config['baseImageLocation'];
$baseImageLocationJPG = $config['baseImageLocationJPG'];
$jp2ImageLocation = $config['jp2ImageLocation'];
$otherImageLocation = $config['otherImageLocation'];

$logPath = $config['logPath'];
if(!file_exists($logPath)){
	mkdir($logPath);
}
$logFile = fopen($logPath . "evld_datastream_audit_". time() . ".log", 'w');
$datastreamFile = fopen($logPath . "evld_missing_datastreams_". time() . ".log", 'w');

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

$xml = simplexml_load_file($sourceXMLFile);
if (!$xml) {
	echo("Failed to read XML, boo");
} else {
	foreach ($xml->export as $exportedItem) {
		set_time_limit(60);
		$objectId = (string)$exportedItem->objectid;
		echo("Processing object $objectId<br/>\r\n");
		fwrite($logFile, "Processing object $objectId\r\n");
		$solrQuery = "?q=mods_extension_marmotLocal_migratedIdentifier_t:\"$objectId\"&fl=PID,dc.title,RELS_EXT_hasModel_uri_s,fedora_datastreams_ms";
		$solrResponse = file_get_contents($solrUrl . $solrQuery, false);
		if (!$solrResponse) {
			die();
		}else{
			$solrResponse = json_decode($solrResponse);
			if (!$solrResponse->response || $solrResponse->response->numFound == 0){
				//We haven't loaded this record yet, ignore
			}else{
				$existingPID = $solrResponse->response->docs[0]->PID;

				$existingDatastreams = $solrResponse->response->docs[0]->fedora_datastreams_ms;
				$existingDatastreams = array_flip($existingDatastreams);

				//Check the objectid to see if we have an image with that name
				$imageFilename = $exportedItem->objectid . ".tif";
				if (file_exists($baseImageLocation . $imageFilename)){
					$validImage = true;
				}else{
					$imageFilename = $exportedItem->objectid . ".jpg";
					if (file_exists($baseImageLocation . $imageFilename)){
						$validImage = true;
					}
				}
				if (!$validImage){
					//Check the objectid to see if we have an image with that name
					$imageFilename = $exportedItem->objectid . ".tif";
					if (file_exists($baseImageLocationJPG . $imageFilename)){
						$validImage = true;
					}else{
						$imageFilename = $exportedItem->objectid . ".jpg";
						if (file_exists($baseImageLocationJPG . $imageFilename)){
							$validImage = true;
						}
					}
				}
				$baseImageFilename = substr($imageFilename, 0, strrpos($imageFilename, '.'));

				if (!array_key_exists('JP2', $existingDatastreams)){
					$fileToLoad = $jp2ImageLocation . $baseImageFilename . '.jp2';
					addDatastream($fileToLoad, 'JP2', 'JPEG 2000', 'image/jpg2', $existingPID, $objectId, $datastreamFile, $repository);
				}
				if (!array_key_exists('TN', $existingDatastreams)){
					$fileToLoad = $otherImageLocation . '/tn/'. $baseImageFilename . '.jpg';
					addDatastream($fileToLoad, 'TN', 'Thumbnail', 'image/jpg', $existingPID, $objectId, $datastreamFile, $repository);
				}
				if (!array_key_exists('LC', $existingDatastreams)){
					$fileToLoad = $otherImageLocation . '/lc/'. $baseImageFilename . '.png';
					addDatastream($fileToLoad, 'LC', 'Large Image for Pika', 'image/png', $existingPID, $objectId, $datastreamFile, $repository);
				}
				if (!array_key_exists('MC', $existingDatastreams)){
					$fileToLoad = $otherImageLocation . '/mc/'. $baseImageFilename . '.png';
					addDatastream($fileToLoad, 'MC', 'Medium Image for Pika', 'image/png', $existingPID, $objectId, $datastreamFile, $repository);
				}
				if (!array_key_exists('SC', $existingDatastreams)){
					$fileToLoad = $otherImageLocation . '/sc/'. $baseImageFilename . '.png';
					addDatastream($fileToLoad, 'SC', 'Small Image for Pika', 'image/png', $existingPID, $objectId, $datastreamFile, $repository);
				}
			}
			usleep(250);
		}
	}
}

/**
 * @param $fileToLoad
 * @param $datastream
 * @param $datastreamLabel
 * @param $mimeType
 * @param $existingPID
 * @param $objectId
 * @param $datastreamFile
 * @param FedoraRepository $repository
 */
function addDatastream($fileToLoad, $datastream, $datastreamLabel, $mimeType, $existingPID, $objectId, $datastreamFile, $repository){
	if (!file_exists($fileToLoad)){
		fwrite($datastreamFile, "{$existingPID},{$objectId},$datastream,file does not exist\r\n");
	}else{

		$newPhoto = $repository->getObject($existingPID);
		$imageDatastream = $newPhoto->constructDatastream($datastream);
		$imageDatastream->label = $datastreamLabel;
		$imageDatastream->mimetype = $mimeType;

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($fileToLoad);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
		fwrite($datastreamFile, "{$existingPID},{$objectId},TN,uploaded\r\n");
	}
}