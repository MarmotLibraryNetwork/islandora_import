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

$config = ini_get('config.ini');

//Read the XML File
$sourceXMLFile =  $config['Setup']['sourceXMLFile'];
$baseImageLocation = $config['Setup']['baseImageLocation'];
$fedoraPassword =  $config['Setup']['fedoraPassword'];
$fedoraUser =  $config['Setup']['fedoraUser'];
$fedoraUrl =  $config['Setup']['fedoraUrl'];
$solrUrl =  $config['Setup']['solrUrl'];
$maxRecordsToProcess = $config['Setup']['maxRecordsToProcess'];
$processAllFiles = $config['Setup']['processAllFiles'];

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
		//Check to see if we have the image
		$imageFilename = trim($exportedItem->imagefile);
		if (strlen($imageFilename) == 0){
			//No point importing something that doesn't have a file
			echo("Warning, " . $exportedItem->objectid . ' has no image defined for it, skipping.<br/>');
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
			$i++;

			$objectId = (string)$exportedItem->objectid;


			echo("$i) Processing $objectId ($title) <br/>");

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
			$newPhoto->ingestDatastream($modsDatastream);

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
			$jp2Image = $baseImageLocation . '/derivatives/jp2/'. $baseImageFilename . '.jp2';
			if (($newObject || ($newPhoto->getDatastream('JP2') == null)) && file_exists($jp2Image)) {
				$imageDatastream = $newPhoto->constructDatastream('JP2');
				$imageDatastream->label = 'JPEG 2000';
				$imageDatastream->mimetype = 'image/jpg2';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($jp2Image);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the thumbnail
			$tnImage = $baseImageLocation . '/derivatives/tn/'. $baseImageFilename . '.jpg';
			if (($newObject || ($newPhoto->getDatastream('TN') == null)) && file_exists($tnImage)) {
				$imageDatastream = $newPhoto->constructDatastream('TN');
				$imageDatastream->label = 'Thumbnail';
				$imageDatastream->mimetype = 'image/jpg';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($tnImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the small image
			$smallImage = $baseImageLocation . '/derivatives/small/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('SC') == null)) && file_exists($smallImage)) {
				$imageDatastream = $newPhoto->constructDatastream('SC');
				$imageDatastream->label = 'Small Image for Pika';
				$imageDatastream->mimetype = 'image/png';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($smallImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the medium image
			$mediumImage = $baseImageLocation . '/derivatives/medium/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('MC') == null)) && file_exists($mediumImage)) {
				$imageDatastream = $newPhoto->constructDatastream('MC');
				$imageDatastream->label = 'Medium Image for Pika';
				$imageDatastream->mimetype = 'image/png';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($mediumImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Add the large image
			$largeImage = $baseImageLocation . '/derivatives/large/'. $baseImageFilename . '.png';
			if (($newObject || ($newPhoto->getDatastream('LC') == null)) && file_exists($largeImage)) {
				$imageDatastream = $newPhoto->constructDatastream('LC');
				$imageDatastream->label = 'Large Image for Pika';
				$imageDatastream->mimetype = 'image/png';

				set_time_limit(800);
				$imageDatastream->setContentFromFile($largeImage);
				$newPhoto->ingestDatastream($imageDatastream);
			}

			//Ingest into Islandora

			if ($newObject){
				try{
					$repository->ingestObject($newPhoto);
				}catch(Exception $e){
					echo("error ingesting object $e</br>");
				}
			}

			echo("Created object " . $newPhoto->id . "</br>");

			if ($i >= $maxRecordsToProcess){
				break;
			}
		}
	}
	echo('Done<br/>');
}


