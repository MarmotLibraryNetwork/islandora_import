<?php
date_default_timezone_set('America/Denver');

$objectsAtCherryHillFhnd = fopen("C:/data/islandora_server_migration/objects_at_cherry_hill.txt", 'r');
$objectsOnEuropaFhnd = fopen("C:/data/islandora_server_migration/objects_on_europa.txt", 'r');

$objectsAtCherryHill = array();
$objectsOnEuropa = array();
$changedObjects = array();
$missingObjects = array();

$curObject = null;
while (($line = fgets($objectsAtCherryHillFhnd)) !== false) {
	$line = trim($line);
	if (preg_match('/pid\s+(.*)/', $line, $matches)){
		if ($curObject != null){
			$objectsAtCherryHill[$curObject->pid] = $curObject;
			$curObject = null;
		}
		$curObject = new stdClass();
		$curObject->pid = $matches[1];
	}elseif (preg_match('/cDate\s+(.*)/', $line, $matches)){
		$curObject->cDate = $matches[1];
	}elseif (preg_match('/mDate\s+(.*)/', $line, $matches)){
		$curObject->mDate = $matches[1];
	}
}
if ($curObject != null){
	$objectsAtCherryHill[$curObject->pid] = $curObject;
}
fclose($objectsAtCherryHillFhnd);

$curObject = null;
while (($line = fgets($objectsOnEuropaFhnd)) !== false) {
	$line = trim($line);
	if (preg_match('/pid\s+(.*)/', $line, $matches)){
		if ($curObject != null){
			$objectsOnEuropa[$curObject->pid] = $curObject;
			$curObject = null;
		}
		$curObject = new stdClass();
		$curObject->pid = $matches[1];
	}
}
if ($curObject != null){
	$objectsOnEuropa[$curObject->pid] = $curObject;
}
fclose($objectsOnEuropaFhnd);


$exportStart = strtotime('2016-08-25');
$allowableNamespaces = array('marmot', 'adams', 'testcollection', 'steamboatlibrary', 'ccu', 'CCU', 'evld', 'fortlewis', 'garfield', 'gunnison', 'mesa', 'salida', 'vail', 'western', 'person', 'pineriver', 'organization', 'place', 'event', 'ir');
$nonConvertedNamespaces = array();
$namespacesWithMissingPids = array();
$namespacesWithUpdatedPids = array();
$suppressedPids = array();
foreach ($objectsAtCherryHill as $object){
	list($namespace) = explode(':', $object->pid);
	if (in_array($namespace, $allowableNamespaces)){
		if (!array_key_exists($object->pid, $objectsOnEuropa)){
			$missingObjects[] = $object;
			if (!in_array($namespace, $namespacesWithMissingPids)){
				$namespacesWithMissingPids[] = $namespace;
			}
		}elseif (strtotime($object->mDate) > $exportStart){
			$changedObjects[] = $object;
			if (!in_array($namespace, $namespacesWithUpdatedPids)){
				$namespacesWithUpdatedPids[] = $namespace;
			}
		}
	}else{
		if (!in_array($namespace, $nonConvertedNamespaces)){
			$nonConvertedNamespaces[] = $namespace;
			echo("$namespace is suppressed in the conversion<br/>\r\n");
		}
		$suppressedPids[] = $object;
	}
}

foreach ($namespacesWithMissingPids as $namespace){
	$pidsToUpdateFhnd = fopen("C:/data/islandora_server_migration/fix_missing_pids_$namespace.sh", 'w');
	fwrite($pidsToUpdateFhnd, "#!/bin/bash\n");
	foreach ($missingObjects as $object){
		list($objectNamespace) = explode(':', $object->pid);
		if ($objectNamespace == $namespace) {
			fwrite($pidsToUpdateFhnd, "./fedora-ingest.sh r islandora.marmot.org:443 fedoraAdmin WwfNyV3rUudrE5 {$object->pid} localhost:8080 fedoraAdmin fedoraAdmin https http\n");
		}
	}
	fclose($pidsToUpdateFhnd);
}

$pidsToUpdateFhnd = fopen("C:/data/islandora_server_migration/update_changed_pids.sh", 'w');
fwrite($pidsToUpdateFhnd, "#!/bin/bash\n");
foreach ($changedObjects as $object){
	fwrite($pidsToUpdateFhnd, "./loadIndividualObject.sh {$object->pid}\n");
}
fclose($pidsToUpdateFhnd);

$suppressedPidsFhnd = fopen("C:/data/islandora_server_migration/suppressed_pids.txt", 'w');
foreach ($suppressedPids as $object){
	fwrite($suppressedPidsFhnd, "{$object->pid}\r\n");
}
fclose($suppressedPidsFhnd);

