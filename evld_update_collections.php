<?php
/**
 * Update collections for gravestones and headstones
 *
 * @category islandora_import
 * @author Mark Noble <mark@marmot.org>
 * Date: 7/28/2016
 * Time: 9:59 AM
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

$logPath = $config['logPath'];
if(!file_exists($logPath)){
	mkdir($logPath);
}
$logFile = fopen($logPath . "evld_update_collections_". time() . ".log", 'w');

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

convertCollection('1995.009', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2008.010', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2008.014', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2008.015', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2008.016', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2008.017', 'evld:5206', $repository, $solrUrl, $logFile);
convertCollection('2015.006', 'evld:5208', $repository, $solrUrl, $logFile);

function convertCollection($identifier, $newCollection, $repository, $solrUrl, $logFile){
	//Query Solr for all basic images that need to be replaced with large images
	$solrQuery = "?q=RELS_EXT_isMemberOfCollection_uri_ms:\"info:fedora/evld:localHistoryArchive\"+AND+mods_extension_marmotLocal_migratedIdentifier_s:{$identifier}*&fl=PID,dc.title";
	$solrResponse = file_get_contents($solrUrl . $solrQuery . "&rows=200", false);

	if (!$solrResponse) {
		die();
	} else {
		$solrResponse = json_decode($solrResponse);
		if (!$solrResponse->response || $solrResponse->response->numFound == 0) {
			fwrite($logFile, 'Nothing to convert for records starting with identifier ' . $identifier);
		}else{
			foreach ($solrResponse->response->docs as $record){
				$pid = $record->PID;
				fwrite($logFile, "Processing $pid\r\n");

				$fedoraObject = $repository->getObject($pid);
				$fedoraObject->relationships->remove(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', 'evld:localHistoryArchive');
				$fedoraObject->relationships->add(FEDORA_RELS_EXT_URI, 'isMemberOfCollection', $newCollection);

				//wait for 60 seconds to let the islandora server catch up
				sleep(60);
			}
		}
	}

}

