<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class Manticore_View {
	private $view = null;

	public function __construct() {
		$this->view = new stdClass();

		// Todo delete in production

		// WORKER_CACHE = false
		// LME_MAIN_DATA = true
		// WORKER_CACHE_ILS = plugin_md5
		// NEED_UPDATE_WORKER = Plugin secure key is not valid
		// MS_TIME = w_time
	}

	public function render( $file ): void {
		require_once( SPHINXSEARCH_PLUGIN_DIR . '/templates/' . $file );
	}

	public function assign( $key, $value ): void {
		$this->view->{$key} = $value;
	}

	public function __set( $name, $value ) {
		$this->view->$name = $value;
	}

	public function __isset($name){

	}

	public function __get($name){

	}
}
