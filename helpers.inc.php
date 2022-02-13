<?php

/*
 * helper functions
 */
function error($msg='') {
	print ('Error : ' . $msg . '!');
	exit(-1);
}

function verbose($msg, $verbose) {
	if ($verbose)
		print (date('G:i:s') . " - "  . $msg . "\n");
}

function trace($msg) {
    print (date('G:i:s') . " - "  . $msg . "\n");
}

function info($msg) {
    print (date('G:i:s') . " - "  . $msg . "\n");
}

function getParam($name, $params, $collection, $default = '') {
	$general_value = isset($params['general'][$name]) ? $params['general'][$name] : $default;
	if (!empty($collection))
		$value = isset($params[$collection][$name]) ? $params[$collection][$name] : $general_value;
	else
		$value = $general_value;
	return $value;
}

/*
 * alternative functions entry points
 * implement your own alternatives in custom-alternatives.class.inc.php
 */
function getAlternativeCollectionName($default_collection = '') {

	if (file_exists('custom-alternatives.class.inc.php')) {
		include_once ('custom-alternatives.class.inc.php');
		if (class_exists('CustomAlternatives')) {
			$custom = new CustomAlternatives();

			if (method_exists($custom, 'GetAlternativeCollectionName')) {
				return $custom->GetAlternativeCollectionName($default_collection);
			}
		}
	}
	return $default_collection;
}

function GetAlternativeQuery($query, $default_collection = '') {

	if (file_exists('custom-alternatives.class.inc.php')) {
		include_once ('custom-alternatives.class.inc.php');
		if (class_exists('CustomAlternatives')) {
			$custom = new CustomAlternatives();

			if (method_exists($custom,'GetAlternativeQuery')) {
				return CustomAlternatives::GetAlternativeQuery($query, $default_collection);
			}
		}
	}
	return $query;
}

function getDocSize($doc) {
    $serializedDoc = serialize($doc);
    if (function_exists('mb_strlen')) {
        $size = mb_strlen($serializedDoc, '8bit');
    } else {
        $size = strlen($serializedDoc);
    }
    return $size;
}

function recursive_unset(&$array, $unwanted_key) {
    $unwanted_key = trim($unwanted_key);
    if (array_key_exists($unwanted_key, $array) === true)
        unset($array[$unwanted_key]);

    foreach ($array as $key => &$value) {
        if (($key=='_childDocuments_' || is_numeric($key)) && is_array($value)) {
             recursive_unset($value, $unwanted_key);
        }
    }
}

?>