<?php
include ('helpers.inc.php');
include ('solr.class.inc.php');

function usage() {
	print ('Missing or bad arguments !');
	exit(-1);
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
$commit_each_pause = getParam('commit_each_pause', $params, $collection, '0');
$commit_final = (getParam('commit_final', $params, $collection, '1') == '1');
$optimize_final = (getParam('optimize_final', $params, $collection, '0') == '1');
$verbose = (getParam('verbose', $params, $collection, '0') == '1');

$loop_max_count = getParam('loop_max_count', $params, $collection, '0');
$loop_time_duration = getParam('loop_time_duration', $params, $collection, '0');
$loop_time_request_duration = getParam('loop_time_request_duration', $params, $collection, '0');
$random = (getParam('random', $params, $collection, '0') == '1');

$randomize_unique_key = getParam('randomize_unique_key', $params, $collection, '');
$randomize_unique_key_mode = getParam('randomize_unique_key_mode', $params, $collection, '');

$timezone = getParam('timezone', $params, '', '');
if (!empty($timezone)) date_default_timezone_set($timezone);

$input_dir = getParam('input_dir', $params, $collection, './data_export');
if (empty($input_dir)) usage();
if (!file_exists($input_dir)) error();

$input_file_pattern = getParam('input_file_pattern', $params, $collection, '*.json');

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */

$solr = new Solr($solr_url, $collection);
if (!$solr) error();

verbose('Starting import for collection : ' . $collection, $verbose);

$files = glob($input_dir . '/' . $input_file_pattern, GLOB_BRACE);
$file_cnt=0;
$loop_count=0;
$pause=false;

while ($loop_max_count==0 || $loop_count<$loop_max_count) {

	$loop_start_time = time();

	$file_loop_cnt = 0;
	$loop_duration = 0;
	while ($file_loop_cnt < count($files)) {
		if ($loop_time_request_duration == 0 || $loop_duration < $loop_time_request_duration) {
			if ($pause) {
				$pause = false;
				verbose('Pause ends', $verbose);
			}
			if ($random)
				$ndx = rand(0, count($files)-1);
			else
				$ndx = ($file_loop_cnt == 0) ? 0 : $ndx + 1;

			$content= file_get_contents($files[$ndx]);
			$docs = json_decode($content);
			//$content = '{';
			for ($i = 0; $i <count($docs); $i++) {
				if (!empty($randomize_unique_key)) {
					if ($randomize_unique_key_mode=='append')
						$docs[$i]->$randomize_unique_key = uniqid($docs[$i]->$randomize_unique_key . '_');
					else
						$docs[$i]->$randomize_unique_key = uniqid();
				}
				//if ($i>0) $content .= ',';
				//$content .= '"add": {"doc": ' . json_encode($docs[$i]) . '}}';
			}
			//$content .= '}';
			$content = json_encode($docs);

			verbose('Post documents [' . $file_cnt . '/' . count($docs) . ' docs/' . strlen($content) . ' bytes]', $verbose);

			$alternative_collection = getAlternativeCollectionName($collection);
			if ($alternative_collection!=$collection) {
				$solr = new Solr($solr_url, $alternative_collection);
				if (!$solr) error();
			}

			$solr->post_binarydata($content);
			$file_cnt++;
			$file_loop_cnt++;

			if ($commit_each_file > 0 && ($file_cnt % $commit_each_file) == 0) {
				verbose('Commit', $verbose);
				$solr->commit();
			}
			if ($max_files > 0 && $file_cnt == $max_files) break;
		} else {
			if (!$pause) {
				$pause = true;
				verbose('Pause starts', $verbose);
				if ($commit_each_pause) {
					verbose('Commit', $verbose);
					$solr->commit();
				}
			}
			sleep(1);
		}

		$loop_duration = time() - $loop_start_time;
		if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) break;
	}
	$loop_count++;
}

verbose('Commit', $verbose);
if ($commit_final) $solr->commit();

verbose('Optimize', $verbose);
if ($optimize_final) $solr->optimize();

verbose('Import end', $verbose);
?>