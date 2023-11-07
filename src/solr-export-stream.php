<?php
include('helpers.inc.php');
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

// https://stackoverflow.com/questions/52333353/solr-streaming-expression-using-php-curl
// https://stackoverflow.com/questions/1342583/manipulate-a-string-that-is-30-million-characters-long/1342760#1342760


$url = $solr_url . $collection . '/stream';

$data['expr'] = "search($collection,
                               q='$q',
                               fl='id,*',
                               sort='id asc',
                               qt='/export')";

// Register the wrapper
stream_wrapper_register("test", "MyStream")
or die("Failed to register protocol");

// Open the "file"
$fp = fopen("test://MyTestVariableInMemory", "r+");

// Configuration of curl
$ch = curl_init();
if ($ch) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 256);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FILE, $fp);    // Data will be sent to our stream ;-)
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    curl_exec($ch);

    curl_close($ch);
}

fclose($fp);


class MyStream {
    protected $buffer;

    function stream_open($path, $mode, $options, &$opened_path) {
        // Has to be declared, it seems...
        return true;
    }

    public function stream_write($data) {
        // Extract the lines ; on y tests, data was 8192 bytes long ; never more
        $lines = explode("\n", $data);

        // The buffer contains the end of the last line from previous time
        // => Is goes at the beginning of the first line we are getting this time
        $lines[0] = $this->buffer . $lines[0];

        // And the last line os only partial
        // => save it for next time, and remove it from the list this time
        $nb_lines = count($lines);
        $this->buffer = $lines[$nb_lines-1];
        unset($lines[$nb_lines-1]);

        // Here, do your work with the lines you have in the buffer
        var_dump($lines);
        echo '<hr />';

        return strlen($data);
    }
}




?>