<?php
function error() {
	print ('Error !');
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
?>