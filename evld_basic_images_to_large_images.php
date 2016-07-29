<?php
/**
 * Converts files that were originally loaded as basic images to large images.
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 7/27/2016
 * Time: 4:18 PM
 */

header('Content-type: text/html; charset=utf-8');

define('ROOT_DIR', __DIR__);
date_default_timezone_set('America/Denver');

ini_set('display_errors', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

ini_set('implicit_flush', true);

$config = parse_ini_file(ROOT_DIR . '/config.ini');
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
$logFile = fopen($logPath . "evld_basic_to_large_image_". time() . ".log", 'w');

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

//Query Solr for all basic images that need to be replaced with large images
$solrQuery = "?q=RELS_EXT_hasModel_uri_s:\"info:fedora/islandora:sp_basic_image\"+AND+PID:evld*&fl=PID,dc.title,RELS_EXT_hasModel_uri_s,fedora_datastreams_ms";
$solrResponse = file_get_contents($solrUrl . $solrQuery . "&limit=1", false);
if (!$solrResponse) {
	die();
} else {
	$solrResponse = json_decode($solrResponse);
	if (!$solrResponse->response || $solrResponse->response->numFound == 0){
		fwrite($logFile, 'No basic images found');
	}else{
		$totalRecords = $solrResponse->response->numFound;
		$startRecord = 0;
		$limit = 25;
		$numProcessed = 0;
		while ($numProcessed < $totalRecords){
			$solrResponse = file_get_contents($solrUrl . $solrQuery . "&rows=$limit&start=$startRecord", false);
			$solrResponse = json_decode($solrResponse);
			foreach ($solrResponse->response->docs as $record){
				$pid = $record->PID;
				fwrite($logFile, "Processing $pid\r\n");
				$numProcessed += 1;

				$fedoraObject = $repository->getObject($pid);

				//Get a copy of MODS record for the object from Fedora
				$MODS = $fedoraObject->getDatastream('MODS');
				$MODScontent = $MODS->content;

				//Parse the MODS record using simple XML
				$MODSxml = new DOMDocument();
				$MODSxml->preserveWhiteSpace = false;
				$MODSxml->formatOutput = true;
				if (!$MODSxml->loadXML($MODScontent)) {
					echo("Could not load XML for $pid");
					fwrite($logFile, "  Could not load XML for $pid\r\n");
					continue;
				}

				$migratedIdentifierElement = $MODSxml->getElementsByTagNameNS('http://marmot.org/local_mods_extension', 'migratedIdentifier')->item(0);
				if ($migratedIdentifierElement == null){
					$migratedIdentifierElement = $MODSxml->getElementsByTagName('identifier')->item(0);
				}
				$migratedIdentifier = $migratedIdentifierElement->textContent;


				//Find the TIFF and JP2 for the basic image
				$tifImage = $baseImageLocation . '/' . $migratedIdentifier . '.tif';
				$jp2Image = $jp2ImageLocation . '/' . $migratedIdentifier . '.jp2';

				if (!file_exists($tifImage)){
					fwrite($logFile, "  $tifImage Did not exist, skipping\r\n");
					continue;
				}
				if (!file_exists($jp2Image)){
					fwrite($logFile, "  $jp2Image Did not exist, skipping\r\n");
					continue;
				}

				//Add the JP2 image
				addDatastream($jp2Image, 'JP2', 'JPEG 2000', 'image/jpg2', $fedoraObject, $logFile);

				//Replace the OBJ with the TIFF
				$fedoraObject->purgeDatastream('OBJ');
				addDatastream($tifImage, 'OBJ', $migratedIdentifier . '.tif', 'image/tiff', $fedoraObject, $logFile);

				//Change the model for the record to large image model rather than basic
				unset($fedoraObject->models);
				$fedoraObject->models = array('islandora:sp_large_image_cmodel');

				//Remove the record from the basic image collection
				$fedoraObject->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:sp_large_image_collectiont');
				$fedoraObject->relationships->remove(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:sp_basic_image_collection');

				//wait for 30 seconds to let the islandora server catch up
				sleep(30);
			}

			$startRecord += $limit;
		}
	}
}

/**
 * @param $fileToLoad
 * @param $datastream
 * @param $datastreamLabel
 * @param $mimeType
 * @param FedoraObject $fedoraObject
 * @param $datastreamFile
 */
function addDatastream($fileToLoad, $datastream, $datastreamLabel, $mimeType, $fedoraObject, $datastreamFile){
	$imageDatastream = $fedoraObject->constructDatastream($datastream);
	$imageDatastream->label = $datastreamLabel;
	$imageDatastream->mimetype = $mimeType;

	set_time_limit(1600);
	$imageDatastream->setContentFromFile($fileToLoad);
	$fedoraObject->ingestDatastream($imageDatastream);
	unset($imageDatastream);
	fwrite($datastreamFile, "  $fileToLoad uploaded\r\n");
}