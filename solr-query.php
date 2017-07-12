<?php
function usage() {
	print ('Missing or bad arguments !');
	exit(-1);
}
function error() {
	print ('Error !');
	exit(-1);
}

function getParam($name, $params, $collection, $default) {
	$general_value = isset($params['general'][$name]) ? $params['general'][$name] : $default;
	if (!empty($collection))
		$value = isset($params[$collection][$name]) ? $params[$collection][$name] : $general_value;
	else
		$value = $general_value;
	return $value;
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

$timezone = getParam('timezone', $params, '', '');
if (!empty($timezone)) date_default_timezone_set($timezone);

$log_dir = getParam('log_dir', $params, $collection, './data_log');
if (empty($log_dir)) usage();
if (!file_exists($log_dir)) error();

$log_pattern = getParam('log_pattern', $params, $collection, $collection . '.log');

include ('solr.class.inc.php');

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */
$file_cnt=0;

$solr = new Solr($solr_url, $collection);
if (!$solr) error();

print (date('G:i:s') . "\n");

$files = glob($log_dir . '/' . $log_pattern, GLOB_BRACE);
$file_cnt=0;
$loop_count=0;
$loop_duration=0;
$handle=0;

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
		if (($line = fgets($handle)) !== false) {
			$line_items = explode(' ', $line);

			if ($line_items[0] == 'INFO' && $line_items[10] == '[' . $collection . ']' && $line_items[12] == 'path=/select') {
				$params = array();
				$p = explode('&', substr($line_items[13], strlen('params={'), -1));
				foreach ($p as $v1) {
					$v2 = explode('=', $v1);
					$params[$v2[0]] = urldecode($v2[1]);
				}
				$params['indent'] = 'true';
				print("query\n");
				$data = $solr->get($params);

			}
		} else {
			fclose($handle);
			$handle = 0;
			$file_loop_cnt++;
			$file_cnt++;
		}
	} else {
		print("pause\n");
		sleep(1);
	}
	$loop_duration = time() - $loop_start_time;
	if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) {
		$loop_start_time = time();
		$loop_duration=0;
	}
}

print (date('G:i:s') . "\n");


?>