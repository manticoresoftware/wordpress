<?php

/**
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

interface ManticoreConnector {

	public function __construct( $config );

	public function keywords( $index, $text, $options = [] );

	public function qsuggest( $index, $word, $options );

	public function select( $data );

	public function snippets( $index, $query, $data, $options );

}
