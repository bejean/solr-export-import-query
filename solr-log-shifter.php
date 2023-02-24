<?php


function usage() {
    print ('php solr-log-shifter.php -i logfile');
    exit(-1);
}

date_default_timezone_set('Europe/Paris');

$options = getopt("i:");


$inputfile = isset($options['i']) ? $options['i'] : '';

$ouputfile = $inputfile . '.shifted';

if (empty($inputfile) || !file_exists($inputfile))
   exit();


// Open your file in read mode
$input = fopen($inputfile, "r");

$output = fopen($ouputfile,"a",1);

// Display a line of the file until the end
while(!feof($input)) {

    $line = fgets($input);

    if (preg_match("/QTime=([0-9]*)$/", $line, $matches, PREG_OFFSET_CAPTURE)) {
        $i = 1;
        //if (preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}/", $line, $matches2, PREG_OFFSET_CAPTURE)) {
        if (preg_match("/^[0-9]{4}\-[0-9]{2}\-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}/", $line, $matches2, PREG_OFFSET_CAPTURE)) {
            $t = strtotime($matches2[0][0]);

            //$date = DateTime::createFromFormat('Y-m-d H:i:s.u', $matches2[0][0]);
            //$t2 = $date->getTimestamp();

            $t2 = date('Y-m-d H:i:s', $t);

            //$now = DateTime::createFromFormat('U.u', $t);
            $line2= $t2 . substr($line, strlen($matches2[0][0]));
            fwrite($output,$line2);
        }
    }
}
fclose($output);

