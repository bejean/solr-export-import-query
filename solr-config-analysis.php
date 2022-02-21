<?php
include ('helpers.inc.php');
include ('helpers-xml.inc.php');

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php --ini inifile --conf solr_config_directory [--clean] [--upgrade]');
    exit(-1);
}

function unused_types($schema, $solrconfig) {
    $result = $schema->xpath('//fieldType');
    $arr_unused_field_type=array();
    $arr_ignore=array('text_general','string','strings','boolean','booleans','pint','pfloat','plong','pdouble','pints','pfloats','plongs','pdoubles','random','ignored','pdate','pdates','binary','rank');$arr_unused_field_type = array();
    foreach($result as $node) {
        $name = xml_attribute($node,'name');
        $used=$schema->xpath("//field[@type='" . $name . "']");
        $used_dynamic=$schema->xpath("//dynamicField[@type='" . $name . "']");
        $used_spellcheck = false;
        $e = $solrconfig->xpath("//str[@name='queryAnalyzerFieldType']");
        foreach($e as $e1)
            if ($e1[0]==$name)
                $used_spellcheck = true;
        if (count($used)==0 && count($used_dynamic)==0 && !$used_spellcheck)
            $arr_unused_field_type[]=$name;
    }
    return array_diff($arr_unused_field_type, $arr_ignore);
}


function xml_upgrade_schema($params, SimpleXMLElement $schema, SimpleXMLElement $xml_solrconfig) {
    // schema version
    $schema['version'] = '1.6';

    // remove deprecated enablePositionIncrements filter attribute
    xml_remove_nodes_attribute($schema,"//filter[@enablePositionIncrements]", 'enablePositionIncrements');

    // remove standard filter
    xml_remove_nodes($schema,"//filter[@class='solr.StandardFilterFactory']");

    // SynonymFilterFactory -> SynonymGraphFilterFactory
    $nodes=$schema->xpath("//filter[@class='solr.SynonymFilterFactory']");
    foreach($nodes as $node) {
        $node['class']='solr.SynonymGraphFilterFactory';
    }

    // WordDelimiterFilterFactory -> WordDelimiterGraphFilterFactory
    $nodes=$schema->xpath("//filter[@class='solr.WordDelimiterFilterFactory']");
    foreach($nodes as $node) {
        $node['class']='solr.WordDelimiterGraphFilterFactory';
    }

    // FlattenGraphFilterFactory
    $results=$schema->xpath('//fieldType');
    foreach($results as $node) {
        if ($node->count()==1){
            $nodes_WDG=$node->xpath("analyzer/filter[@class='solr.WordDelimiterGraphFilterFactory']");
            $nodes_SG=$node->xpath("analyzer/filter[@class='solr.SynonymGraphFilterFactory']");
            if (count($nodes_WDG)+count($nodes_SG)>0) {
                // duplicate single analyzer in two analyzers for index and query
                $dom_node = dom_import_simplexml($node);
                $dom_analyzer = dom_import_simplexml($node->analyzer);
                $dom_node->appendChild($dom_analyzer->cloneNode(true));

                $analyzers=$node->xpath("analyzer");
                $analyzers[0]['type']='index';
                $analyzers[1]['type']='query';

                // add FlattenGraphFilterFactory filter to index analyzer
                $analyzers=$node->xpath("analyzer[@type='index']");
                $filter = $analyzers[0]->addChild('filter', '');
                $filter->addAttribute("class", "solr.FlattenGraphFilterFactory");
            }
        }
    }

    // replace int and tint by pint
    $arr=array('int', 'long', 'float', 'double', 'date');
    foreach($arr as $t) {
        xml_remove_nodes($schema,"//fieldType[@name='t" . $t . "']", true, 'deprecated');
        $nodes = $schema->xpath("//field[@type='t" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }
        $nodes = $schema->xpath("//dynamicField[@type='t" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }

        xml_remove_nodes($schema,"//fieldType[@name='" . $t . "']", true, 'deprecated');
        $nodes = $schema->xpath("//field[@type='" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }
        $nodes = $schema->xpath("//dynamicField[@type='" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }
    }

    // remove types inserted for compatibility
    $arr=array('ignored', 'random', 'binary', 'boolean', 'string', 'pint', 'plong', 'pfloat', 'pdouble', 'pdate');
    foreach($arr as $t) {
        xml_remove_nodes($schema,"//fieldType[@name='" . $t . "']", true, 'remove');
    }

    $xml_str = $schema->asXML();

    // insert
    if (!empty($params)) {
        $inserts = explode(',', getParam('insert', $params, 'schema', ''));
        foreach ($inserts as $insert) {
            $file = getParam('file', $params, $insert, '');
            $content = file_get_contents(dirname(__FILE__) . '/' . $file);
            $where = getParam('insert_before', $params, $insert, '');
            $xml_str = insert_before_line_matching ($where, $xml_str, $content);
        }
    }

    $xml_str = formatXmlString($xml_str);

    // format
    $xmlDocument = new DOMDocument('1.0');
    $xmlDocument->preserveWhiteSpace = false;
    $xmlDocument->formatOutput = true;
    $xmlDocument->loadXML($xml_str);
    return xml_load_string($xmlDocument->saveXML());
}

function xml_upgrade_config($params, $xml_solrconfig) {
    $xml_str = $xml_solrconfig->asXML();

    // luceneMatchVersion
    $nodes=$xml_solrconfig->xpath('//luceneMatchVersion');
    $nodes[0][0]='8.11.1';

    // Add <config><schemaFactory class="ClassicIndexSchemaFactory"/>
    $schemaFactory=$xml_solrconfig->xpath("//schemaFactory");
    if (count($schemaFactory)==0) {
        $schemaFactory = $xml_solrconfig->addChild('schemaFactory', '');
        $schemaFactory->addAttribute("class", "ClassicIndexSchemaFactory");
    }

    $xml_str = $xml_solrconfig->asXML();

    // deprecate <checkIntegrityAtMerge>
    xml_remove_nodes($xml_solrconfig,"//checkIntegrityAtMerge", true, 'deprecated');

    // remove implicite handlers
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/json']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/csv']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/extract']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/analysis/field']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/analysis/document']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/debug/dump']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/admin/']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/replication']", true);
    xml_remove_nodes($xml_solrconfig,"//admin", true);

    // cache fieldValueCache filterCache documentCache queryResultCache remove class attribute
    xml_remove_nodes_attribute($xml_solrconfig, "//cache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//fieldValueCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//filterCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//documentCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//queryResultCache", "class");

    $xml_str = $xml_solrconfig->asXML();

    // insert
    if (!empty($params)) {
        $inserts = explode(',', getParam('insert', $params, 'solrconfig', ''));
        foreach ($inserts as $insert) {
            $file = getParam('file', $params, $insert, '');
            $content = file_get_contents(dirname(__FILE__) . '/' . $file);
            $where = getParam('insert_before', $params, $insert, '');
            $xml_str = insert_before_line_matching ($where, $xml_str, $content);
        }
    }

    $xml_str = formatXmlString($xml_str);

    // format
    $xmlDocument = new DOMDocument('1.0');
    $xmlDocument->preserveWhiteSpace = false;
    $xmlDocument->formatOutput = true;
    $xmlDocument->loadXML($xml_str);
    return xml_load_string($xmlDocument->saveXML());
}

function xml_clean_schema(SimpleXMLElement $schema, SimpleXMLElement $solrconfig)
{
    // remove unused type
    $arr_unused_field_type = unused_types($schema, $solrconfig);
    foreach($arr_unused_field_type as $name) {
        xml_remove_nodes($schema, "//fieldType[@name='" . $name . "']", true, 'remove');
    }
    return $schema;
}

$options = getopt("", array('conf:', 'ini:', 'clean', 'upgrade'));

// ini file
$param_file = isset($options['ini']) ? $options['ini'] : '';
if (!empty($param_file) && !file_exists(dirname(__FILE__) . '/' . $param_file)) usage('ini file not found');
if (!empty($param_file))
    $params = parse_ini_file(dirname(__FILE__) . '/' . $param_file, true);

$config_upgrade = isset($options['upgrade']);
$config_clean = isset($options['clean']);

$config_dir = $options['conf'] ?? '';
if (empty($config_dir)) usage("Missing -c parameter");

if (!file_exists($config_dir))
    usage("$config_dir doesn't exist");

if (!is_dir($config_dir))
    usage("$config_dir is not a directory");

$schema_file = $config_dir . '/schema.xml';
if (!file_exists($schema_file))
    usage("$schema_file doesn't exist");

$solrconfig_file = $config_dir . '/solrconfig.xml';
if (!file_exists($solrconfig_file))
    usage("$solrconfig_file doesn't exist");

$xml_str = file_get_contents($schema_file);
$xml_str = leading_tabs_to_spaces($xml_str);
// clean
$xml_str = str_replace('<fields>', '', $xml_str);
$xml_str = str_replace('</fields>', '', $xml_str);
$xml_str = str_replace('<types>', '', $xml_str);
$xml_str = str_replace('</types>', '', $xml_str);
$xml_str = str_replace('<fieldtype', '<fieldType', $xml_str);
$xml_str = str_replace('</fieldtype', '</fieldType', $xml_str);
$xml = xml_load_string($xml_str);
if ($xml===false)
    usage("unable to parse $schema_file");

$xmlDocument = new DOMDocument('1.0');
$xmlDocument->preserveWhiteSpace = false;
$xmlDocument->formatOutput = true;
$xmlDocument->loadXML(formatXmlString($xml_str));
xmlstr_save($xmlDocument->saveXML(),$config_dir . '/schema-original.xml');

$xml_config_str = file_get_contents($solrconfig_file);
$xml_config_str = leading_tabs_to_spaces($xml_config_str);
$xml_solrconfig = xml_load_string($xml_config_str);

if ($xml_solrconfig===false)
    usage("unable to parse $solrconfig_file");

$xmlDocument = new DOMDocument('1.0');
$xmlDocument->preserveWhiteSpace = false;
$xmlDocument->formatOutput = true;
$xmlDocument->loadXML(formatXmlString($xml_config_str));
xmlstr_save($xmlDocument->saveXML(),$config_dir . '/solrconfig-original.xml');

// copyField
$result=$xml->xpath('//copyField');
$arr_copy_field = array();
foreach($result as $node) {
    $arr_copy_field[]=xml_attribute($node,'dest');
}
$arr_copy_field=array_unique($arr_copy_field);

$result=$xml->xpath('//field');
$arr_field_stored = array();
$arr_field_stored_only = array();
$arr_field_docValues_only = array();
$arr_field_not_stored = array();
foreach($result as $node) {
    $name=xml_attribute($node,'name');

    if ($name=='title_exact') {
        $name=$name;
    }

    if (in_array($name , array('_version_', '_root_')))
        continue;
    $type=xml_attribute($node,'type');
    $type_def=$xml->xpath("//fieldType[@name='$type']");
    $stored_type=xml_attribute($type_def,'stored');
    if (empty($stored_type))
        $stored_type = 'true';
    $docValues_type=xml_attribute($type_def,'docValues');
    if (empty($docValues_type))
        $docValues_type = 'false';

    $stored_field=xml_attribute($node,'stored');
    if (empty($stored_field))
        $stored_field = $stored_type;
    $docValues_field=xml_attribute($node,'docValues');
    if (empty($docValues_field))
        $docValues_field = $docValues_type;

    if ($stored_field=='true' || $docValues_field=='true')
        $arr_field_stored[]=$name;
    else
        $arr_field_not_stored[]=$name;

    if ($stored_field=='true' && $docValues_field=='false')
        $arr_field_stored_only[]=$name;

    if ($stored_field=='false' && $docValues_field=='true')
        $arr_field_docValues_only[]=$name;

    //echo "Field : $name - Stored : $stored_field - docValues : $docValues_field\n";
}

$unique_key=$xml->xpath('//uniqueKey');

$arr_unused_field_type = unused_types($xml, $xml_solrconfig);

echo "=======================================\n";
echo $config_dir . "\n\n";
echo "uniqueKey : " . $unique_key[0] . "\n\n";
echo "fields : " . implode(', ' ,$arr_field_stored) . ', ' . implode(', ' ,$arr_field_not_stored) . "\n\n";
echo "stored || docValues : " . implode(', ' ,$arr_field_stored) . "\n\n";
echo "not stored && not docValues : " . implode(', ' ,$arr_field_not_stored) . "\n\n";
echo "copyField dest : " . implode(', ' ,$arr_copy_field) . "\n\n";
echo "WARNING - not stored & not docValues & not copyField dest: " . implode(', ' ,array_diff($arr_field_not_stored, $arr_copy_field)) . "\n\n";
echo "stored only: " . implode(', ' ,$arr_field_stored_only) . "\n\n";
echo "WARNING - docValues only: " . implode(', ' ,$arr_field_docValues_only) . "\n\n";
echo "WARNING - unused types : " . implode(', ' ,$arr_unused_field_type) . "\n\n";
echo "---------------------------------------\n";
if (count(array_diff($arr_field_not_stored, $arr_copy_field))!=0 || count($arr_field_docValues_only)!=0) {
    echo "fl_force_ignore=\n";
    echo "fl=".implode(',' ,$arr_field_stored) . ',' . implode(',' ,$arr_field_not_stored) . "\n";
} else {
    echo "fl_force_ignore=" . implode(',' ,$arr_copy_field). "\n";
    echo "fl=\n";
}
echo "unique_key=" . $unique_key[0] . "\n";

if ($config_upgrade) {
    $xml = xml_upgrade_schema($params, $xml, $xml_solrconfig);
    $xml_solrconfig = xml_upgrade_config($params, $xml_solrconfig);
}

if ($config_clean) {
    $xml = xml_clean_schema($xml, $xml_solrconfig);
}

if ($config_clean || $config_upgrade) {
    xmlstr_save($xml->asXML(),$config_dir . '/schema-new.xml');
    xmlstr_save($xml_solrconfig->asXML(),$config_dir . '/solrconfig-new.xml');
}