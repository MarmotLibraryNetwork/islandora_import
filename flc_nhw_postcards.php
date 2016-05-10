<?php
/**
 * Imports information from Fort Lewis College's Nina Heald Webber instance into Islandora
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

$config = parse_ini_file(ROOT_DIR . '/flc_nhw_config.ini');

//Read the configuration file
global $solrUrl, $fedoraUser, $fedoraPassword, $baseImageLocation, $updateModsForExistingEntities;
global $modsLocation, $processAllFiles, $logFile;
$sourceCSVFile =  $config['sourceCSVFile'];
$baseImageLocation = $config['baseImageLocation'];
$updateModsForExistingEntities = $config['updateModsForExistingEntities'];
$modsLocation = $config['modsLocation'];
$recordsToSkip = isset($config['recordsToSkip']) ? $config['recordsToSkip'] : 0;
$maxRecordsToProcess = $config['maxRecordsToProcess'];
$processAllFiles = $config['processAllFiles'];
$fedoraPassword =  $config['fedoraPassword'];
$fedoraUser =  $config['fedoraUser'];
$fedoraUrl =  $config['fedoraUrl'];
$solrUrl =  $config['solrUrl'];
$logPath = $config['logPath'];
if(!file_exists($logPath)){
	mkdir($logPath);
}
global $logFile;
$logFile = fopen($logPath . "import". time() . ".log", 'w');
$basicImageNames = fopen($logPath . "basicImages.log", 'w');
$recordsNoImage = fopen($logPath . "noImageRecords.log", 'w');

//For NHW, open the csv file
$sourceCSVFhnd = fopen($sourceCSVFile, 'r');
if (!$sourceCSVFhnd){
	echo("Failed to read CSV file, boo");
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

	//Read all of the postcards into memory, the key for the array will be the postcard id
	$allPostcards = array();

	/** @var SimpleXMLElement $exportedItem */
	$csvRecord = fgetcsv($sourceCSVFhnd,0, ',', '"', '"');
	while ($csvRecord != null){
		$itemId = $csvRecord[2];
		$postcardPartiallyRead = array_key_exists($itemId, $allPostcards);
		if (!$postcardPartiallyRead){
			//Create a new postcard
			$postcardData = array();
			$postcardData['acsnId'] = $csvRecord[1];
			$postcardData['itemId'] = $itemId;
			$postcardData['otherNumber'] = $csvRecord[3];
			$postcardData['itemName'] = $csvRecord[4];
			$postcardData['owner'] = $csvRecord[6];
			$postcardData['department'] = $csvRecord[7];
			$postcardData['title'] = $csvRecord[8];
			$postcardData['description'] = $csvRecord[9];
			$postcardData['creators'] = array();
			if ($csvRecord[11] != ''){
				$creatorName = $csvRecord[11];
				$postcardData['creators'][$creatorName] = array(
						'name' => $creatorName,
						'type' => $csvRecord[12],
						'place' => $csvRecord[13]
				);
			}
			$creatorName = 'Nina Heald Webber';
			$postcardData['creators'][$creatorName] = array(
					'name' => $creatorName,
					'type' => 'donor',
					'place' => ''
			);
			if ($postcardData['owner'] == 'Foundation'){
				$creatorName = 'Fort Lewis College Foundation';
				$postcardData['creators'][$creatorName] = array(
						'name' => $creatorName,
						'type' => 'owner',
						'place' => ''
				);
			}elseif ($postcardData['owner'] == 'Donor'){
				$creatorName = 'Nina Heald Webber';
				$postcardData['creators'][$creatorName] = array(
						'name' => $creatorName,
						'type' => 'owner',
						'place' => ''
				);
			}elseif ($postcardData['owner'] == 'FLC'){
				$creatorName = 'Fort Lewis College';
				$postcardData['creators'][$creatorName] = array(
						'name' => $creatorName,
						'type' => 'owner',
						'place' => ''
				);
			}
			$postcardData['dateCreated'] = $csvRecord[14];
			$postcardData['material'] = $csvRecord[15];
			$postcardData['medium'] = $csvRecord[16];
			$postcardData['volumeNumber'] = $csvRecord[17];
			$postcardData['photoNumber'] = $csvRecord[18];
			$postcardData['pubPhotoNumber'] = $csvRecord[19];
			$postcardData['thumbnail_images'] = array();
			$postcardData['master_images'] = array();
			if ($csvRecord[21] != ''){
				$postcardData['thumbnail_images'][$csvRecord[21]] = $csvRecord[21];
			}
			if ($csvRecord[23] != ''){
				$postcardData['master_images'][$csvRecord[23]] = $csvRecord[23];
			}
			$postcardData['related_people'] = array();
			if ($csvRecord[24] != ''){
				$postcardData['related_people'][$csvRecord[24]] = $csvRecord[24];
			}
			$postcardData['related_organizations'] = array();
			if ($csvRecord[25] != ''){
				$postcardData['related_organizations'][$csvRecord[25]] = $csvRecord[25];
			}
			$postcardData['lc_subjects'] = array();
			if ($csvRecord[27] != ''){
				$postcardData['lc_subjects'][$csvRecord[27]] = $csvRecord[27];
			}
			$postcardData['geo_place_ids'] = array();
			if ($csvRecord[28] != ''){
				$postcardData['geo_place_ids'][$csvRecord[28]] = $csvRecord[28];
			}
			$postcardData['geo_places'] = array();
			if ($csvRecord[29] != ''){
				$postcardData['geo_places'][$csvRecord[29]] = $csvRecord[29];
			}
			$postcardData['original_data'] = implode(',', $csvRecord);

			$allPostcards[$itemId] = $postcardData;
		}else{
			//Existing postcard data
			$postcardData = $allPostcards[$itemId];
			$creatorName = $csvRecord[11];
			if ($csvRecord[11] != '' && !array_key_exists($creatorName, $postcardData['creators'])){
				$postcardData['creators'][$creatorName] = array(
						'name' => $creatorName,
						'type' => $csvRecord[12],
						'place' => $csvRecord[13]
				);
			}
			if ($csvRecord[21] != '' && !array_key_exists($csvRecord[21], $postcardData['thumbnail_images'])){
				$postcardData['thumbnail_images'][$csvRecord[21]] = $csvRecord[21];
			}
			if ($csvRecord[23] != '' && !array_key_exists($csvRecord[23], $postcardData['master_images'])){
				$postcardData['master_images'][$csvRecord[23]] = $csvRecord[23];
			}
			if ($csvRecord[24] != '' && !array_key_exists($csvRecord[24], $postcardData['related_people'])){
				$postcardData['related_people'][$csvRecord[24]] = $csvRecord[24];
			}
			if ($csvRecord[25] != '' && !array_key_exists($csvRecord[25], $postcardData['related_organizations'])){
				$postcardData['related_organizations'][$csvRecord[25]] = $csvRecord[25];
			}
			if ($csvRecord[27] != '' && !array_key_exists($csvRecord[27], $postcardData['lc_subjects'])){
				$postcardData['lc_subjects'][$csvRecord[27]] = $csvRecord[27];
			}
			if ($csvRecord[28] != '' && !array_key_exists($csvRecord[28], $postcardData['geo_place_ids'])){
				$postcardData['geo_place_ids'][$csvRecord[28]] = $csvRecord[28];
			}
			if ($csvRecord[29] != '' && !array_key_exists($csvRecord[29], $postcardData['geo_places'])){
				$postcardData['geo_places'][$csvRecord[29]] = $csvRecord[29];
			}
			$postcardData['original_data'] .= "\r\n" . implode(',', $csvRecord);
			//Make sure to update with our changes
			$allPostcards[$itemId] = $postcardData;
		}

		//Read the next record
		$csvRecord = fgetcsv($sourceCSVFhnd,0, ',', '"', '"');
	}

	//Now that we have read all of the records,
	//Process each record
	$recordsProcessed = 0;
	$recordsRead = 0;
	foreach ($allPostcards as $postcardData){
		$recordsRead++;
		if ($recordsRead <= $recordsToSkip){
			continue;
		}

		$itemId = $postcardData['itemId'];
		$title = $postcardData['title'];
		$frontImage = null;
		$backImage = null;

		if (strlen($postcardData['photoNumber']) == 0 || strlen($postcardData['volumeNumber']) == 0){
			fwrite($logFile, date('Y-m-d H:i:s')."$recordsProcessed) No photo or volume for $itemId \r\n");
			continue;
		}
		//Try to build the filename
		$photoNumber = str_pad($postcardData['photoNumber'], 4, '0', STR_PAD_LEFT);
		$frontImageBase ='M194' .  $postcardData['volumeNumber'] . $photoNumber . 'F';
		$backImageBase ='M194' .  $postcardData['volumeNumber'] . $photoNumber . 'B';
		$frontImage = $frontImageBase . '.tif';
		$backImage = $backImageBase . '.tif';

		if (file_exists($baseImageLocation . 'tif/' . $frontImage) && file_exists($baseImageLocation . 'tif/' . $backImage)){
			//We have both a front and a back.
			$recordsProcessed++;
			fwrite($logFile, date('Y-m-d H:i:s')."$recordsProcessed) Processing $itemId ($title) \r\n");

			addPostCardToIslandora($postcardData, $frontImageBase, $backImageBase, $repository, $config);

		}else{
			if (file_exists($baseImageLocation . 'tif/' . $frontImage)){
				fwrite($recordsNoImage, "$itemId,,$backImage\r\n");
			}elseif (file_exists($baseImageLocation . 'tif/' . $backImage)){
				fwrite($recordsNoImage, "$itemId,$frontImage,\r\n");
			}else{
				fwrite($recordsNoImage, "$itemId,$frontImage,$backImage\r\n");
			}

		}
		if ($recordsProcessed >= $maxRecordsToProcess){
			break;
		}
	}


	fwrite($logFile, date('Y-m-d H:i:s')."Done\r\n");
	fclose($logFile);
	fclose($basicImageNames);
	fclose($recordsNoImage);
	fclose($sourceCSVFhnd);
}

function addPostCardToIslandora($postcardData, $frontImageName, $backImageName, $repository, $config){
	set_time_limit(120);
	global $updateModsForExistingEntities, $modsLocation, $processAllFiles, $logFile;

	$objectId = $postcardData['itemId'];
	//Check to see if the compound object has already been created
	/** @var $compoundObject AbstractFedoraObject */
	list($compoundObject, $isNew) = getObjectForIdentifier($objectId, $repository);
	if ($isNew){
		$compoundObject->models = array('islandora:compoundCModel');
		$compoundObject->label = $postcardData['title'];
		$compoundObject->owner = 'martha_fortlewis';
		switch ($postcardData['volumeNumber']){
			case 1:
				$collection = 'fortlewis:4';
				break;
			case 2:
				$collection = 'fortlewis:6';
				break;
			case 3:
				$collection = 'fortlewis:8';
				break;
			case 4:
				$collection = 'fortlewis:10';
				break;
			case 5:
				$collection = 'fortlewis:12';
				break;
			case 6:
				$collection = 'fortlewis:14';
				break;
			default:
				echo "Invalid volume for $objectId " . $postcardData['volumeNumber'];
				return;
		}
		$compoundObject->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $collection);
	}else{
		if (!$processAllFiles){
			fwrite($logFile, date('Y-m-d H:i:s')."Skipping existing object $objectId because it was already processed\r\n");
			return;
		}
	}

	//Create MODS record for the compound object
	if ($isNew || $updateModsForExistingEntities){
		/** @var NewFedoraDatastream $modsDatastream */
		if ($isNew){
			$modsDatastream = $compoundObject->constructDatastream('MODS');
			$modsDatastream->label = 'MODS Record';
			$modsDatastream->mimetype = 'text/xml';
		}else{
			$modsDatastream = $compoundObject->getDatastream('MODS');
		}

		//Build our MODS data
		$modsData = build_postcard_mods_data($repository, $postcardData);

		$normalizedObjectId = str_replace(':', '_', $objectId);
		$normalizedObjectId = str_replace('.', '_', $normalizedObjectId);
		file_put_contents("{$modsLocation}{$normalizedObjectId}.xml",$modsData);

		//Add Mods data to the datastream
		if ($isNew || ($modsDatastream->size != strlen($modsData))){
			$modsDatastream->setContentFromString($modsData);
		}

		//Add the MODS datastream to the object
		if ($isNew) {
			$compoundObject->ingestDatastream($modsDatastream);
			$repository->ingestObject($compoundObject);
		}
	} //Done setting up MODS

	//Add the original data
	if (($isNew || ($compoundObject->getDatastream('ACCESS_DB_DATA') == null))) {
		$accessDbDatastream = $compoundObject->constructDatastream('ACCESS_DB_DATA');
		$accessDbDatastream->label = 'Original data from access database';
		$accessDbDatastream->mimetype = 'text/csv';

		set_time_limit(1600);
		$accessDbDatastream->setContentFromString($postcardData['original_data']);
		$compoundObject->ingestDatastream($accessDbDatastream);
		unset($accessDbDatastream);
	}

	//Add the thumbnail
	global $baseImageLocation;
	$tnImage = $baseImageLocation . 'tn/'. $frontImageName . '.jpg';
	if (($isNew || ($compoundObject->getDatastream('TN') == null)) && file_exists($tnImage)) {
		$imageDatastream = $compoundObject->constructDatastream('TN');
		$imageDatastream->label = 'Thumbnail';
		$imageDatastream->mimetype = 'image/jpg';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($tnImage);
		$compoundObject->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Check to see if the front has already been created
	addPostCardFront($compoundObject, $postcardData, $frontImageName, $repository);

	//Check to see if the back has already been created
	addPostCardBack($compoundObject, $postcardData, $backImageName, $repository);
}

/**
 * @param AbstractFedoraObject $compoundObject
 * @param $postcardData
 * @param $frontImageName
 * @param $repository
 */
function addPostCardFront($compoundObject, $postcardData, $frontImageName, $repository){
	global $updateModsForExistingEntities, $modsLocation;
	global $logFile;
	$objectId = $postcardData['itemId'] . '-Front';
	$parentId = $compoundObject->id;
	/** @var AbstractFedoraObject $newPhoto */
	list($newPhoto, $isNew) = getObjectForIdentifier($objectId, $repository);
	if ($isNew){
		$newPhoto->models = array('islandora:sp_large_image_cmodel');
		$newPhoto->label = $postcardData['title'] . ' Front';
		$newPhoto->owner = 'martha_fortlewis';
		$newPhoto->relationships->add(FEDORA_RELS_EXT_URI, 'isConstituentOf', $parentId);
		$newPhoto->relationships->add(ISLANDORA_RELS_EXT_URI, 'isSequenceNumberOf' . str_replace(':', '_', $parentId), '1', TRUE);
	}

	//Create MODS record for the compound object
	if ($isNew || $updateModsForExistingEntities){
		/** @var NewFedoraDatastream $modsDatastream */
		if ($isNew){
			$modsDatastream = $newPhoto->constructDatastream('MODS');
			$modsDatastream->label = 'MODS Record';
			$modsDatastream->mimetype = 'text/xml';
		}else{
			$modsDatastream = $newPhoto->getDatastream('MODS');
		}

		//Build our MODS data
		$modsData = build_postcard_front_mods_data($repository, $postcardData);

		$normalizedObjectId = str_replace(':', '_', $objectId);
		$normalizedObjectId = str_replace('.', '_', $normalizedObjectId);
		file_put_contents("{$modsLocation}{$normalizedObjectId}.xml",$modsData);

		//Add Mods data to the datastream
		if ($isNew || ($modsDatastream->size != strlen($modsData))){
			$modsDatastream->setContentFromString($modsData);
		}

		//Add the MODS datastream to the object
		if ($isNew) {
			$newPhoto->ingestDatastream($modsDatastream);
			$repository->ingestObject($newPhoto);
		}
	} //Done setting up MODS

	//Add the thumbnail
	global $baseImageLocation;

	//Create Image data
	if ($isNew || ($newPhoto->getDatastream('OBJ') == null)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading TIFF image for front\r\n");
		if ($newPhoto->getDatastream('OBJ') == null){
			$imageDatastream = $newPhoto->constructDatastream('OBJ');
		}else {
			$imageDatastream = $newPhoto->getDatastream('OBJ');
		}


		$imageDatastream->label = $frontImageName;
		$imageDatastream->mimetype = 'image/tiff';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($baseImageLocation . 'tif/'. $frontImageName . '.tif');
		errorTrappedIngest($newPhoto, $imageDatastream, $baseImageLocation . 'tif/'. $frontImageName . '.tif', 'tiff image', 'front', $logFile);
	}

	//Add the JP2 derivative
	$jp2Image = $baseImageLocation . 'jp2/'. $frontImageName . '.jp2';
	if (($isNew || ($newPhoto->getDatastream('JP2') == null)) && file_exists($jp2Image)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading JP2 image for front\r\n");
		$imageDatastream = $newPhoto->constructDatastream('JP2');
		$imageDatastream->label = 'JPEG 2000';
		$imageDatastream->mimetype = 'image/jpg2';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($jp2Image);
		errorTrappedIngest($newPhoto, $imageDatastream, $jp2Image, 'jp2 image', 'front', $logFile);
	}

	$tnImage = $baseImageLocation . 'tn/'. $frontImageName . '.jpg';
	if (($isNew || ($newPhoto->getDatastream('TN') == null)) && file_exists($tnImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading thumbnail image for front\r\n");
		$imageDatastream = $newPhoto->constructDatastream('TN');
		$imageDatastream->label = 'Thumbnail';
		$imageDatastream->mimetype = 'image/jpg';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($tnImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the small image
	$smallImage = $baseImageLocation . '/sc/'. $frontImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('SC') == null)) && file_exists($smallImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading small image for front\r\n");
		$imageDatastream = $newPhoto->constructDatastream('SC');
		$imageDatastream->label = 'Small Image for Pika';
		$imageDatastream->mimetype = 'image/png';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($smallImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the medium image
	$mediumImage = $baseImageLocation . '/mc/'. $frontImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('MC') == null)) && file_exists($mediumImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading medium image for front\r\n");
		$imageDatastream = $newPhoto->constructDatastream('MC');
		$imageDatastream->label = 'Medium Image for Pika';
		$imageDatastream->mimetype = 'image/png';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($mediumImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the large image
	$largeImage = $baseImageLocation . '/lc/'. $frontImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('LC') == null)) && file_exists($largeImage)) {
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
			set_time_limit(1600);
			fwrite($logFile, date('Y-m-d H:i:s')."Uploading large image for front\r\n");
			try{
				$imageDatastream->setContentFromFile($largeImage);
			}catch(Exception $e){
				fwrite($logFile, date('Y-m-d H:i:s')."error uploading large image $e\r\n");
			}

			if ($newDataStream) {
				errorTrappedIngest($newPhoto, $imageDatastream, $largeImage, 'large image', 'front', $logFile);

			}
		}
	}
}

function errorTrappedIngest($object, $datastream, $filename, $type, $side, $logFile){
	$maxTries = 3;
	for ($try = 0; $try < $maxTries; $try++){
		if ($try > 0){
			sleep(5);
		}
		try{
			$result = $object->ingestDatastream($datastream);
			break;
		}catch (Exception $e){
			echo("Error ingesting $side $type datastream on try $try " .  $e->getMessage());
		}
	}
	unset($imageDatastream);
	if (!$result){
		fwrite($logFile, date('Y-m-d H:i:s')."Could not upload $type for postcard $side {$filename} upload it later manually\r\n");
	}
}

/**
 * @param AbstractFedoraObject $compoundObject
 * @param $postcardData
 * @param $backImageName
 * @param $repository
 */
function addPostCardBack($compoundObject, $postcardData, $backImageName, $repository){
	global $updateModsForExistingEntities, $modsLocation;
	global $logFile;
	$objectId = $postcardData['itemId'] . '-Back';
	$parentId = $compoundObject->id;
	/** @var AbstractFedoraObject $newPhoto */
	list($newPhoto, $isNew) = getObjectForIdentifier($objectId, $repository);
	if ($isNew){
		$newPhoto->models = array('islandora:sp_large_image_cmodel');
		$newPhoto->label = $postcardData['title'] . ' Back';
		$newPhoto->owner = 'martha_fortlewis';
		$newPhoto->relationships->add(FEDORA_RELS_EXT_URI, 'isConstituentOf', $parentId);
		$newPhoto->relationships->add(ISLANDORA_RELS_EXT_URI, 'isSequenceNumberOf' . str_replace(':', '_', $parentId), '2', TRUE);
	}

	//Create MODS record for the compound object
	if ($isNew || $updateModsForExistingEntities){
		/** @var NewFedoraDatastream $modsDatastream */
		if ($isNew){
			$modsDatastream = $newPhoto->constructDatastream('MODS');
			$modsDatastream->label = 'MODS Record';
			$modsDatastream->mimetype = 'text/xml';
		}else{
			$modsDatastream = $newPhoto->getDatastream('MODS');
		}

		//Build our MODS data
		$modsData = build_postcard_back_mods_data($repository, $postcardData);

		$normalizedObjectId = str_replace(':', '_', $objectId);
		$normalizedObjectId = str_replace('.', '_', $normalizedObjectId);
		file_put_contents("{$modsLocation}{$normalizedObjectId}.xml",$modsData);

		//Add Mods data to the datastream
		if ($isNew || ($modsDatastream->size != strlen($modsData))){
			$modsDatastream->setContentFromString($modsData);
		}

		//Add the MODS datastream to the object
		if ($isNew) {
			$newPhoto->ingestDatastream($modsDatastream);
			$repository->ingestObject($newPhoto);
		}
	} //Done setting up MODS

	//Add the thumbnail
	global $baseImageLocation;

	//Create Image data
	if ($isNew || ($newPhoto->getDatastream('OBJ') == null)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading TIFF image for back\r\n");
		if ($newPhoto->getDatastream('OBJ') == null){
			$imageDatastream = $newPhoto->constructDatastream('OBJ');
		}else {
			$imageDatastream = $newPhoto->getDatastream('OBJ');
		}


		$imageDatastream->label = $backImageName;
		$imageDatastream->mimetype = 'image/tiff';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($baseImageLocation . 'tif/'. $backImageName . '.tif');
		$newPhoto->ingestDatastream($imageDatastream);
		errorTrappedIngest($newPhoto, $imageDatastream, $baseImageLocation . 'tif/'. $backImageName . '.tif', 'tiff image', 'back', $logFile);
	}

	//Add the JP2 derivative
	$jp2Image = $baseImageLocation . 'jp2/'. $backImageName . '.jp2';
	if (($isNew || ($newPhoto->getDatastream('JP2') == null)) && file_exists($jp2Image)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading JP2 image for back\r\n");
		$imageDatastream = $newPhoto->constructDatastream('JP2');
		$imageDatastream->label = 'JPEG 2000';
		$imageDatastream->mimetype = 'image/jpg2';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($jp2Image);
		errorTrappedIngest($newPhoto, $imageDatastream, $jp2Image, 'jp2 image', 'back', $logFile);
	}

	$tnImage = $baseImageLocation . 'tn/'. $backImageName . '.jpg';
	if (($isNew || ($newPhoto->getDatastream('TN') == null)) && file_exists($tnImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading Thumbnail image for back\r\n");
		$imageDatastream = $newPhoto->constructDatastream('TN');
		$imageDatastream->label = 'Thumbnail';
		$imageDatastream->mimetype = 'image/jpg';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($tnImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the small image
	$smallImage = $baseImageLocation . '/sc/'. $backImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('SC') == null)) && file_exists($smallImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading small image for back\r\n");
		$imageDatastream = $newPhoto->constructDatastream('SC');
		$imageDatastream->label = 'Small Image for Pika';
		$imageDatastream->mimetype = 'image/png';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($smallImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the medium image
	$mediumImage = $baseImageLocation . '/mc/'. $backImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('MC') == null)) && file_exists($mediumImage)) {
		fwrite($logFile, date('Y-m-d H:i:s')."Uploading medium image for back\r\n");
		$imageDatastream = $newPhoto->constructDatastream('MC');
		$imageDatastream->label = 'Medium Image for Pika';
		$imageDatastream->mimetype = 'image/png';

		set_time_limit(1600);
		$imageDatastream->setContentFromFile($mediumImage);
		$newPhoto->ingestDatastream($imageDatastream);
		unset($imageDatastream);
	}

	//Add the large image
	$largeImage = $baseImageLocation . '/lc/'. $backImageName . '.png';
	if (($isNew || ($newPhoto->getDatastream('LC') == null)) && file_exists($largeImage)) {
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
			set_time_limit(1600);
			fwrite($logFile, date('Y-m-d H:i:s')."Uploading large image for back\r\n");
			try{
				$imageDatastream->setContentFromFile($largeImage);
			}catch(Exception $e){
				fwrite($logFile, date('Y-m-d H:i:s')."error uploading large image $e\r\n");
			}

			if ($newDataStream) {
				errorTrappedIngest($newPhoto, $imageDatastream, $largeImage, 'large image', 'back', $logFile);
			}
		}
	}
}

/**
 * @param FedoraRepository $repository
 * @param string $identifier
 * @return array
 */
function getObjectForIdentifier($identifier, $repository){
	global $fedoraPassword, $fedoraUser, 	$solrUrl;

	//Check Solr to see if we have created the compound object yet
	$escapedIdentifer = str_replace(':', '\:', $identifier);
	$solrQuery = "?q=mods_identifier_ms:\"$escapedIdentifer\"&fl=PID,dc.title";

	$context = stream_context_create(array(
			'http' => array(
					'header'  => "Authorization: Basic " . base64_encode("$fedoraUser:$fedoraPassword")
			)
	));
	//echo("checking solr ".$solrUrl . $solrQuery."<br/>");

	$ch=curl_init();
	$connectTimeout=5;
	$timeout=20;

	curl_setopt($ch, CURLOPT_URL, $solrUrl . $solrQuery);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	$curTry = 0;
	$maxTries = 3;

	while ($curTry < $maxTries){
		$solrResponse=curl_exec($ch);
		if ($solrResponse !== false){
			//We got a good response, stop looking.
			break;
		}
		$curTry++;
	}

	curl_close($ch);


	if (!$solrResponse){
		die("Solr is currently down");
	}else{
		$solrResponse = json_decode($solrResponse);
		if ($solrResponse->response->numFound == 0){
			$newObject = true;
			$existingPID = false;
		}else{
			$newObject = false;
			$existingPID = $solrResponse->response->docs[0]->PID;
		}
	}

	//Basic settings for this content type
	$namespace = 'fortlewis';

	//Create an object (this will create a new PID)
	/** @var AbstractFedoraObject $object */
	if ($newObject){
		$object = $repository->constructObject($namespace);
	}else{
		$object = $repository->getObject($existingPID);
	}

	return array($object, $newObject);
}

function build_postcard_mods_data($repository, $postcardData){
	$mods = "<?xml version=\"1.0\"?>";
	$mods .= "<mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">";
	$mods .= "<titleInfo>\r\n";
	$mods .= "<title>".htmlspecialchars($postcardData['title'])."</title>\r\n";
	$mods .= "</titleInfo>\r\n";
	$mods .= "<mods:genre>postcard</mods:genre>\r\n";
	$mods .= "<identifier>".htmlspecialchars($postcardData['acsnId'])."</identifier>\r\n";
	$mods .= "<identifier>".htmlspecialchars($postcardData['itemId'])."</identifier>\r\n";
	if (strlen($postcardData['dateCreated']) > 0) {
		$mods .= "<originInfo>\r\n";
		$qualifer = '';
		if (preg_match('/ca./', $postcardData['dateCreated'])){
			$qualifer = 'approximate';
			$postcardData['dateCreated'] = trim(str_replace('ca.', '', $postcardData['dateCreated']));
		}elseif (preg_match('/circa/', $postcardData['dateCreated'])){
			$qualifer = 'approximate';
			$postcardData['dateCreated'] = trim(str_replace('circa', '', $postcardData['dateCreated']));
		}elseif (preg_match("/'s/", $postcardData['dateCreated'])){
			$qualifer = 'approximate';
			$postcardData['dateCreated'] = trim(str_replace("'s", '', $postcardData['dateCreated']));
		}elseif (preg_match('/\d{4}s/', $postcardData['dateCreated'])){
			$qualifer = 'approximate';
			$postcardData['dateCreated'] = trim(str_replace("s", '', $postcardData['dateCreated']));
		}
		if (preg_match('/^([\\d-]+)\/([\\d-]+)$/', $postcardData['dateCreated'], $matches)) {
			$mods .= "<dateCreated point='start' qualifier='$qualifer'>" . htmlspecialchars($matches[1]) . "</dateCreated>\r\n";
			$mods .= "<dateCreated point='end' qualifier='$qualifer'>" . htmlspecialchars($matches[2]) . "</dateCreated>\r\n";
		}elseif (preg_match('/^\\d+\/\\d+\/\\d+$/', $postcardData['dateCreated'], $matches)) {
			$mods .= "<dateCreated point='start' qualifier='$qualifer'>" . htmlspecialchars($postcardData['dateCreated']) . "</dateCreated>\r\n";
		}elseif (preg_match('/^\\d+-\\d+-\\d+$/', $postcardData['dateCreated'], $matches)) {
			$mods .= "<dateCreated point='start' qualifier='$qualifer'>" . htmlspecialchars($postcardData['dateCreated']) . "</dateCreated>\r\n";
		}elseif (preg_match('/^(\\d{4})-(\\d{4})$/', $postcardData['dateCreated'], $matches)) {
			$mods .= "<dateCreated point='start' qualifier='$qualifer'>" . htmlspecialchars($matches[1]) . "</dateCreated>\r\n";
			$mods .= "<dateCreated point='end' qualifier='$qualifer'>" . htmlspecialchars($matches[2]) . "</dateCreated>\r\n";
		}else{
			$mods .= "<dateCreated point='start' qualifier='$qualifer'>" . htmlspecialchars($postcardData['dateCreated']) . "</dateCreated>\r\n";
		}

		$mods .= "</originInfo>\r\n";
	}
	$mods .= "<abstract>".htmlspecialchars($postcardData['description'])."</abstract>\r\n";
	$allSubjects = array();
	foreach ($postcardData['lc_subjects'] as $fullSubject){
		$subjectParts = explode('|', $fullSubject);
		//Always add the first part of the subject |a
		//$allSubjects[$subjectParts[0]] = $subjectParts[0];
		$fullSubject = $subjectParts[0];
		for ($i = 1; $i < count($subjectParts); $i++){
			$subjectWithoutIndicator = substr($subjectParts[$i], 1);
			$subfield = substr($subjectParts[$i], 0, 1);
			switch ($subfield){
				case 'z':
					//Ignore geographic portions of the LC subject since we have that as part of related entity
					break;
				case 'v': //Add form
				case 'x': //Add general subdivision
					$subjectWithForm = $subjectParts[0] . ' -- ' . $subjectWithoutIndicator;
					$fullSubject .= ' -- ' . $subjectWithoutIndicator;
					$allSubjects[$subjectWithForm] = $subjectWithForm;
					break;
				default:
					echo ("Unhandled subdivision $subfield");
			}
		}
		$allSubjects[$fullSubject] = $fullSubject;
	}

	foreach($allSubjects as $subject){
		$mods .= "<subject authority='lcsh'>\r\n";
			$mods .= "<topic>".htmlspecialchars($subject)."</topic>\r\n";
		$mods .= "</subject>\r\n";
	}

	$mods .= "<language>\r\n";
	$mods .= "<languageTerm authority='iso639-2b' type='code'>English</languageTerm>\r\n";
	$mods .= "</language>\r\n";
	$mods .= "<physicalDescription>\r\n";
	$mods .= "<extent>".htmlspecialchars($postcardData['itemName'])."</extent>\r\n";
	$mods .= "<form type='material'>".htmlspecialchars($postcardData['medium'])."</form>\r\n";
	$mods .= "</physicalDescription>\r\n";
	$mods .= "<physicalLocation>".htmlspecialchars($postcardData['volumeNumber'] . ' ' . $postcardData['photoNumber'])."</physicalLocation>\r\n";

	$mods .= "<extension>\r\n";
	$mods .= "<marmot:marmotLocal>\r\n";
	$mods .= "<marmot:correspondence>\r\n";

	$mods .= "<marmot:postcardPublisherNumber>".htmlspecialchars($postcardData['pubPhotoNumber'])."</marmot:postcardPublisherNumber>\r\n";
	$mods .= "</marmot:correspondence>\r\n";

	foreach($postcardData['creators'] as $creator){
		//reverse firstname and last name for people
		$creatorNameNormalized = trim($creator['name']);
		if (stripos($creator['type'], 'person') !== false ) {
			if (strpos($creatorNameNormalized, ',') > 0){
				$names = explode(',', $creatorNameNormalized, 2);
				$creatorNameNormalized = trim($names[1] . ' ' . $names[0]);
			}
		}
		$entityPID = doesEntityExist($creatorNameNormalized);

		//If entity does not exist, we should create it
		if ($entityPID == false){
			if (stripos($creator['type'], 'person') !== false ){
				$entityPID = createPerson($repository, $creatorNameNormalized);
			}else{
				$entityPID = createOrganization($repository, $creatorNameNormalized);
			}
		}

		if (stripos($creator['type'], 'donor') !== false ){
			$mods .= "<marmot:relatedPersonOrg>\r\n";
			$mods .= "<marmot:role>donor</marmot:role>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:relatedPersonOrg>\r\n";
		}elseif (stripos($creator['type'], 'photographer') !== false ){
			$mods .= "<marmot:hasCreator role='photographer'>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:hasCreator>\r\n";
		}elseif (stripos($creator['type'], 'artist') !== false ){
			$mods .= "<marmot:hasCreator role='artist'>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:hasCreator>\r\n";
		}elseif (stripos($creator['type'], 'author') !== false ){
			$mods .= "<marmot:hasCreator role='author'>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:hasCreator>\r\n";
		}elseif (stripos($creator['type'], 'owner') !== false ){
			$mods .= "<marmot:relatedPersonOrg>\r\n";
			$mods .= "<marmot:role>owner</marmot:role>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:relatedPersonOrg>\r\n";
		}elseif (stripos($creator['type'], 'publisher') !== false ){
			$mods .= "<marmot:relatedPersonOrg>\r\n";
			$mods .= "<marmot:role>publisher</marmot:role>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:relatedPersonOrg>\r\n";
		}elseif (stripos($creator['type'], 'producer') !== false ){
			$mods .= "<marmot:hasCreator role='producer'>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:hasCreator>\r\n";
		}else{
			echo("Unhandled creator type " . $creator['type']);
		}
	}
	foreach($postcardData['related_people'] as $person){
		$creatorNameNormalized = $person;
		if (strpos($creatorNameNormalized, ',') > 0){
			$names = explode(',', $creatorNameNormalized, 2);
			$creatorNameNormalized = trim($names[1] . ' ' . $names[0]);
		}

		$entityPID = doesEntityExist($creatorNameNormalized);

		//If entity does not exist, we should create it
		if ($entityPID == false){
			$entityPID = createPerson($repository, $creatorNameNormalized);
		}

		$mods .= "<marmot:relatedPersonOrg>\r\n";
		$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
		$mods .= "<marmot:entityTitle>".htmlspecialchars($creatorNameNormalized)."</marmot:entityTitle>\r\n";
		$mods .= "</marmot:relatedPersonOrg>\r\n";
	}
	foreach($postcardData['related_organizations'] as $organization){
		$entityPID = doesEntityExist($organization);

		//If entity does not exist, we should create it
		if ($entityPID == false){
			$entityPID = createOrganization($repository, $organization);
		}

		$mods .= "<marmot:picturedEntity>\r\n";
		$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
		$mods .= "<marmot:entityTitle>".htmlspecialchars($organization)."</marmot:entityTitle>\r\n";
		$mods .= "</marmot:picturedEntity>\r\n";
	}

	foreach($postcardData['geo_place_ids'] as $placeId){
		//Find the place in solr based on information entered as external data
		//mods_extension_marmotLocal_externalLink_fortLewisGeoPlaces_link_mlt
		list($entityPID, $title) = findPlaceByFortLewisId($placeId);

		if ($entityPID != false){
			$mods .= "<marmot:picturedEntity>\r\n";
			$mods .= "<marmot:entityPid>{$entityPID}</marmot:entityPid>\r\n";
			$mods .= "<marmot:entityTitle>".htmlspecialchars($title)."</marmot:entityTitle>\r\n";
			$mods .= "</marmot:picturedEntity>\r\n";
		}
	}
	$mods .= "<marmot:pikaOptions>\r\n";
	$mods .= "<marmot:includeInPika>yes</marmot:includeInPika>\r\n";
	$mods .= "<marmot:showInSearchResults>yes</marmot:showInSearchResults>\r\n";
	$mods .= "</marmot:pikaOptions>\r\n";
	$mods .= "</marmot:marmotLocal>\r\n";
	$mods .= "</extension>\r\n";
	$mods .= "<mods:accessCondition>\r\n";
	$mods .= "<marmot:rightsStatement>\r\n";
	$mods .= "The Center of Southwest Studies is not aware of any U.S. copyright or any other restrictions in the postcards in this collection.  However, some of the content may be protected by the U.S.  Copyright Law (Title 17, U.S.C.) and/or by the copyright or neighboring-rights laws of other nations.  Additionally, the reproduction of some materials may be restricted by privacy and/or publicity rights.";
	$mods .= "</marmot:rightsStatement>\r\n";
	$mods .= "</mods:accessCondition>\r\n";
	$mods .= "</mods>";

	return $mods;
}

function build_postcard_front_mods_data($repository, $postcardData){
	$mods = "<?xml version=\"1.0\"?>";
	$mods .= "<mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">";
	$mods .= "<titleInfo>\r\n";
	$mods .= "<title>".htmlspecialchars($postcardData['title'])." Front </title>\r\n";
	$mods .= "</titleInfo>\r\n";
	$mods .= "<identifier>".htmlspecialchars($postcardData['itemId'])."-Front</identifier>\r\n";
	$mods .= "<abstract>".htmlspecialchars($postcardData['description'])."</abstract>\r\n";
	$mods .= "<physicalDescription>\r\n";
	$mods .= "<extent>".htmlspecialchars($postcardData['itemName'])."</extent>\r\n";
	$mods .= "<form type='material'>".htmlspecialchars($postcardData['medium'])."</form>\r\n";
	$mods .= "</physicalDescription>\r\n";

	$mods .= "<extension>\r\n";
	$mods .= "<marmot:marmotLocal>\r\n";
	$mods .= "<marmot:pikaOptions>\r\n";
	$mods .= "<marmot:includeInPika>yes</marmot:includeInPika>\r\n";
	$mods .= "<marmot:showInSearchResults>no</marmot:showInSearchResults>\r\n";
	$mods .= "</marmot:pikaOptions>\r\n";
	$mods .= "</marmot:marmotLocal>\r\n";
	$mods .= "</extension>\r\n";
	$mods .= "<mods:accessCondition>\r\n";
	$mods .= "<marmot:rightsStatement>\r\n";
	$mods .= "The Center of Southwest Studies is not aware of any U.S. copyright or any other restrictions in the postcards in this collection.  However, some of the content may be protected by the U.S.  Copyright Law (Title 17, U.S.C.) and/or by the copyright or neighboring-rights laws of other nations.  Additionally, the reproduction of some materials may be restricted by privacy and/or publicity rights.";
	$mods .= "</marmot:rightsStatement>\r\n";
	$mods .= "</mods:accessCondition>\r\n";
	$mods .= "</mods>";

	return $mods;
}

function build_postcard_back_mods_data($repository, $postcardData){
	$mods = "<?xml version=\"1.0\"?>";
	$mods .= "<mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">";
	$mods .= "<titleInfo>\r\n";
	$mods .= "<title>".htmlspecialchars($postcardData['title'])." Back </title>\r\n";
	$mods .= "</titleInfo>\r\n";
	$mods .= "<identifier>".htmlspecialchars($postcardData['itemId'])."-Back</identifier>\r\n";
	$mods .= "<abstract>".htmlspecialchars($postcardData['description'])."</abstract>\r\n";
	$mods .= "<physicalDescription>\r\n";
	$mods .= "<extent>".htmlspecialchars($postcardData['itemName'])."</extent>\r\n";
	$mods .= "<form type='material'>".htmlspecialchars($postcardData['medium'])."</form>\r\n";
	$mods .= "</physicalDescription>\r\n";

	$mods .= "<extension>\r\n";
	$mods .= "<marmot:marmotLocal>\r\n";
	$mods .= "<marmot:pikaOptions>\r\n";
	$mods .= "<marmot:includeInPika>yes</marmot:includeInPika>\r\n";
	$mods .= "<marmot:showInSearchResults>no</marmot:showInSearchResults>\r\n";
	$mods .= "</marmot:pikaOptions>\r\n";
	$mods .= "</marmot:marmotLocal>\r\n";
	$mods .= "</extension>\r\n";
	$mods .= "<mods:accessCondition>\r\n";
	$mods .= "<marmot:rightsStatement>\r\n";
	$mods .= "The Center of Southwest Studies is not aware of any U.S. copyright or any other restrictions in the postcards in this collection.  However, some of the content may be protected by the U.S.  Copyright Law (Title 17, U.S.C.) and/or by the copyright or neighboring-rights laws of other nations.  Additionally, the reproduction of some materials may be restricted by privacy and/or publicity rights.";
	$mods .= "</marmot:rightsStatement>\r\n";
	$mods .= "</mods:accessCondition>\r\n";
	$mods .= "</mods>";

	return $mods;
}


