<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class Manticore_Container {

	public $config;
	/** @var SphinxQL  */
	public $sphinxQL;
	public $service;
	public $backend;
	public $frontend;
	public $indexer;

	public function __construct() {
		$this->config = new Manticore_Config();
		$this->sphinxQL = new SphinxQL( $this->config );

		$this->service  = new Manticore_Service( $this->config );
		$this->backend  = new Manticore_Backend( $this->config );
		$this->frontend = new Manticore_FrontEnd( $this->config );
		$this->indexer  = new Manticore_Indexing( $this->config );

		$this->config->attach( $this->sphinxQL );
		$this->config->attach( $this->service );
		$this->config->attach( $this->indexer );
	}
}
