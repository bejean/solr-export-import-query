<?php
include ('helpers.inc.php');
include ('solr.class.inc.php');

function usage() {
	print ('php solr-export.php -i inifile.ini -c collection [-p profil]');
	exit(-1);
}

date_default_timezone_set('Europe/Paris');

$options = getopt("i:p:c:");

// ini file
$param_file = isset($options['i']) ? $options['i'] : 'export.ini';
if (!file_exists($param_file)) usage();
$params = parse_ini_file($param_file, true);

// collection
$collection = isset($options['c']) ? $options['c'] : '';
if (empty($collection)) usage();

// profil
$profil = isset($options['p']) ? $options['p'] : '';
if (empty($profil)) $profil=$collection;


$solr_url = getParam('solr_url', $params, $profil, '');
if (empty($solr_url)) usage();

$verbose = (getParam('verbose', $params, $profil, '0') == '1');
$remove_child = (getParam('remove_child', $params, $profil, '0') == '1');

$fl = getParam('fl', $params, $profil, '*');
$q = getParam('q', $params, $profil, '*:*');
$extra_params = getParam('extra_params', $params, $profil, '');
$fq = getParam('fq', $params, $profil, '');
$start = getParam('start', $params, $profil, '0');
$rows = getParam('rows', $params, $profil, '10');
$max_rows = getParam('max_rows', $params, $profil, '0');
$json_max_size = getParam('json_max_size', $params, $profil, $max_rows);
$json_max_byte_size = getParam('json_max_byte_size', $params, $profil, '0');
$unique_key = getParam('unique_key', $params, $profil, '');
$extra_data = getParam('extra_data', $params, $profil, '');

$fl_force_ignore = explode(',', getParam('fl_force_ignore', $params, $profil, ''));
$fl_force_empty = explode(',', getParam('fl_force_empty', $params, $profil, ''));

$child_collection = getParam('child_collection', $params, $profil, '');
$child_join_field = getParam('child_join_field', $params, $profil, '');
$child_copy_field = getParam('child_copy_field', $params, $profil, '');
$child_uniq_field = getParam('child_uniq_field', $params, $profil, '');
$child_type_field = getParam('child_type_field', $params, $profil, '');
$child_only = (getParam('child_only', $params, $profil, '0') == '1');

$output_dir = getParam('output_dir', $params, $profil, './data_export');
if (empty($output_dir)) usage();
if (!file_exists($output_dir)) error("$output_dir Output directory doesn't exist !");
$output_dir.='/'.$collection;
if (!file_exists($output_dir)) mkdir($output_dir);

/* Query Parameters for search query */
$solr_params = array(
	'indent' => 'true',
	'q' => $q,
	'fq' => $fq,
	'fl' => $fl,
	'rows' => $rows
);

if (!empty($extra_params)) {
    $ps = explode('&', $extra_params);
    foreach ($ps as $p) {
        $ptokens = explode('=', $p);
        $solr_params[$ptokens[0]] = $ptokens[1];
    }
}

if ($start='*') {
	if (empty($unique_key)) usage();
	$solr_params['cursorMark'] = '*';
	$solr_params['sort'] = $unique_key . ' asc';
}
else
	$solr_params['start'] = $start;

/**********************************************************/
// Procedural execution steps - edit at your own risk
/**********************************************************/

/* Main application loop */
//$doc_cnt=$params['start'];
$doc_cnt=0;
$page_cnt=0;
$json_cnt=0;
$json_size=0;
$json_byte_size=0;
$doc_max_byte_size=0;
$doc_max_byte_size_id='';
$total_docs=0;
$start_time = time();
sleep(1);
$doc_read_cnt=0;
$last_exported_key = '';
$last_mn_count = -1;

$solr = new Solr($solr_url, $collection);
if (!$solr) error("Cannot create Solr object");

verbose($solr->getCollection() . ' - Starting export for collection : ' . $collection, $verbose);

verbose('Url : ' . $solr->getUrl($solr_params), $verbose);

//Initialize outup array
$output_doc_array = array();
$end_of_index = false;

$retry_count = 0;
while(!$end_of_index) {
	
	if (($data = $solr->post($solr_params)) == false) {
		if ($retry_count>=10) {
			verbose('Error reading data from Solr - Abort', $verbose);
			break;
		}
		$retry_count++;
		verbose('Error reading data from Solr - Retry (' . $retry_count . ')', $verbose);
		sleep(5);
		continue;
	}
	$retry_count = 0;
	
	$total_docs = $data['response']['numFound'];
	$page_cnt++;
    $doc_read_cnt += count($data['response']['docs']);

    if (count($data['response']['docs']) == 0)
        break;

    //verbose($solr->getCollection() . ' - Read data from Solr - page read count ' . $page_cnt . ' - doc read count ' . $doc_read_cnt , $verbose);

    //Loop through returned documents
	foreach($data['response']['docs'] as $doc) {
        //Increment document count
        $doc_cnt++;
        $json_size++;
        $doc_byte_size = getDocSize($doc);
        $json_byte_size += $doc_byte_size;

        if ($doc_byte_size > $doc_max_byte_size) {
            $doc_max_byte_size_id=$doc[$unique_key];
            $doc_max_byte_size = $doc_byte_size;
            verbose('Largest doc so far - Doc Id : ' . $doc[$unique_key] . ' - size : ' . $doc_byte_size, $verbose);
        }
        //See if max_rows has been reached
        if ($max_rows > 0)
            $end_of_index = ($doc_cnt >= $max_rows) || ($doc_cnt >= $total_docs);
        else
            $end_of_index = ($doc_cnt >= $total_docs);

        if (array_key_exists('childrens', $doc) === true)
            if ($remove_child)
                unset($doc['childrens']);
            else {
                $doc['_childDocuments_'] = $doc['childrens'];
                unset($doc['childrens']);
            }

        if (!empty($extra_data)) {
            if (str_contains($extra_data, '$RAMDOM$')) {
                $a = str_replace('$RAMDOM$', rand(1, 10), $extra_data);
            } else {
                $a = $extra_data;
            }
            $doc = array_merge($doc,json_decode($a, true));
        }
        recursive_unset($doc, '_version_');
        recursive_unset($doc, '_root_');
        recursive_unset($doc, '_nest_path_');
        recursive_unset($doc, '_nest_parent_');
        //if (array_key_exists('_version_', $doc) === true)
        //    unset($doc['_version_']);

        // Remove un-wanted fields
        if (count($fl_force_ignore) > 0)
            foreach ($fl_force_ignore as $field)
                recursive_unset($doc, $field);

        if (count($fl_force_empty) > 0)
            foreach ($fl_force_empty as $field)
                if (array_key_exists(trim($field), $doc) === true)
                    $doc[trim($field)] = '';

        // child ?
        if (!empty($child_collection)) {

            $k = array_keys($doc);

            $solr_child = new Solr($solr_url, $child_collection);
            if (!$solr_child) error("Cannot create Solr object");

            $fq_child = $child_join_field . ':' . $doc[$child_join_field];

            $solr_params_child = array(
                'indent' => 'true',
                'q' => '*:*',
                'fq' => $fq_child,
                'fl' => '*',
                'start' => 0,
                'rows' => 1000
            );

            if (!empty($child_uniq_field)) {
                $uniq_field = explode(',', $child_uniq_field);
                if (count($uniq_field) == 3) {
                    $doc[trim($uniq_field[0])] = $doc[trim($uniq_field[1])];
                }
            }

            if (!empty($child_type_field)) {
                $type_field = explode(',', $child_type_field);
                if (count($type_field) == 3) {
                    $doc[trim($type_field[0])] = trim($type_field[1]);
                }
            }

            if (($data_child = $solr_child->post($solr_params_child)) !== false) {

                $output_doc_child_array = array();
                $child_cnt = 0;

                //Loop through returned child documents
                foreach ($data_child['response']['docs'] as $doc_child) {

                    if (array_key_exists('_version_', $doc_child) === true)
                        unset($doc_child['_version_']);

                    // Remove parent's fields
                    foreach ($k as $v)
                        if (array_key_exists($v, $doc_child) === true)
                            unset($doc_child[$v]);

                    if (!empty($child_copy_field)) {
                        $copy_field = explode(',', $child_copy_field);
                        foreach ($copy_field as $f)
                            if (array_key_exists($f, $doc) === true && array_key_exists($f, $doc_child) === false)
                                $doc_child[$f] = $doc[$f];
                    }

                    if (!empty($child_uniq_field)) {
                        $uniq_field = explode(',', $child_uniq_field);
                        if (count($uniq_field) == 3) {
                            $doc_child[trim($uniq_field[0])] = $doc[trim($uniq_field[1])] . '-' . $doc_child[trim($uniq_field[2])];
                        }
                    }

                    if (!empty($child_type_field)) {
                        $type_field = explode(',', $child_type_field);
                        if (count($type_field) == 3) {
                            $doc_child[trim($type_field[0])] = trim($type_field[2]);
                        }
                    }

                    $output_doc_child_array[] = $doc_child;
                    $child_cnt++;
                }
                if (!empty($output_doc_child_array)) {
                    $doc ['_childDocuments_'] = $output_doc_child_array;
                } else {
                    if ($child_cnt == 0 && $child_only)
                        $doc = array();
                }
            }
        }

        // Append data to output array
        if (count($doc) > 0)
            $output_doc_array[] = $doc;

        if (($json_size >= $json_max_size) || ($json_max_byte_size>0 && ($json_byte_size >= $json_max_byte_size)) || $end_of_index) {
            $current_time = time();
            $elapsed_time = ($current_time - $start_time) / 60;
            $mn_count = floor($elapsed_time);
            $json_cnt++;
            $file_name = $output_dir . '/' . $collection . '-' . $json_cnt . '.json';
            if ($json_max_byte_size>0 && ($json_byte_size >= $json_max_byte_size))
                verbose($solr->getCollection() . ' - Write json file due to json max byte size reached - doc count : ' . $json_size, $verbose);
            verbose($solr->getCollection() . ' - Write json file [' . $file_name . '] - Doc count : ' . $doc_cnt. '/' . $total_docs . ' - Elapsed time : ' . round($elapsed_time, 2) . ' mn - Remaining time = ' . round((($elapsed_time/$doc_cnt) * ($total_docs - $doc_cnt)), 2) . ' mn', $verbose);
            $remaining_time = $elapsed_time / $doc_cnt * ($total_docs - $doc_cnt);
            if ($last_mn_count==-1 || $mn_count > $last_mn_count) {
                info('Elapsed time (mn) : ' . floor($elapsed_time));
                info('Processed docs : ' . $doc_cnt . ' / ' . $total_docs . ' (' . floor($doc_cnt / $elapsed_time) . ' doc/mn)');
                info('Remaining time : ' . floor($remaining_time) . ' mn / ' . ($remaining_time / 60) . ' h');
                $last_mn_count = $mn_count;
            }
            file_put_contents($file_name, json_encode($output_doc_array, JSON_PRETTY_PRINT));
            unset($output_doc_array);
            $output_doc_array = array();
            $json_size=0;
            $json_byte_size=0;
			$last_exported_key = $doc[$unique_key];
        }

		//Stop if we reached the end of the index
		if($end_of_index === true) {
			break;
		}
	} //foreach docs

	if($end_of_index === true) {
		break;
	}

	//Increment
	if ($start='*') {
		$solr_params['cursorMark'] = $data['nextCursorMark'];
	}
	else {
		$solr_params['start'] += $rows;
	}
    unset($data);
}
//verbose('end_of_index : ' . $end_of_index . ' - data : ' . $data, $verbose);

//print_r ($data); 

if ($json_size > 0) {
    $current_time = time();
    $elapsed_time = ($current_time - $start_time) / 60;
    $mn_count = floor($elapsed_time);
    $json_cnt++;
    $file_name = $output_dir . '/' . $collection . '-' . $json_cnt . '.json';
    if ($json_max_byte_size>0 && ($json_byte_size >= $json_max_byte_size))
        verbose($solr->getCollection() . ' - Write json file due to json max byte size reached - doc count : ' . $json_size, $verbose);
    verbose($solr->getCollection() . ' - Write json file [' . $file_name . '] - Doc count : ' . $doc_cnt. '/' . $total_docs . ' - Elapsed time : ' . round($elapsed_time, 2) . ' mn - Remaining time = ' . round((($elapsed_time/$doc_cnt) * ($total_docs - $doc_cnt)), 2) . ' mn', $verbose);

    info('Elapsed time (mn) : ' . floor($elapsed_time));
    info('Processed docs : ' . $doc_cnt . ' / ' . $total_docs . ' (' . floor($doc_cnt / $elapsed_time) . ' doc/mn)');
    info('Terminated !');

    file_put_contents($file_name, json_encode($output_doc_array, JSON_PRETTY_PRINT));
    unset($output_doc_array);
    $output_doc_array = array();
    $json_size=0;
    $json_byte_size=0;
	$last_exported_key = $doc[$unique_key];
}

verbose('Largest doc - Doc Id : ' . $doc_max_byte_size_id . ' - size : ' . $doc_max_byte_size, $verbose);
verbose('Last exported doc - Doc Id : ' . $last_exported_key, $verbose);
verbose($solr->getCollection() . ' - Export end', $verbose);

?>