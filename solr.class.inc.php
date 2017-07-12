<?php

class Solr
{

	private $_url = null;
	private $_writer = null;
	private $_timeout = null;

	function __construct($url, $collection, $writer = 'json', $timeout = 60) {
		$this->_url=$url . $collection . '/';
		$this->_writer=$writer;
		$this->_timeout=$timeout;
	}

	/**
	 *  cleanup entity in data
	 *
	 * @param string $value - data string
	 * @return string - the cleaned data
	 * @throws none
	 */
	public function remove_entity($value)
	{
		$value = html_entity_decode($value, ENT_QUOTES, "UTF-8");
		$value = htmlspecialchars($value, ENT_QUOTES);
		return $value;
	}

	/**
	 *  function definition to compute the document boost
	 *
	 * @param array $values - associative array (key/value)
	 * @return string $boost - custom boost value (default '')
	 * @throws none
	 */
	/*
	public function compute_boost($value)
	{
		if ($value['groupesource'] == 'Magnolia-WEBSITE') {
			$boost = '4';
			$volumeRedactionnel = 0;
			if (!empty($value['chapo'][0])) $volumeRedactionnel += strlen($value['chapo'][0]);
			if (!empty($value['accroche'][0])) $volumeRedactionnel += strlen($value['accroche'][0]);
			if (!empty($value['keywords'][0])) $volumeRedactionnel += strlen($value['keywords'][0]);
			if ($volumeRedactionnel > 700) {
				$boost = '8';
			}
		}
		return $boost;
	}
    */

	/**
	 *  function definition to convert array to xml
	 *
	 * @param array $array - associative array (key/value)
	 * @param $xml - the xml documlent to build
	 * @param $parent_key - the parent element name
	 * @return array $data - solr data array
	 * @throws none
	 */
	public function array_to_xml($array, &$xml, $parent_key = '')
	{
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				if (!is_numeric($key)) {
					$boost = '';
					if ($key == 'doc_elt') {
						$key = 'doc';
						$value = $value['doc'];
						$boost = $this->compute_boost($value);
					}
					if ($key == 'doc') {
						$subnode = $xml->addChild("$key");
						if (!empty($boost)) {
							$subnode->addAttribute("boost", $boost);
						}
						$str = $subnode->asXML();
						$this->array_to_xml($value, $subnode);
					} else {
						$this->array_to_xml($value, $xml, $key);
					}
				} else {
					$this->array_to_xml($value, $xml);
				}
			} else {
				if (!empty($value)) {
					$value = $this->remove_entity($value);
					if (!is_numeric($key)) {
						$subnode = $xml->addChild("field", "$value");
						$subnode->addAttribute("name", "$key");
					} else {
						$subnode = $xml->addChild("field", "$value");
						$subnode->addAttribute("name", "$parent_key");
					}
				}
			}
		}
	}

	/**
	 *  executes a get query on a solr database
	 *
	 * @param array $query - associative array (key/value) of query string variables
	 * @return array $data - solr data array
	 * @throws none
	 */
	public function get($query, $retry = false)
	{
		$ch = $this->init_curl();    /* Initialize curl */

		//Set output type
		$query['wt'] = 'json';

		//Set URL
		$url = $this->_url . 'select?' . http_build_query($query);
		curl_setopt($ch, CURLOPT_URL, $url);
		$response = curl_exec($ch);
		$data = $this->transform($response);

		if (isset($data['response']) && count($data['response']['docs']) > 0) {
			return $data;
		} else {
			//Sleep and re-try once
			if ($retry) {
				sleep(5);
				$this->get($query, false);
			}
			return false;
		}
	}

	/**
	 *  posts data to a solr database and optionally commits data
	 *
	 * @param array $data - associative (key/value pair) array of solr data
	 * @return array $response - solr response array
	 * @throws none
	 */
	public function post($data, $doc_cnt)
	{
		$ch = $this->init_curl();    /* Initialize curl */

		//Set update URL
		$url = $this->_url . 'update';
		curl_setopt($ch, CURLOPT_URL, $url);

		//Configure curl for post
		$xml = new SimpleXMLElement('<add/>');
		//print_r($data);
		$this->array_to_xml($data, $xml);
		$data_post = $xml->asXML();

		//print ("=======$doc_cnt\n");
		//file_put_contents("log/doc-$doc_cnt.xml", $data_post);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_post);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/xml'
		));

		//Execute post
		$resp = curl_exec($ch);
		//print("resp = $resp\n");
		$solr_response = simplexml_load_string($resp);
		return (int)$solr_response->lst->int[0];
	}

	/**
	 *  sends a commit statement to solr
	 *
	 * @param none
	 * @return array $response - solr response array
	 * @throws none
	 */
	public function commit()
	{
		$ch = $this->init_curl();    /* Initialize curl */

		$url = $this->_url . 'update?commit=true';
		curl_setopt($ch, CURLOPT_URL, $url);
		//curl_setopt($ch,CURLOPT_POST,true);

		//Execute post
		$resp = curl_exec($ch);
		$solr_response = simplexml_load_string($resp);
		if (!$solr_response) {
			return false;
		}
		return (int)$solr_response->lst->int[0];
	}

	/**
	 *  sends an optimize statement to solr
	 *
	 * @param none
	 * @return array $response - solr response array
	 * @throws none
	 */
	public function optimize()
	{
		$ch = $this->init_curl();    /* Initialize curl */

		$url = $this->_url . 'update?optimize=true';
		curl_setopt($ch, CURLOPT_URL, $url);
		//curl_setopt($ch,CURLOPT_POST,true);

		//Execute post
		$resp = curl_exec($ch);
		$solr_response = simplexml_load_string($resp);
		if (!$solr_response) {
			return false;
		}
		return (int)$solr_response->lst->int[0];
	}

	/**
	 *  transforms raw solr responses into associative array
	 *
	 * @param string $data - raw, unprocessed, solr data
	 * @return array $response - associative array representation of raw solr response data
	 * @throws Exception - unsupported output format
	 */
	public function transform($data)
	{
		return json_decode($data, true);
	}


	/**
	 *  initializes curl with default options
	 *
	 * @param none
	 * @return resource $ch - curl handle
	 * @throws none
	 */
	protected function init_curl()
	{
		//Initialize CURL
		$ch = curl_init();

		//Set CURL Options to return results
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		//Follow up to two redirects
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 2);

		//Set timeout so it doesn't run forever
		//5 seconds to make a connection
		//15 seconds for the whole transfer
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->_timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout);

		//Do not verify SSL peer
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

		//Set connectivity port
		//curl_setopt($ch, CURLOPT_PORT, SOLR_PORT);
		return $ch;
	}

	public function post_binarydata ($content)
	{
		$ch = $this->init_curl();    /* Initialize curl */
		$url = $this->_url . 'update';
		curl_setopt($ch, CURLOPT_URL, $url);

		$header = array('Content-type:application/json');
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

		curl_setopt($ch, CURLOPT_POSTFIELDS, $content);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		$resp = curl_exec($ch);
		$result = json_decode($resp);
		curl_close($ch);

		return $result;
	}
}

?>