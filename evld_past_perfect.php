<?php
/**
 * Imports information from EVLD's Past Perfect instance into Islandora
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 12/4/2015
 * Time: 10:57 AM
 */

header( 'Content-type: text/html; charset=utf-8' );

define ('ROOT_DIR', __DIR__);
date_default_timezone_set('America/Denver');

ini_set('display_errors', true);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

ini_set('implicit_flush', true);

$config = parse_ini_file(ROOT_DIR . '/config.ini');

//Read the XML File
$sourceXMLFile =  $config['sourceXMLFile'];
$baseImageLocation = $config['baseImageLocation'];
$jp2ImageLocation = $config['jp2ImageLocation'];
$otherImageLocation = $config['otherImageLocation'];
$updateLargeImagesForExistingEntities = $config['updateLargeImagesForExistingEntities'];
$fedoraPassword =  $config['fedoraPassword'];
$fedoraUser =  $config['fedoraUser'];
$fedoraUrl =  $config['fedoraUrl'];
$solrUrl =  $config['solrUrl'];
$recordsToSkip = isset($config['recordsToSkip']) ? $config['recordsToSkip'] : 0;
$maxRecordsToProcess = $config['maxRecordsToProcess'];
$processAllFiles = $config['processAllFiles'];
$logPath = $config['logPath'];
if(!file_exists($logPath)){
	mkdir($logPath);
}
$logFile = fopen($logPath . "import". time() . ".log", 'w');

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
		echo("Connected to Tuque OK");
	}catch (Exception $e){
		echo("We could not connect to the fedora repository.");
		die;
	}

	//Process each record
	$recordsProcessed = 0;
	$recordsRead = 0;

	/** @var SimpleXMLElement $exportedItem */
	foreach ($xml->export as $exportedItem){
		$recordsRead++;
		if ($recordsRead <= $recordsToSkip){
			continue;
		}
		//Check to see if we have the image
		$imageFilename = trim($exportedItem->imagefile);
		if (strlen($imageFilename) == 0){
			//No point importing something that doesn't have a file
			fwrite($logFile, "Warning, " . $exportedItem->objectid . " has no image defined for it, skipping.\r\n");
			continue;
		}

		$title = trim($exportedItem->title);
		if (empty($title)){
			$title = trim($exportedItem->caption);
		}

		$validImage = false;

		//Remove the folder from the image file
		$imageFilename = substr($imageFilename, strpos($imageFilename, '\\') + 1);

		//test to see if we can get the tiff instead of the jpg
		$tifFilename = str_ireplace('jpg', 'tif', $imageFilename);
		if (file_exists($baseImageLocation . $tifFilename)){
			$imageFilename = $tifFilename;
			$validImage = true;
		}elseif (file_exists($baseImageLocation . $imageFilename)){
			//TIFF doesn't exist, try the jpg
			$validImage =true;
		}

		if (!$validImage){
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
		}
		$baseImageFilename = substr($imageFilename, 0, strrpos($imageFilename, '.'));

		//Make sure that the image exists in what we have downloaded
		if ($validImage){
			$recordsProcessed++;

			$objectId = (string)$exportedItem->objectid;


			fwrite($logFile, "$recordsProcessed) Processing $objectId ($title) \r\n");

			//Check Solr to see if we have processed this already
			$solrQuery = "?q=mods_identifier_t:$objectId&fl=PID,dc.title";

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
					$newObject = true;
					$existingPID = false;
				}else{
					$newObject = false;
					$existingPID = $solrResponse->response->docs[0]->PID;
					if ($processAllFiles == false){
						continue;
					}
				}
			}




			//Basic settings for this content type
			$namespace = 'evld';

			//Create an object (this will create a new PID)
			/** @var AbstractFedoraObject $newPhoto */
			if ($newObject){
				$newPhoto = $repository->constructObject($namespace);
			}else{
				$newPhoto = $repository->getObject($existingPID);
			}

			if ($newObject){
				//$newPhoto->relationships->add()

				//TODO: if we get a tiff this can be a large image, otherwise it should be a basic imag
				if (strtolower(substr($imageFilename, -3)) == 'jpg'){
					$newPhoto->models = array('islandora:sp_basic_image');
					$isLargeImage = false;
				}else{
					$newPhoto->models = array('islandora:sp_large_image_cmodel');
					$isLargeImage = true;
				}

				$newPhoto->label = $title;
				$newPhoto->owner = 'lacy_evld';
				//Add to the proper collections
				$newPhoto->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'evld:localHistoryArchive');
				if ($isLargeImage) {
					$newPhoto->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:sp_large_image_collectiont');
				}else{
					$newPhoto->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:sp_basic_image_collection');
				}
			}

			//Create MODS data
			/** @var NewFedoraDatastream $modsDatastream */
			if ($newObject){
				$modsDatastream = $newPhoto->constructDatastream('MODS');
			}else{
				$modsDatastream = $newPhoto->getDatastream('MODS');
			}

			$modsDatastream->label = 'MODS Record';
			$modsDatastream->mimetype = 'text/xml';

			//Build our MODS data
			include_once 'metadataBuilder.php';
			$modsData = build_evld_mods_data($title, $exportedItem);

			file_put_contents("C:/data/islandora_conversions/evld/mods/{$exportedItem->objectid}.xml",$modsData);

			//Add Mods data to the datastream
			$modsDatastream->setContentFromString($modsData);

			//Add the MODS datastream to the object
			if ($newObject) {
				$newPhoto->ingestDatastream($modsDatastream);
			}

			//TODO: Update Dublin Core datastream?

			//Add the original exported past perfect metadata
			if ($newObject || ($newPhoto->getDatastream('PastPerfectExport') == null)) {
				$imageDatastream = $newPhoto->constructDatastream('PastPerfectExport');
				$imageDatastream->label = 'Original metadata migrated from Past Perfect';
				$imageDatastream->mimetype = 'text/xml';

				set_time_limit(800);
				$imageDatastream->setContentFromString($exportedItem->asXML());
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Create Image data
			if ($newObject || ($newPhoto->getDatastream('OBJ') == null)) {
				$imageDatastream = $newPhoto->constructDatastream('OBJ');

				$imageDatastream->label = $imageFilename;
				$imageDatastream->mimetype = 'image/tiff';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($baseImageLocation . $imageFilename);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the JP2 derivative
			$jp2Image = $jp2ImageLocation . $baseImageFilename . '.jp2';
			if (($newObject || ($newPhoto->getDatastream('JP2') == null)) && file_exists($jp2Image)) {
				$imageDatastream = $newPhoto->constructDatastream('JP2');
				$imageDatastream->label = 'JPEG 2000';
				$imageDatastream->mimetype = 'image/jpg2';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($jp2Image);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the thumbnail
			$tnImage = $otherImageLocation . '/tn/'. $baseImageFilename . '.jpg';
			if (($newObject || ($newPhoto->getDatastream('TN') == null)) && file_exists($tnImage)) {
				$imageDatastream = $newPhoto->constructDatastream('TN');
				$imageDatastream->label = 'Thumbnail';
				$imageDatastream->mimetype = 'image/jpg';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($tnImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the small image
			$smallImage = $otherImageLocation . '/sc/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('SC') == null)) && file_exists($smallImage)) {
				$imageDatastream = $newPhoto->constructDatastream('SC');
				$imageDatastream->label = 'Small Image for Pika';
				$imageDatastream->mimetype = 'image/png';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($smallImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the medium image
			$mediumImage = $otherImageLocation . '/mc/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('MC') == null)) && file_exists($mediumImage)) {
				$imageDatastream = $newPhoto->constructDatastream('MC');
				$imageDatastream->label = 'Medium Image for Pika';
				$imageDatastream->mimetype = 'image/png';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($mediumImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the large image
			$largeImage = $otherImageLocation . '/lc/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('LC') == null) || $updateLargeImagesForExistingEntities) && file_exists($largeImage)) {
				$updateDataStream = true;
				$newDataStream = false;
				if ($newPhoto->getDatastream('LC') == null) {
					$imageDatastream = $newPhoto->constructDatastream('LC');
					$imageDatastream->label = 'Large Image for Pika';
					$imageDatastream->mimetype = 'image/png';
					$newDataStream = true;
				}else{
					$imageDatastream = $newPhoto->getDatastream('LC');
					if ($imageDatastream->size == filesize($largeImage)){
						$updateDataStream = false;
					}
				}

				if ($updateDataStream){
					set_time_limit(800);
					fwrite($logFile, "Uploading large image\r\n");
					try{
						$imageDatastream->setContentFromFile($largeImage);
					}catch(Exception $e){
						fwrite($logFile, "error uploading large image $e\r\n");
					}

					if ($newDataStream) {
						$newPhoto->ingestDatastream($imageDatastream);
					}
				}
			}

			//Ingest into Islandora

			if ($newObject){
				try{
					$repository->ingestObject($newPhoto);
					fwrite($logFile, "Created object " . $newPhoto->id . "\r\n");
				}catch(Exception $e){
					fwrite($logFile, "error ingesting object $e\r\n");
				}
			}else{
				fwrite($logFile, "Updated object " . $newPhoto->id . "\r\n");
			}

			if ($recordsProcessed >= $maxRecordsToProcess){
				break;
			}
		}
	}
	fwrite($logFile, "Done\r\n");
}


