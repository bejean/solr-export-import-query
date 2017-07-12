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

$param_file = isset($options['i']) ? $options['i'] : 'import.ini';
if (!file_exists($param_file)) usage();
$params = parse_ini_file($param_file, true);

$collection = isset($options['c']) ? $options['c'] : getParam('collection', $params, '', '');
if (empty($collection)) usage();

$solr_url = getParam('solr_url', $params, $collection, '');
if (empty($solr_url)) usage();

$max_files = getParam('max_files', $params, $collection, '0');
$commit_each_file = getParam('commit_each_file', $params, $collection, '0');
$commit_final = (getParam('commit_final', $params, $collection, '1') == '1');
$optimize_final = (getParam('optimize_final', $params, $collection, '0') == '1');

$loop_max_count = getParam('loop_max_count', $params, $collection, '0');
$loop_time_duration = getParam('loop_time_duration', $params, $collection, '0');
$loop_time_request_duration = getParam('loop_time_request_duration', $params, $collection, '0');
$random = (getParam('random', $params, $collection, '0') == '1');

$timezone = getParam('timezone', $params, '', '');
if (!empty($timezone)) date_default_timezone_set($timezone);

$input_dir = getParam('input_dir', $params, $collection, './data_export');
if (empty($input_dir)) usage();
if (!file_exists($input_dir)) error();

include ('solr.class.inc.php');

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */

$solr = new Solr($solr_url, $collection);
if (!$solr) error();

print (date('G:i:s') . "\n");

$files = glob($input_dir . '/*.json', GLOB_BRACE);
$file_cnt=0;
$loop_count=0;

while ($loop_max_count==0 || $loop_count<$loop_max_count) {

	$loop_start_time = time();

	$file_loop_cnt = 0;
	$loop_duration = 0;
	while ($file_loop_cnt < count($files)) {
		if ($loop_time_request_duration == 0 || $loop_duration < $loop_time_request_duration) {
			if ($random)
				$ndx = rand(0, count($files)-1);
			else
				$ndx = ($file_loop_cnt == 0) ? 0 : $ndx + 1;

			$solr->post_binarydata(realpath($files[$ndx]));
			$file_cnt++;
			$file_loop_cnt++;

			if ($commit_each_file > 0 && ($file_cnt % $commit_each_file) == 0) $solr->commit();
			if ($max_files > 0 && $file_cnt == $max_files) break;
		} else {
			sleep(1);
		}

		$loop_duration = time() - $loop_start_time;
		if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) break;
	}
	$loop_count++;
}

if ($commit_final) $solr->commit();
print (date('G:i:s') . "\n");

if ($optimize_final) $solr->optimize();
print (date('G:i:s') . "\n");

?>