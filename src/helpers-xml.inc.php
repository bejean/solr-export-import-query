<?php

/*
 * helper functions
 */

function xml_load_string($str) {
    $str = preg_replace('/&(#[0-9]+);/', '__$1__', $str);
    return simplexml_load_string($str);
}

function xmlstr_save($str, $file, $backup=true) {
    // file exists ? -> save
    if ($backup && file_exists($file)) {
        $to = $file . '.' . date('Ymd-His');
        print ("File $file exists, saving to $to\n");
        copy($file, $to);
    }

    $str = preg_replace('/__(#[0-9]+)__/', '&$1;', $str);
    file_put_contents($file, $str);
}

function xml_attribute($object, $attribute) {
    if(isset($object[$attribute]))
        return (string) $object[$attribute];
}


function clone_dom_node(DOMNode $node, $newName) {
    $newNode = $node->ownerDocument->createElement($newName);

    foreach ($node->attributes as $attr)  {
        $newNode->appendChild($attr->cloneNode());
    }

    foreach ($node->childNodes as $child)  {
        $newNode->appendChild($child->cloneNode(true));
    }

    $node->parentNode->replaceChild($newNode, $node);
}

function xml_move_node(SimpleXMLElement $to, SimpleXMLElement $from) {
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

function xml_remove_nodes($xml, $xpath, $flag_only=false, $flag_message='remove') {
    $nodes=$xml->xpath($xpath);
    foreach($nodes as $node) {
        xml_remove_node($node, $flag_only, $flag_message);
    }
}

function xml_remove_node($node, $flag_only=false, $flag_message='remove') {
    $dom = dom_import_simplexml($node);
    if ($flag_only) {
        clone_dom_node($dom, $flag_message . '_' . $dom->tagName );
    } else {
        $dom->parentNode->removeChild($dom);
    }
}

function xml_remove_nodes_attribute($xml, $xpath, $name) {
    $nodes=$xml->xpath($xpath);
    foreach($nodes as $node) {
        unset($node[$name]);
    }
}

function remove_html_comments($html = '') {
    return preg_replace('/<!--(.|\s)*?-->/', '', $html);
}

function formatXmlString($xml) {

    // add marker linefeeds to aid the pretty-tokeniser (adds a linefeed between all tag-end boundaries)
    $xml = preg_replace('/(>)(<)(\/*)/', "$1\n$2$3", $xml);

    // now indent the tags
    $token      = strtok($xml, "\n");
    $result     = ''; // holds formatted version as it is built
    $pad        = 0; // initial indent
    $matches    = array(); // returns from preg_matches()

    $in_comment = false;

    // scan each line and adjust indent based on opening/closing tags
    while ($token !== false) :
        if (preg_match('/^.+<!--.*$/', $token, $matches))
            $in_comment = true;

        // test for the various tag states

        // 1. open and closing tags on same line - no change
        if (preg_match('/.+<\/\w[^>]*>$/', $token, $matches)) :
            $indent=0;
        // 2. closing tag - outdent now
        elseif (preg_match('/^<\/\w/', $token, $matches)) :
            $pad--;
        // 3. opening tag - don't pad this one, only subsequent tags
        elseif (preg_match('/^<\w[^>]*[^\/]>.*$/', $token, $matches)) :
            $indent=1;
        // 4. no indentation needed
        else :
            $indent = 0;
        endif;

        // pad the line with the required number of leading spaces
        if ($in_comment)
            $line    = str_pad($token, strlen($token), ' ', STR_PAD_LEFT);
        else
            $line    = str_pad($token, strlen($token)+$pad, ' ', STR_PAD_LEFT);

        if (preg_match('/-->/', $token, $matches))
            $in_comment = false;

        $result .= $line . "\n"; // add to the cumulative result, with linefeed
        $token   = strtok("\n"); // get the next token
        $pad    += $indent; // update the pad size for subsequent lines

    endwhile;

    return $result;
}

function xmlAttributes2Array($attributes)
{
    $attrArray = array();
    foreach ($attributes as $key => $val) {
        $attrArray[strtolower((string)$key)] = (string)$val;
    }
    return $attrArray;
}