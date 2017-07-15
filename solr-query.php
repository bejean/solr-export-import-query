<?php
include ('helpers.inc.php');
include ('solr.class.inc.php');

function usage() {
	print ('Missing or bad arguments !');
	exit(-1);
}


$options = getopt("i:c:");

$param_file = isset($options['i']) ? $options['i'] : 'query.ini';
if (!file_exists($param_file)) usage();
$params = parse_ini_file($param_file, true);

$collection = isset($options['c']) ? $options['c'] : getParam('collection', $params, '', '');
if (empty($collection)) usage();

$solr_url = getParam('solr_url', $params, $collection, '');
if (empty($solr_url)) usage();

$loop_max_count = getParam('loop_max_count', $params, $collection, '0');
$loop_time_duration = getParam('loop_time_duration', $params, $collection, '0');
$loop_time_request_duration = getParam('loop_time_request_duration', $params, $collection, '0');
$random = (getParam('random', $params, $collection, '0') == '1');
$verbose = (getParam('verbose', $params, $collection, '0') == '1');

$timezone = getParam('timezone', $params, '', '');
if (!empty($timezone)) date_default_timezone_set($timezone);

$log_dir = getParam('log_dir', $params, $collection, './data_log');
if (empty($log_dir)) usage();
	if (!file_exists($log_dir)) error($log_dir);

$log_pattern = getParam('log_pattern', $params, $collection, $collection . '.log');

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */
$file_cnt=0;

$solr = new Solr($solr_url, $collection);
if (!$solr) error($solr_url . '/' .  $collection);

verbose('Starting queries for collection : ' . $collection, $verbose);

$files = glob($log_dir . '/' . $log_pattern, GLOB_BRACE);
$file_cnt=0;
$loop_count=0;
$loop_duration=0;
$handle=0;
$pause=false;

$loop_start_time = time();
while ($loop_max_count==0 || $loop_count<$loop_max_count) {

	$file_loop_cnt = 0;
	$file_cnt++;

	if ($handle==0) {
		if ($random)
			$ndx = rand(0, count($files)-1);
		else
			$ndx = ($file_loop_cnt == 0) ? 0 : $ndx + 1;
		$handle = fopen(realpath($files[$ndx]), "r");
		if (!$handle) exit(1);
	}

	if ($loop_time_request_duration == 0 || $loop_duration < $loop_time_request_duration) {
		if ($pause) {
			$pause = false;
			verbose('Pause ends', $verbose);
		}
		if (($line = fgets($handle)) !== false) {
			$line_items = explode(' ', $line);

			$alternative_query_collection = getAlternativeCollectionName(substr($line_items[10], 1 , -1));
			if ($line_items[0] == 'INFO' && $alternative_query_collection == $collection && $line_items[12] == 'path=/select') {
				$params = array();
				$p = explode('&', substr($line_items[13], strlen('params={'), -1));
				foreach ($p as $v1) {
					$v2 = explode('=', $v1);
					$params[$v2[0]] = urldecode($v2[1]);
				}
				$params['indent'] = 'true';
				//print("query\n");
				if ($alternative_query_collection!=$collection) {
					$solr = new Solr($solr_url, $alternative_query_collection);
					if (!$solr) error($solr_url . '/' .  $alternative_query_collection);
				}
				$data = $solr->get($params);
			}
		} else {
			fclose($handle);
			$handle = 0;
			$file_loop_cnt++;
			$file_cnt++;
		}
	} else {
		if (!$pause) {
			$pause = true;
			verbose('Pause starts', $verbose);
		}
		sleep(1);
	}
	$loop_duration = time() - $loop_start_time;
	if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) {
		$loop_start_time = time();
		$loop_duration=0;
	}
}

verbose('Queries end', $verbose);


?>