<?php

/**
 * @file
 * Validation helper for the GSA Feed.
 *
 * Usage:
 * 0, Update the gsa.dtd file if necessary or if you wish to use the one
 *    supplied with your GSA device.
 * 1, Copy the new output to source.xml.
 * 2, From the web folder, use this:
 *    drush php-script modules/custom/gsa_feed/tool/validate.php
 * 3, It either will give errors, or execute without any message.
 */

$root = 'gsafeed';
$xml = file_get_contents(__DIR__ . '/source.xml');

$old = new DOMDocument();
$old->loadXML($xml);

$creator = new DOMImplementation();
$doctype = $creator->createDocumentType($root, NULL, __DIR__ . '/gsa.dtd');
$new = $creator->createDocument(NULL, NULL, $doctype);
$new->encoding = 'utf-8';

$oldNode = $old->getElementsByTagName($root)->item(0);
$newNode = $new->importNode($oldNode, TRUE);
$new->appendChild($newNode);

$new->validate();
