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