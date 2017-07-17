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

function getParam($name, $params, $collection, $default) {
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


?>