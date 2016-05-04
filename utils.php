<?php
/**
 * Utility functionality to aid in import
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 1/20/2016
 * Time: 11:39 AM
 */

/**
 * @param String $name
 *
 * @return bool|string Returns false if the object does not exist, or the PID if it does exist
 */
global $existingEntities;
if ($existingEntities == null){
	$existingEntities = array();
}
function doesEntityExist($name){
	global $existingEntities;
	global $solrUrl, $fedoraUser, $fedoraPassword;
	if (array_key_exists($name, $existingEntities)){
		return $existingEntities[$name];
	}
	$name = str_replace('"', '\"', $name);
	$solrQuery = "?q=fgs_label_s:\"" . urlencode($name) . "\"&fl=PID,dc.title";

	//echo($solrUrl . $solrQuery);
	//Give Solr some time to respond
	set_time_limit(60);
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
			$existingEntities[$name] = $solrResponse->response->docs[0]->PID;
			return $solrResponse->response->docs[0]->PID;
		}
	}
}

global $existingFLCPlaces;
if ($existingFLCPlaces == null){
	$existingFLCPlaces = array();
}
function findPlaceByFortLewisId($fortLewisIdentifier){
	global $existingFLCPlaces;
	global $solrUrl, $fedoraUser, $fedoraPassword;
	if (array_key_exists($fortLewisIdentifier, $existingFLCPlaces)){
		return $existingFLCPlaces[$fortLewisIdentifier];
	}
	$solrQuery = "?q=mods_extension_marmotLocal_externalLink_fortLewisGeoPlaces_link_mlt:\"" . urlencode($fortLewisIdentifier) . "\"&fl=PID,fgs_label_s";

	//echo($solrUrl . $solrQuery);
	//Give Solr some time to respond
	set_time_limit(60);
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
			return array(false, 'Unknown');
		}else{
			$firstDoc = $solrResponse->response->docs[0];
			$pid = $solrResponse->response->docs[0]->PID;
			$title = $firstDoc->fgs_label_s;
			$existingFLCPlaces[$fortLewisIdentifier] = array($pid, $title);

			return $existingFLCPlaces[$fortLewisIdentifier];
		}
	}
}

function createPerson($repository, $personName){
	$existingPID = doesEntityExist($personName);
	if ($existingPID){
		return $existingPID;
	}else{
		//Create an entity within Islandora
		$entity = $repository->constructObject('person');
		$entity->models = array('islandora:personCModel');
		$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'marmot:people');
		$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:entity_collection');

		$entity->label = $personName;
		$modsDatastream = $entity->constructDatastream('MODS');

		$modsDatastream->label = 'MODS Record';
		$modsDatastream->mimetype = 'text/xml';
		$nameParts = explode(",", $personName);
		$lastName = trim($nameParts[0]);
		$modsDatastream->setContentFromString("<?xml version=\"1.0\"?><mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><mods:titleInfo><mods:title>{$personName}</mods:title></mods:titleInfo><mods:extension><marmot:marmotLocal><marmot:familyName>{$lastName}</marmot:familyName><marmot:pikaOptions><marmot:includeInPika>yes</marmot:includeInPika><marmot:showInSearchResults>yes</marmot:showInSearchResults></marmot:pikaOptions></marmot:marmotLocal></mods:extension></mods>");
		$entity->ingestDatastream($modsDatastream);

		$repository->ingestObject($entity);
		$existingEntities[$personName] = $entity->id;
		return $entity->id;
	}
}

function createOrganization($repository, $organization){
	$existingPID = doesEntityExist($organization);
	if ($existingPID){
		return $existingPID;
	}else{
		//Create an entity within Islandora
		$entity = $repository->constructObject('organization');
		$entity->models = array('islandora:organizationCModel');
		$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'marmot:organizations');
		$entity->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'islandora:entity_collection');

		$entity->label = $organization;
		$modsDatastream = $entity->constructDatastream('MODS');

		$modsDatastream->label = 'MODS Record';
		$modsDatastream->mimetype = 'text/xml';
		$modsDatastream->setContentFromString("<?xml version=\"1.0\"?><mods xmlns=\"http://www.loc.gov/mods/v3\" xmlns:marmot=\"http://marmot.org/local_mods_extension\" xmlns:mods=\"http://www.loc.gov/mods/v3\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><mods:titleInfo><mods:title>{$organization}</mods:title></mods:titleInfo><mods:extension><marmot:marmotLocal><marmot:pikaOptions><marmot:includeInPika>yes</marmot:includeInPika><marmot:showInSearchResults>yes</marmot:showInSearchResults></marmot:pikaOptions></marmot:marmotLocal></mods:extension></mods>");
		$entity->ingestDatastream($modsDatastream);

		$repository->ingestObject($entity);
		$existingEntities[$organization] = $entity->id;
		return $entity->id;
	}
}