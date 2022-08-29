<?php
include ('helpers.inc.php');
include ('helpers-xml.inc.php');

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php --ini inifile --conf solr_config_directory [--clean] [--upgrade] [--classic] [--no-flag]');
    exit(-1);
}

function unused_types($schema, $solrconfig) {
    $result = $schema->xpath('//fieldType');
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


function xml_upgrade_schema($params, SimpleXMLElement $schema, SimpleXMLElement $xml_solrconfig, $config_classic, $is_first_pass, $config_flag_only) {
    // schema version
    $target_schema_version = getParam('target_schema_version', $params, 'general', '1.6');
    $schema['version'] = $target_schema_version;

    if ($config_classic) {
        $results=$schema->xpath('//similarity');
        if (count($results)==0) {
            // <similarity class="org.apache.lucene.search.similarities.ClassicSimilarity" />
            $similarity = $schema->addChild('similarity', '');
            $similarity->addAttribute("class", "org.apache.lucene.search.similarities.ClassicSimilarity");
        }
    }

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
        $nodes_WDG=$node->xpath("analyzer/filter[@class='solr.WordDelimiterGraphFilterFactory']");
        $nodes_SG=$node->xpath("analyzer/filter[@class='solr.SynonymGraphFilterFactory']");
        if (count($nodes_WDG)+count($nodes_SG)>0) {
            if ($node->count()==1){
                // duplicate single analyzer in two analyzers for index and query
                $dom_node = dom_import_simplexml($node);
                $dom_analyzer = dom_import_simplexml($node->analyzer);
                $dom_node->appendChild($dom_analyzer->cloneNode(true));

                $analyzers=$node->xpath("analyzer");
                $analyzers[0]['type']='index';
                $analyzers[1]['type']='query';
            }
            $index_analyzer=$node->xpath("analyzer[@type='index']");
            if ($index_analyzer) {
                $flatten_filter=$index_analyzer[0]->xpath("filter[@class='solr.FlattenGraphFilterFactory']");
                if (!$flatten_filter) {
                    // add FlattenGraphFilterFactory filter to index analyzer
                    $flatten_filter = $index_analyzer[0]->addChild('filter', '');
                    $flatten_filter->addAttribute("class", "solr.FlattenGraphFilterFactory");
                }
            }
        }
    }

    // replace int and tint by pint
    $arr=array('int', 'long', 'float', 'double', 'date');
    foreach($arr as $t) {
        xml_remove_nodes($schema,"//fieldType[@name='t" . $t . "']", $config_flag_only, 'deprecated');
        $nodes = $schema->xpath("//field[@type='t" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }
        $nodes = $schema->xpath("//dynamicField[@type='t" . $t . "']");
        foreach ($nodes as $node) {
            $node['type'] = 'p' . $t;
        }

        xml_remove_nodes($schema,"//fieldType[@name='" . $t . "']", $config_flag_only, 'deprecated');
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
    if ($is_first_pass) {
        $arr = array('ignored', 'random', 'binary', 'boolean', 'string', 'pint', 'plong', 'pfloat', 'pdouble', 'pdate');
        foreach ($arr as $t) {
            xml_remove_nodes($schema, "//fieldType[@name='" . $t . "']", $config_flag_only);
        }
    }

    $xml_str = $schema->asXML();

    // insert
    if ($is_first_pass) {
        if (!empty($params)) {
            $inserts = explode(',', getParam('insert', $params, 'schema', ''));
            foreach ($inserts as $insert) {
                $file = getParam('file', $params, $insert, '');
                $content = file_get_contents(dirname(__FILE__) . '/' . $file);
                $where = getParam('insert_before', $params, $insert, '');
                $xml_str = insert_before_line_matching($where, $xml_str, $content);
            }
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

function isTargetVersion($params, $xml_solrconfig) {
    // luceneMatchVersion
    $target_lucene_version = getParam('target_lucene_version', $params, 'general', '');
    $nodes=$xml_solrconfig->xpath('//luceneMatchVersion');
    return ($nodes[0][0]==$target_lucene_version);
}

function xml_upgrade_config($params, $xml_solrconfig, $config_classic, $is_first_pass, $config_flag_only) {
    // luceneMatchVersion
    $target_lucene_version = getParam('target_lucene_version', $params, 'general', '');
    $nodes=$xml_solrconfig->xpath('//luceneMatchVersion');
    $nodes[0][0]=$target_lucene_version;

    // Add <config><schemaFactory class="ClassicIndexSchemaFactory"/>
    $schemaFactory=$xml_solrconfig->xpath("//schemaFactory");
    if (count($schemaFactory)==0) {
        $schemaFactory = $xml_solrconfig->addChild('schemaFactory', '');
        $schemaFactory->addAttribute("class", "ClassicIndexSchemaFactory");
    }

    // solr.StandardRequestHandler deprecated
    $results=$xml_solrconfig->xpath("//requestHandler[@class='solr.StandardRequestHandler']");
    foreach($results as $node) {
        $node['class'] = 'solr.SearchHandler';
    }

    // SearchHandler deduplicate
    $results=$xml_solrconfig->xpath("//requestHandler[@class='solr.SearchHandler']");
    foreach($results as $node) {
        $name = xml_attribute($node, 'name');
        if (!startsWith($name, '/')) {
            $search = '/' . $name;
            $rh=$xml_solrconfig->xpath("//requestHandler[@class='solr.SearchHandler'][@name='" . $search . "']");
            if (count($rh)==1) {
                xml_remove_nodes($xml_solrconfig,"//requestHandler[@class='solr.SearchHandler'][@name='" . $name . "']", $config_flag_only);
            }
            if (count($rh)==0) {
                $node['name'] = '/' . $name;
            }
        }
    }

    // SearchHandler remove q.op if both q.op and mm exists, add sow=true
    $results=$xml_solrconfig->xpath("//requestHandler[@class='solr.SearchHandler']/lst[@name='defaults']");
    foreach($results as $node) {
        $parents = $node->xpath("..");
        $name = xml_attribute($parents[0], 'name');
        $qop=$node->xpath("str[@name='q.op']");
        $mm=$node->xpath("str[@name='mm']");
        if (count($qop)==1 && count($mm)==1) {
            xml_remove_nodes($xml_solrconfig,"//requestHandler[@class='solr.SearchHandler'][@name='" . $name . "']/lst[@name='defaults']/str[@name='q.op']", $config_flag_only);
        }
        $sow=$node->xpath("str[@name='sow']");
        if (count($sow)==0) {
            $sow = $node->addChild('str', 'true');
            $sow->addAttribute("name", "sow");
        }
        /*
        $q=$node->xpath("lst/str[@name='q']");
        if (count($q)==1) {
           if (empty((string) $q[0][0]))
               xml_remove_nodes($xml_solrconfig,"//requestHandler[@class='solr.SearchHandler'][@name='" . $name . "']/lst/str[@name='q']");
        }
        */
    }

    // temporary deprecated clustering lib
    $results=$xml_solrconfig->xpath('//lib');
    foreach($results as $node) {
        $dir = xml_attribute($node, 'dir');
        $regex = xml_attribute($node, 'regex');
        if (str_contains($dir, 'clustering') || str_contains($regex, 'clustering')) {
            xml_remove_node($node, true, 'temporary_deprecated');
        }
    }

    // deprecate <jmx>
    xml_remove_nodes($xml_solrconfig,"//jmx", $config_flag_only, 'deprecated');

    // deprecate <checkIntegrityAtMerge>
    xml_remove_nodes($xml_solrconfig,"//checkIntegrityAtMerge", $config_flag_only, 'deprecated');

    // remove implicite handlers
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/json']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/csv']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update/extract']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/analysis/field']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/analysis/document']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/debug/dump']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/admin/']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/update']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//requestHandler[@name='/replication']", $config_flag_only);
    xml_remove_nodes($xml_solrconfig,"//admin", $config_flag_only);

    // cache fieldValueCache filterCache documentCache queryResultCache remove class attribute
    xml_remove_nodes_attribute($xml_solrconfig, "//cache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//fieldValueCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//filterCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//documentCache", "class");
    xml_remove_nodes_attribute($xml_solrconfig, "//queryResultCache", "class");

    $xml_str = $xml_solrconfig->asXML();

    // insert
    if ($is_first_pass) {
        if (!empty($params)) {
            $inserts = explode(',', getParam('insert', $params, 'solrconfig', ''));
            foreach ($inserts as $insert) {
                $file = getParam('file', $params, $insert, '');
                $content = file_get_contents(dirname(__FILE__) . '/' . $file);
                $where = getParam('insert_before', $params, $insert, '');
                $xml_str = insert_before_line_matching($where, $xml_str, $content);
            }
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

function xml_clean_schema(SimpleXMLElement $schema, SimpleXMLElement $solrconfig, $config_flag_only)
{
    // remove unused type
    $arr_unused_field_type = unused_types($schema, $solrconfig);
    foreach($arr_unused_field_type as $name) {
        xml_remove_nodes($schema, "//fieldType[@name='" . $name . "']", $config_flag_only);
    }
    return $schema;
}

$options = getopt("", array('conf:', 'ini:', 'ext:', 'clean', 'upgrade', 'classic', 'nocomment', 'no-flag'));

// ini file
$param_file = isset($options['ini']) ? $options['ini'] : '';
if (empty($param_file)) usage("Missing --ini parameter");
if (!file_exists(dirname(__FILE__) . '/' . $param_file)) usage('ini file not found');
$params = parse_ini_file(dirname(__FILE__) . '/' . $param_file, true);

$config_upgrade = isset($options['upgrade']);
$config_clean = isset($options['clean']);
$config_classic = isset($options['classic']);
$config_nocomment = isset($options['nocomment']);
$config_flag_only = !isset($options['no-flag']);

$config_dir = $options['conf'] ?? '';
if (empty($config_dir)) usage("Missing --conf parameter");

if (!file_exists($config_dir))
    usage("$config_dir doesn't exist");

if (!is_dir($config_dir))
    usage("$config_dir is not a directory");

$source_ext = $options['ext'] ?? getParam('default_source_ext', $params, 'general', '');
if (empty($source_ext)) usage("Missing --ext parameter");

print ("=======================================\n");
print ("Input directory\n");
print ("    $config_dir\n");

print ("Input files\n");

$schema_file = 'schema.' . $source_ext;
print ("    $schema_file\n");
$schema_file = $config_dir . '/' . $schema_file;
if (!file_exists($schema_file))
    usage("$schema_file doesn't exist");

$solrconfig_file = 'solrconfig.' . $source_ext;
print ("    $solrconfig_file\n");
$solrconfig_file = $config_dir . '/' . $solrconfig_file;
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
if ($config_nocomment)
    $xml_str = remove_html_comments ($xml_str);
$xml = xml_load_string($xml_str);
if ($xml===false)
    usage("unable to parse $schema_file");

$xmlDocument = new DOMDocument('1.0');
$xmlDocument->preserveWhiteSpace = false;
$xmlDocument->formatOutput = true;
$xmlDocument->loadXML(formatXmlString($xml_str));

// save pretty version of input schema file
xmlstr_save($xmlDocument->saveXML(),$config_dir . '/schema.' . $source_ext . '.pretty', false);

$xml_config_str = file_get_contents($solrconfig_file);
$xml_config_str = leading_tabs_to_spaces($xml_config_str);
if ($config_nocomment)
    $xml_config_str = remove_html_comments ($xml_config_str);
$xml_solrconfig = xml_load_string($xml_config_str);

if ($xml_solrconfig===false)
    usage("unable to parse $solrconfig_file");

$xmlDocument = new DOMDocument('1.0');
$xmlDocument->preserveWhiteSpace = false;
$xmlDocument->formatOutput = true;
$xmlDocument->loadXML(formatXmlString($xml_config_str));

// save pretty version of input solrconfig file
xmlstr_save($xmlDocument->saveXML(),$config_dir . '/solrconfig.' . $source_ext . '.pretty', false);

$is_first_pass = !isTargetVersion($params, $xml_solrconfig);

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
}

$unique_key=$xml->xpath('//uniqueKey');

$arr_unused_field_type = unused_types($xml, $xml_solrconfig);

echo "---------------------------------------\n";
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

echo "fl_force_ignore=" . implode(',' ,$arr_copy_field). "\n";
echo "fl=".implode(',' ,array_diff(array_merge($arr_field_stored,$arr_field_not_stored),$arr_copy_field)) . "\n";

echo "unique_key=" . $unique_key[0] . "\n";

if ($config_upgrade) {
    $xml = xml_upgrade_schema($params, $xml, $xml_solrconfig, $config_classic, $is_first_pass, $config_flag_only);
    $xml_solrconfig = xml_upgrade_config($params, $xml_solrconfig, $config_classic, $is_first_pass, $config_flag_only);
}

if ($config_clean) {
    $xml = xml_clean_schema($xml, $xml_solrconfig, $config_flag_only);
}

if ($config_clean || $config_upgrade) {
    xmlstr_save($xml->asXML(),$config_dir . '/schema.xml');
    xmlstr_save($xml_solrconfig->asXML(),$config_dir . '/solrconfig.xml');
}