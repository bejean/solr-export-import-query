<?php

function usage($message = '') {
    if (!empty($message))
        print ('Error : ' . $message . "\n");
    print ('Usage : php solr-config-analysis.php -c solr_config_directory');
    exit(-1);
}

function xml_attribute($object, $attribute)
{
    if(isset($object[$attribute]))
        return (string) $object[$attribute];
}

function xml_move_node(SimpleXMLElement $to, SimpleXMLElement $from) {
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}


function xml_upgrade_schema(SimpleXMLElement $schema)
{
    // schema version
    $schema['version'] = '1.6';

    // remove deprecated enablePositionIncrements filter attribute
    $nodes=$schema->xpath('//filter[@enablePositionIncrements]');
    foreach($nodes as $node) {
        unset($node['enablePositionIncrements']);
    }

    // remove standard filter
    $nodes=$schema->xpath("//filter[@class='solr.StandardFilterFactory']");
    foreach($nodes as $node) {
        $dom=dom_import_simplexml($node);
        $dom->parentNode->removeChild($dom);
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
    $xml_str = str_replace('<fieldtype', '<fieldType', $xml_str);
    $xml_str = str_replace('</fieldtype', '</fieldType', $xml_str);
    $xmlDocument->loadXML($xml_str);
    $xml = simplexml_load_string($xmlDocument->saveXML());

    return $xml;
}

$options = getopt("c:u");

$config_upgrade = isset($options['u']);

$config_dir = $options['c'] ?? '';
if (empty($config_dir)) usage("Missing -c parameter");

if (!file_exists($config_dir))
    usage("$config_dir doesn't exist");

if (!is_dir($config_dir))
    usage("$config_dir is not a directory");

$schema_file = $config_dir . '/schema.xml';
if (!file_exists($schema_file))
    usage("$schema_file doesn't exist");

$xml = simplexml_load_file($schema_file);
if ($xml===false)
    usage("unable to parse $schema_file");

// copyField
$result=$xml->xpath('//copyField');
$arr_copy_field = array();
foreach($result as $node) {
    $arr_copy_field[]=xml_attribute($node,'dest');
    //echo '/a/b/c: ' . xml_attribute($node,'dest') . "\n";
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
    $new_xml = xml_upgrade_schema($xml);
    $new_xml->asXML($config_dir . '/schema-new.xml');
}