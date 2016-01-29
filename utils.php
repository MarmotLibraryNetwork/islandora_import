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