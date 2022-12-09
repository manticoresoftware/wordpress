<?php

/**
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

ini_set( 'display_errors', 1 );
error_reporting( E_ALL );

header( 'Content-Type: application/json' );


$include_path = realpath( __DIR__ . DIRECTORY_SEPARATOR . "include" );
$config_path  = realpath( __DIR__ . DIRECTORY_SEPARATOR . "configs" );
$config       = (object) parse_ini_file( "$config_path/config.ini.php" );

if ( empty( $config->table ) ) {
	$tmpDir                      = sys_get_temp_dir();
	$filename                    = $tmpDir . DIRECTORY_SEPARATOR . 'autocomplete_' . $_REQUEST['search_in'] . '.tmp';
	$config                      = (object) parse_ini_file( $filename );
	$config->tags_names          = explode( ' |*| ', $config->tags_names );
	$config->taxonomy_names      = explode( ' |*| ', $config->taxonomy_names );
	$config->custom_fields_names = explode( ' |*| ', $config->custom_fields_names );
	$config->search_in_blogs     = explode( ' |*| ', $config->search_in_blogs );
}

include_once( $include_path . DIRECTORY_SEPARATOR. "connectors" . DIRECTORY_SEPARATOR . "ManticoreConnector.php" );
include_once( $include_path . DIRECTORY_SEPARATOR. "connectors" . DIRECTORY_SEPARATOR . "ManticoreQlConnector.php" );
include_once( $include_path . DIRECTORY_SEPARATOR. "ManticoreAutocompleteCache.php" );
include_once( $include_path . DIRECTORY_SEPARATOR. "ManticoreAutocomplete.php" );

$cache = new ManticoreAutocompleteCache();
if ( ! empty( $_REQUEST['q'] ) &&
     (
	     strpos( $_REQUEST['q'], 'tax:' ) === 0 ||
	     strpos( $_REQUEST['q'], 'field:' ) === 0 ||
	     strpos( $_REQUEST['q'], 'tag:' ) === 0
     ) ) {

	include_once( $include_path . "ManticoreAutocompleteAdvanced.php" );
	$autocomplete_engine = new ManticoreAutocompleteAdvanced( $config, $cache );
} else {
	$autocomplete_engine = new ManticoreAutocomplete( $config, $cache );
}


exit( $autocomplete_engine->request( $_REQUEST ) );
