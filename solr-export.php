<?php
include ('helpers.inc.php');
include ('solr.class.inc.php');

function usage() {
	print ('Missing or bad arguments !');
	exit(-1);
}

date_default_timezone_set('Europe/Paris');

$options = getopt("i:c:");

$param_file = isset($options['i']) ? $options['i'] : 'export.ini';
if (!file_exists($param_file)) usage();
$params = parse_ini_file($param_file, true);

$collection = isset($options['c']) ? $options['c'] : getParam('collection', $params, '', '');
if (empty($collection)) usage();

$solr_url = getParam('solr_url', $params, $collection, '');
if (empty($solr_url)) usage();

$verbose = (getParam('verbose', $params, $collection, '0') == '1');

$fl = getParam('fl', $params, $collection, '*');
$q = getParam('q', $params, $collection, '*:*');
$fq = getParam('fq', $params, $collection, '');
$start = getParam('start', $params, $collection, '0');
$rows = getParam('rows', $params, $collection, '10');
$max_rows = getParam('max_rows', $params, $collection, '0');
$unique_key = getParam('unique_key', $params, $collection, '');;

$fl_force_ignore = explode(',', getParam('fl_force_ignore', $params, $collection, ''));
$fl_force_empty = explode(',', getParam('fl_force_empty', $params, $collection, ''));

$output_dir = getParam('output_dir', $params, $collection, './data_export');
if (empty($output_dir)) usage();
if (!file_exists($output_dir)) error();


/* Query Parameters for search query */
$params = array(
	'indent' => 'true',
	'q' => $q,
	'fq' => $fq,
	'fl' => $fl,
	'rows' => $rows
);

if ($start='*') {
	if (empty($unique_key)) usage();
	$params['cursorMark'] = '*';
	$params['sort'] = $unique_key . ' asc';
}
else
	$params['start'] = $start;


/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */
//$doc_cnt=$params['start'];
$doc_cnt=0;
$page_cnt=0;
$total_docs=0;

$solr = new Solr($solr_url, $collection);
if (!$solr) error();

verbose('Starting export for collection : ' . $collection, $verbose);

while(($data = $solr->get($params)) !== false) {
	$end_of_index = false;
	$total_docs = $data['response']['numFound'];
	$page_cnt++;

	//Initialize outup array
	$output_doc_array = array();

	//Loop through returned documents
	foreach($data['response']['docs'] as $doc) {
		//Increment document count
		$doc_cnt++;

		//See if max_rows has been reached
		if ($max_rows > 0)
			$end_of_index = ($doc_cnt == $max_rows);
		else
			$end_of_index = ($doc_cnt == $total_docs);

		if(array_key_exists('_version_',$doc) === true)
					unset($doc['_version_']);

		//Remove un-wanted fields
		if (count($fl_force_ignore)>0)
			foreach($fl_force_ignore as $field)
				if(array_key_exists(trim($field),$doc) === true)
					unset($doc[trim($field)]);

		if (count($fl_force_empty)>0)
			foreach($fl_force_empty as $field)
				if(array_key_exists(trim($field),$doc) === true)
					$doc[trim($field)] = '';

		// Append data to output array
		$output_doc_array[] = $doc;

		//Stop if we reached the end of the index
		if($end_of_index === true) {
			break;
		}
	} //foreach docs

	$file_name = $output_dir . '/' . $collection .  '-' . $page_cnt . '.json';
	verbose('Write json file [' . $file_name . ']', $verbose);
	file_put_contents ( $file_name , json_encode($output_doc_array, JSON_PRETTY_PRINT) );
	if($end_of_index === true) {
		break;
	}

	//Increment
	if ($start='*') {
		$params['cursorMark'] = $data['nextCursorMark'];
	}
	else {
		$params['start'] += $rows;
	}
}

verbose('Export end', $verbose);

?>