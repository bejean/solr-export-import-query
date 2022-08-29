<?php
include ('helpers.inc.php');
include ('helpers-xml.inc.php');

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php --ini inifile --conf solr_dih_config_file [--verbose]');
    exit(-1);
}


function processEntities ($xml, $path, $depth, $output, $config_verbose) {

    $indent_str = str_repeat(' ', ($depth - 1) * 4);

    $nodes=$xml->xpath($path);
    if (count($nodes)==0)
        return $output;

    $output .= $indent_str . "--- Entities (depth = $depth) --- \n";
    foreach($nodes as $node) {
        $attributes = xmlAttributes2Array($node->attributes());
        $output .= $indent_str . "    name " . $attributes['name'] . "\n";
        $processor = $attributes['processor'] ?? 'SqlEntityProcessor';

        //$processor = $attributes['processor'];
        //if (empty($processor)) $processor = "SqlEntityProcessor.";
        $output .= $indent_str . "        processor       " . $processor . "\n";
        $output .= $indent_str . "        dataSource      " . $attributes['datasource'] . "\n";
        if (key_exists('transformer', $attributes)) {
            $output .= $indent_str . "        transformers    " . $attributes['transformer'] . "\n";
        }

        if ($config_verbose) {
            //$output .= "        driver  " . $node['driver'] . "\n";
            //$output .= "        url     " . $node['url'] . "\n";
        }
        $output= processEntities ($xml, $path . '/entity', $depth+1, $output, $config_verbose);
    }
    return $output;
}

$options = getopt("", array('conf:', 'ini:', 'verbose'));

// ini file
$param_file = $options['ini'] ?? '';
if (empty($param_file))
    usage("Missing --ini parameter");
if (!file_exists(dirname(__FILE__) . '/' . $param_file))
    usage('ini file not found');

$config_file = $options['conf'] ?? '';
if (empty($config_file))
    usage("Missing --conf parameter");

if (!file_exists($config_file))
    usage("$config_file doesn't exist");

$config_verbose = isset($options['verbose']);


$params = parse_ini_file(dirname(__FILE__) . '/' . $param_file, true);

$output = '';

$xml_str = file_get_contents($config_file);
$xml_str = leading_tabs_to_spaces($xml_str);

$xml = xml_load_string($xml_str);
if ($xml===false)
    usage("unable to parse $config_file");

$output .= "--- Datasources --- \n";
$nodes=$xml->xpath("/dataConfig/dataSource");
foreach($nodes as $node) {
    $attributes = xmlAttributes2Array($node->attributes());

    $output .= "    name " . $attributes['name'] . "\n";
    $output .= "        type    " . $attributes['type'] . "\n";
    if ($config_verbose) {
        if (key_exists('driver', $attributes))
            $output .= "        driver  " . $attributes['driver'] . "\n";
        if (key_exists('url', $attributes))
            $output .= "        url     " . $attributes['url'] . "\n";
    }
}

$output = processEntities ($xml, '/dataConfig/document/entity', 1, $output, $config_verbose);

echo $output;