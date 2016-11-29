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

$config = parse_ini_file(ROOT_DIR . '/western_past_perfect_config.ini');

//Read the XML File
$sourceXMLFile =  $config['sourceXMLFile'];
$baseImageLocation = $config['baseImageLocation'];
$jp2ImageLocation = $config['jp2ImageLocation'];
$otherImageLocation = $config['otherImageLocation'];
$updateModsForExistingEntities = $config['updateModsForExistingEntities'];
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
$recordsNoImage = fopen($logPath . "noImageRecords.log", 'w');

$extractedEntitiesLog = fopen($logPath . "extractedEntities.log", 'w');
$extractedEntities = array();

require_once(ROOT_DIR . '/utils.php');

$xml = simplexml_load_file($sourceXMLFile);
if (!$xml){
	echo("Failed to read XML, boo");
}else{
	//Process each record
	$recordsProcessed = 0;
	$recordsRead = 0;

	/** @var SimpleXMLElement $exportedItem */
	foreach ($xml->export as $exportedItem){
		$recordsRead++;

		if (strlen($exportedItem->objectid) == 0){
			//No object
			continue;
		}

		//Check to see if we have the image
		$imageFilename = trim($exportedItem->imagefile);
		if (strlen($imageFilename) == 0){
			//No point importing something that doesn't have a file
			fwrite($logFile, "Warning, " . (string)$exportedItem->objectid . " has no image defined for it, skipping.\r\n");
			continue;
		}

		$title = trim($exportedItem->title);
		if (empty($title)){
			$title = trim($exportedItem->descrip);
			if (strlen($title) > 128){
				$title = explode("\n", $title, 2);
				$title = $title[0];
				if (strlen($title) > 128) {
					$title = substr($title, 0, strrpos($title, ' ', -strlen($title) + 128)) . '...';
				}
			}
		}

		$validImage = false;

		//Remove the folder from the image file
		$imageFilename = substr($imageFilename, strpos($imageFilename, '\\') + 1);

		//test to see if we can get the tiff instead of the jpg
		$tifFilename = str_ireplace('.jpg', '.tif', $imageFilename);
		if (file_exists($baseImageLocation . $tifFilename)){
			$imageFilename = $tifFilename;
			$validImage = true;
		}

		if (!$validImage){
			//Check the objectid to see if we have an image with that name
			$imageFilename = (string)$exportedItem->objectid . ".tif";
			if (file_exists($baseImageLocation . $imageFilename)){
				$validImage = true;
			}else{
				//looks like several use the convention of objectid-large.tif
				$imageFilename = (string)$exportedItem->objectid . "-large.tif";
				if (file_exists($baseImageLocation . $imageFilename)){
					$validImage = true;
				}
			}
		}

		$baseImageFilename = substr($imageFilename, 0, strrpos($imageFilename, '.'));

		//Make sure that the image exists in what we have downloaded
		if ($validImage){
			$objectId = (string)$exportedItem->objectid;

			$recordsProcessed++;

			$studio = trim($exportedItem->studio);
			if (strlen($studio) > 0 && $studio != '-' && !array_key_exists($studio, $extractedEntities)){
				$existingPid = doesEntityExist($studio);
				$extractedEntities[$studio] = $existingPid;
			}
			$people=preg_split('/\r\n|\r|\n/', $exportedItem->people);
			foreach($people as $person){
				$person = trim($person);
				if (strlen($person) > 0 && $person != '-' && !array_key_exists($person, $extractedEntities)){
					$existingPid = doesEntityExist($person);
					$extractedEntities[$person] = $existingPid;
				}
			}
			$places=preg_split('/\r\n|\r|\n/', $exportedItem->place);
			foreach ($places as $place) {
				$place = trim($place);
				if (strlen($place) > 0 && $place != '-' && !array_key_exists($place, $extractedEntities)){
					$existingPid = doesEntityExist($place);
					$extractedEntities[$place] = $existingPid;
				}
			}

			set_time_limit(60);
		}else{
			fwrite($recordsNoImage, "{$exportedItem->objectid}\r\n");
		}
	}
	fwrite($logFile, date('Y-m-d H:i:s')."Done\r\n");
	fclose($logFile);
	fclose($recordsNoImage);
	ksort($extractedEntities);
	foreach ($extractedEntities as $name => $pid){
		fputcsv($extractedEntitiesLog, array($name, $pid));
	}
	fclose($extractedEntitiesLog);

}


