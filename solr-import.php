<?php
include ('helpers.inc.php');
include ('solr.class.inc.php');

function usage() {
    print ('Usage : php solr-import.php -i <inifile> -c <collection_section_in_inifile');
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

verbose($solr->getCollection() . ' - Starting import for collection : ' . $collection, $verbose);

$files = glob($input_dir . '/' . $input_file_pattern, GLOB_BRACE);
if (count($files)==0) error("no data file");
$file_cnt=0;
$loop_count=0;
$pause=false;

while ($loop_max_count==0 || $loop_count<$loop_max_count) {

	$loop_start_time = time();

	$file_loop_cnt = 0;
	$loop_duration = 0;
    $ndx = 0;
	while ($file_loop_cnt < count($files)) {
		if ($loop_time_request_duration == 0 || $loop_duration < $loop_time_request_duration) {
			if ($pause) {
				$pause = false;
				verbose($solr->getCollection() . ' - Pause ends', $verbose);
			}

			verbose('Read data in : ' . $files[$ndx], $verbose);
			$content= file_get_contents($files[$ndx]);
			$docs = json_decode($content);

			$alternative_collection = getAlternativeCollectionName($collection);
			if ($alternative_collection!=$collection) {
				$solr = new Solr($solr_url, $alternative_collection);
				if (!$solr) error();
			}

			for ($i = 0; $i <count($docs); $i++) {
				if (!empty($randomize_unique_key)) {
					switch($randomize_unique_key_mode) {
						case 'collection_name_append':
							$docs[$i]->$randomize_unique_key = $docs[$i]->$randomize_unique_key . '_' . $alternative_collection;
							break;
						case 'uniqid_append':
							$docs[$i]->$randomize_unique_key = uniqid($docs[$i]->$randomize_unique_key . '_');
							break;
						case 'uniqid_replace':
							$docs[$i]->$randomize_unique_key = uniqid();
							break;
					}
				}
			}
			$content = json_encode($docs);

			verbose($solr->getCollection() . ' - Post documents [' . $file_cnt . '/' . count($docs) . ' docs/' . strlen($content) . ' bytes]', $verbose);

			$result = json_decode(json_encode($solr->post_binarydata($content)),true);
            if ($result['responseHeader']['status'] != 0) {
                //$e = 1;
            }
			$file_cnt++;
			$file_loop_cnt++;

			if ($commit_each_file > 0 && ($file_cnt % $commit_each_file) == 0) {
				verbose($solr->getCollection() . ' - Commit', $verbose);
				$solr->commit();
			}

            if ($random)
                $ndx = rand(0, count($files)-1);
            else
                $ndx++;
            //$ndx = ($loop_count == 0 && $file_loop_cnt == 0) ? 0 : $ndx + 1;

            if ($ndx>count($files)-1) break;

			if ($max_files > 0 && $file_cnt == $max_files) break;
		} else {
			if (!$pause) {
				$pause = true;
				verbose($solr->getCollection() . ' - Pause starts', $verbose);
				if ($commit_each_pause) {
					verbose($solr->getCollection() . ' - Commit', $verbose);
					$solr->commit();
				}
			}
			sleep(1);
		}

		$loop_duration = time() - $loop_start_time;
		if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) break;
	}
	$loop_count++;

    verbose($solr->getCollection() . ' - Commit', $verbose);
    $solr->commit();
}
verbose($solr->getCollection() . ' - Commit', $verbose);
$solr->commit();

verbose($solr->getCollection() . ' - Commit', $verbose);
if ($commit_final) $solr->commit();

verbose($solr->getCollection() . ' - Optimize', $verbose);
if ($optimize_final) $solr->optimize();

verbose($solr->getCollection() . ' - Import end', $verbose);
?>