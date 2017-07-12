<?php

/*
 * Sample alternatives
 */


/*

Samples - implement your own alternative methods and uncomment

*/

class CustomAlternatives
{

	function GetAlternativeCollectionName($default_collection) {
		global $params;

		$alternative_collection = (getParam('custom_alternative_collection', $params, $default_collection, '0')=='1');
		if ($alternative_collection) {

			$alternative_collection_method = getParam('custom_alternative_collection_method', $params, $default_collection, '');

			// if we need to randomly update sharded collections with shards names like foo-0, ..., foo-99
			if ($alternative_collection_method=='one_to_many') {
				$max_collection_indice = getParam('custom_max_collection_indice', $params, $default_collection, '0');
				return substr($default_collection, 0, -1) . rand(0, $max_collection_indice);
			}

			// if we need to use only one collection shard but our solr query logs contains is referring various shards
			if ($alternative_collection_method=='many_to_one') {
				return preg_replace ( '_shard[0-9]{1,2}' , '0', $default_collection );
			}
		}
		return $default_collection;
	}

	function GetAlternativeQuery($query, $default_collection)
	{
		global $params;

		// if we need to use only one collection shard but our solr query logs contains is referring various shards
		$alternative_query = (getParam('custom_alternative_query', $params, $default_collection, '0') == '1');
		if ($alternative_query) {
			return preg_replace ( '_shard[0-9]{1,2}' , '0', $query );
		}
		return $query;
	}
}

