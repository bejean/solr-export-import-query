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

$collection = isset($options['c']) ? $options['c'] : '';
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
	if (!file_exists($log_dir)) error('log dir : ' . $log_dir);

$log_pattern = getParam('log_pattern', $params, $collection, $collection . '.log');

$output_file = getParam('output_csv', $params, $collection);

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */
$file_cnt=0;

$solr = new Solr($solr_url, $collection);
if (!$solr) error('Solr url : ' . $solr_url . '/' .  $collection);

verbose($solr->getCollection() . ' - Starting queries for collection : ' . $collection, $verbose);

$files = glob($log_dir . '/' . $log_pattern, GLOB_BRACE);
if (count($files)==0) error("no query log file");
$file_cnt=0;
$loop_count=0;
$loop_duration=0;
$handle=0;
$pause=false;


$handle_out = 0;
if (!empty($output_file))
    $handle_out = fopen($output_file, 'a');

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
			verbose($solr->getCollection() . ' - Pause ends', $verbose);
		}
		if (($line = trim(fgets($handle))) !== false) {
            verbose($solr->getCollection() . ' - ' . $line, $verbose);
			$line_items = explode(' ', $line);

			$d = $line_items[0];
            $t = $line_items[1];
            $c = $line_items[2];
            $q = $line_items[3];
            $hits = $line_items[4];
            $qt = $line_items[5];

            //$alternative_query_collection = getAlternativeCollectionName(substr($c, 1 , -1));
			//if ($alternative_query_collection == $collection && $line_items[12] == 'path=/select') {
			//	$line_items[13] = GetAlternativeQuery($line_items[13], $collection);
				$solr_params = array();
				$p = explode('&', $q);
				foreach ($p as $v1) {
					$v2 = explode('=', $v1);
					if (!array_key_exists($v2[0], $solr_params)) {
						$solr_params[$v2[0]] = array();
					}
					$vtemp = $solr_params[$v2[0]];
					$vtemp[] = urldecode($v2[1]);
					$solr_params[$v2[0]] = $vtemp;
				}
				$solr_params['indent'] = 'true';
				//print("query\n");
				//if ($alternative_query_collection!=$collection) {
				//	$solr = new Solr($solr_url, $alternative_query_collection);
				//	if (!$solr) error('Solr url : ' . $solr_url . '/' .  $alternative_query_collection);
				//}
                verbose($solr->getCollection() . ' - ' . $solr_url . $c . '/select?' . $q, $verbose);
                $data = json_decode(json_encode($solr->get($solr_params)),true);
                $rqt = $data['responseHeader']['QTime'];
                $rqh = $data['response']['numFound'];

            if (!empty($handle_out))
                fputcsv ( $handle_out, array ($d, $t, $c, $hits, $qt, $rqh, $rqt) );

			//}
		} else {
			fclose($handle);
			$handle = 0;
			$file_loop_cnt++;
			$file_cnt++;
		}
	} else {
		if (!$pause) {
			$pause = true;
			verbose($solr->getCollection() . ' - Pause starts', $verbose);
		}
		sleep(1);
	}
	$loop_duration = time() - $loop_start_time;
	if ($loop_time_duration>0 && $loop_duration>$loop_time_duration) {
		$loop_start_time = time();
		$loop_duration=0;
	}
}
if (!empty($handle_out))
    fclose($handle_out);
verbose($solr->getCollection() . ' - Queries end', $verbose);


?>