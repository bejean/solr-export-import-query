<?php

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php --conf solr_config_directory [--clean] [--upgrade]');
    exit(-1);
}

function leading_tabs_to_spaces ($str) {
    // replace leading tabs by spaces
    $separator = "\r\n";
    $new_str='';
    foreach(preg_split("/((\r?\n)|(\r\n?))/", $str) as $line) {
        if (trim($line)=='') {
            $new_str .= "\n";
        }
        else {
            $arr = str_split($line);
            $lead = true;
            $new_line = '';
            foreach ($arr as $c) {
                if ($lead) {
                    if ($c == ' ') {
                        $new_line .= $c;
                        continue;
                    }
                    if ($c == "\t") {
                        $new_line .= '    ';
                        continue;
                    }
                    $new_line .= $c;
                    $lead = false;
                } else
                    $new_line .= $c;
            }
            $new_str .= rtrim($new_line) . "\n";
        }
    }
    return $new_str;
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

function xml_attribute($object, $attribute) {
    if(isset($object[$attribute]))
        return (string) $object[$attribute];
}

function xml_move_node(SimpleXMLElement $to, SimpleXMLElement $from) {
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

function xml_remove_nodes($xml, $xpath, $flag_only=false) {
    $nodes=$xml->xpath($xpath);
    foreach($nodes as $node) {
        if ($flag_only) {
            $node['name']='remove_' . $node['name'];
        } else {
            $dom = dom_import_simplexml($node);
            $dom->parentNode->removeChild($dom);
        }
    }
}

function xml_remove_nodes_attribute($xml, $xpath, $name) {
    $nodes=$xml->xpath($xpath);
    foreach($nodes as $node) {
        unset($node[$name]);
    }
}

function xml_upgrade_schema(SimpleXMLElement $schema, SimpleXMLElement $xml_solrconfig) {
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
        $nodes = $schema->xpath("//field[@type='t" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
            $i=1;
        }
        $nodes = $schema->xpath("//field[@type='" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
            $i=1;
        }
    }

    // format
    $xmlDocument = new DOMDocument('1.0');
    $xmlDocument->preserveWhiteSpace = false;
    $xmlDocument->formatOutput = true;

    $xml_str = $schema->asXML();

    // clean
    $xml_str = str_replace('<fields>', '', $xml_str);
    $xml_str = str_replace('</fields>', '', $xml_str);
    $xml_str = str_replace('<types>', '', $xml_str);
    $xml_str = str_replace('</types>', '', $xml_str);

    $xmlDocument->loadXML($xml_str);
    return simplexml_load_string($xmlDocument->saveXML());
}

function xml_upgrade_config($xml_solrconfig) {
    // luceneMatchVersion
    $nodes=$xml_solrconfig->xpath('//luceneMatchVersion');
    $nodes[0][0]='8.11.1';

    // Add <config><schemaFactory class="ClassicIndexSchemaFactory"/>
    $schemaFactory=$xml_solrconfig->xpath("//schemaFactory");
    if (count($schemaFactory)==0) {
        $schemaFactory = $xml_solrconfig->addChild('schemaFactory', '');
        $schemaFactory->addAttribute("class", "ClassicIndexSchemaFactory");
    }

    // remove implicite handlers
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/json']", true);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/csv']", true);
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

    /*
    <initParams path="/update/**,/query,/select,/spell">
        <lst name="defaults">
            <str name="df">_text_</str>
        </lst>
    </initParams>
    */

    return $xml_solrconfig;
}

function xml_clean_schema(SimpleXMLElement $schema, SimpleXMLElement $solrconfig)
{
    // remove unused type
    $arr_unused_field_type = unused_types($schema, $solrconfig);

    $result=$schema->xpath('//fieldType');
    foreach($result as $node) {
        $name = xml_attribute($node, 'name');
        if (in_array($name, $arr_unused_field_type))
            $node['name']='remove_' . $name;
    }
    return $schema;
}

$options = getopt("", array('conf:', 'clean', 'upgrade'));

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
$xml_str = str_replace('<fieldtype', '<fieldType', $xml_str);
$xml_str = str_replace('</fieldtype', '</fieldType', $xml_str);
$xml = simplexml_load_string($xml_str);
if ($xml===false)
    usage("unable to parse $schema_file");

$xml_config_str = file_get_contents($solrconfig_file);
$xml_config_str = leading_tabs_to_spaces($xml_config_str);
$xml_solrconfig = simplexml_load_string($xml_config_str);
if ($xml_solrconfig===false)
    usage("unable to parse $solrconfig_file");

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

if ($config_clean) {
    $xml = xml_clean_schema($xml, $xml_solrconfig);
}

if ($config_upgrade) {
    $xml = xml_upgrade_schema($xml, $xml_solrconfig);
    $xml_solrconfig = xml_upgrade_config($xml_solrconfig);
}

if ($config_clean || $config_upgrade) {
    $xml->asXML($config_dir . '/schema-new.xml');
    $xml_solrconfig->asXML($config_dir . '/solrconfig-new.xml');
}