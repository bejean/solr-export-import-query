<?php
include ('helpers.inc.php');
include ('helpers-xml.inc.php');

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php --ini inifile --conf solr_dih_config_file [--verbose]');
    exit(-1);
}

function searchDuplicateDatasource($datasource, $array) {
    foreach ($array as $val) {
        if (($val['url'] === $datasource['url'] ) && ($val['type'] === $datasource['name'])) {
            return $val['name'];
        }
    }
    return null;
}

function searchEntityByDatasource($datasource, $array) {
    foreach ($array as $key => $entity) {
        if (key_exists('datasource', $entity))
            if (($entity['datasource'] === $datasource))
                return true;
        if (key_exists('entities', $entity))
            if (searchEntityByDatasource($datasource, $entity['entities']))
                return true;
    }
    return false;
}

function processEntities ($xml, $path, $depth, $config_verbose) {

    //$indent_str = str_repeat(' ', ($depth - 1) * 4);
    $entities = array();

    $nodes=$xml->xpath($path);
    if (count($nodes)==0)
        return $entities;

    foreach($nodes as $node) {
        $attributes = xmlAttributes2Array($node->attributes());
        $entity = array();
        $processor = $attributes['processor'] ?? 'SqlEntityProcessor';
        $entity['depth'] = $depth;
        $entity['name'] = $attributes['name'];
        $entity['processor'] = $processor;
        $entity['datasource'] = $attributes['datasource'];
        if (key_exists('transformer', $attributes)) {
            $entity['transformers'] = explode(',',$attributes['transformer']);
        }

        if ($config_verbose) {
            //$output .= "        driver  " . $node['driver'] . "\n";
            //$output .= "        url     " . $node['url'] . "\n";
        }

        $sub_entities = processEntities ($xml, $path . '[@name="' . $attributes['name'] . '"]/entity', $depth+1, $config_verbose);
        if (count($sub_entities)>0)
            $entity['entities'] = $sub_entities;

        $entities[] = $entity;

    }
    return $entities;
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

$output = array();

$xml_str = file_get_contents($config_file);
$xml_str = leading_tabs_to_spaces($xml_str);

$xml = xml_load_string($xml_str);
if ($xml===false)
    usage("unable to parse $config_file");

// Entities
$entities = processEntities ($xml, '/dataConfig/document/entity', 1, $output, $config_verbose);

// Datasources
$output['datasources'] = array();
$output['datasources_unused'] = array();
$nodes=$xml->xpath("/dataConfig/dataSource");
foreach($nodes as $node) {
    $attributes = xmlAttributes2Array($node->attributes());

    $datasource = array();
    $datasource['name'] = $attributes['name'];
    $datasource['type'] = $attributes['type'];
    if (key_exists('driver', $attributes))
        $datasource['driver'] = $attributes['driver'];
    if (key_exists('url', $attributes))
        $datasource['url'] = $attributes['url'];

    // doublon ???
    //$doublon = searchDuplicateDatasource($datasource, $output['datasources']);
    if (!empty($doublon)) {
        $datasource['duplicate'] = $doublon;
    }

    // utilisé ???
    if (!searchEntityByDatasource($attributes['name'], $entities))
        $output['datasources_unused'][$attributes['name']] = $datasource;
    else
        $output['datasources'][$attributes['name']] = $datasource;
}

// Processors
$output['processors'] = array();
//$output['transformers'] = array();
$transformers = '';
$nodes=$xml->xpath("//entity");
foreach($nodes as $node) {
    $attributes = xmlAttributes2Array($node->attributes());
    $processor = $attributes['processor'] ?? 'SqlEntityProcessor';
    $output['processors'][] = $processor;
    if (key_exists('transformer', $attributes)) {
        if (!empty($transformers))
            $transformers .= ',';
        $transformers .= $attributes['transformer'];
    }
}
$output['processors'] = array_values(array_unique($output['processors'],SORT_STRING));
$transformer = array_values(array_unique(array_map('trim',explode(',', $transformers))));
sort($transformer);
$output['transformers'] = $transformer;

$output['entities'] = $entities;

echo json_encode($output, JSON_PRETTY_PRINT);