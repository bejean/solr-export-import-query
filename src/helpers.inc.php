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

function insert_before_line_matching ($pattern, $str, $insert) {
    $ret='';
    $separator = "\r\n";
    $done=false;
    foreach(preg_split("/((\r?\n)|(\r\n?))/", $str) as $line) {
        if (!$done && preg_match($pattern, $line)) {
            $ret .= "<!-- upgrade insert start -->\n";
            foreach(preg_split("/((\r?\n)|(\r\n?))/", $insert) as $line_insert) {
                $ret .= rtrim($line_insert) . "\n";
            }
            $ret .= "<!-- upgrade insert end -->\n";
            $done=true;
        }
        $ret .= rtrim($line) . "\n";
    }
    return $ret;
}

function startsWith( $haystack, $needle ) {
    $length = strlen( $needle );
    return substr( $haystack, 0, $length ) === $needle;
}
