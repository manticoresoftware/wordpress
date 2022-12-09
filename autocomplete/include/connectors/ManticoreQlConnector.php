<?php

/**
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


class ManticoreQlConnector implements ManticoreConnector {

	public $execution_time = [];
	public $execution_time_inner = [];
	private $connection;

	public function __construct( $config ) {
		mysqli_report( MYSQLI_REPORT_STRICT );
		if ( ! empty( $config->searchd_socket ) ) {
			$this->connection = new mysqli( '', '', '', '', 0, $config->searchd_socket );
		} else {
			$this->connection = new mysqli( $config->searchd_host . ':' . $config->searchd_port, '', '', '' );
		}
	}

	public function keywords( $index, $text, $options = [] ): array {

		$sql = "CALL KEYWORDS('" . $text . "', '" . $index . "' " . $this->getAppendOptions( $options ) . ")";

		return $this->fetch( $this->query( $sql ) );
	}

	public function qsuggest( $index, $word, $options ): array {
		$sql = "CALL QSUGGEST('" . $word . "', '" . $index . "' " . $this->getAppendOptions( $options ) . ")";

		return $this->fetch( $this->query( $sql ) );
	}

	public function select( $data ): array {

		$whereAdded = false;
		$index      = $data['index'];
		$query      = /** @lang manticore_sql */ "SELECT * FROM $index";


		if ( isset( $data['where'] ) ) {
			$where = $data['where'];

			$field   = $where[0];
			$operand = '=';
			if ( $where[1] === '<' || $where[1] === '>' || $where[1] === 'IN' ) {
				$operand = $where[1];
				$value   = $where[2];
			} else {
				$value = $where[1];
			}

			$search['query'][ $field ] = [ $operand => $value ];

			$query      .= " WHERE $field $operand $value";
			$whereAdded = true;
		}

		if ( isset( $data['match_phrase'] ) ) {
			$query .= " " . ( $whereAdded ? 'and' : 'where' ) . " match('\"" . $data['match_phrase'] . "\"')";

		} elseif ( isset( $data['match'] ) ) {
			$query .= " " . ( $whereAdded ? 'and' : 'where' ) . " match('" . $data['match'] . "')";
		}


		if ( isset( $data['limit'] ) ) {
			$query .= " limit = " . $data['limit'];
		}

		return $this->fetch( $this->query( $query ) );
	}

	public function snippets( $index, $query, $data, $options ): array {
		$sql = "CALL SNIPPETS('" . $data . "', '" . $index . "', '" . $query . "' " . $this->getAppendOptions( $options ) . ")";

		return $this->fetch( $this->query( $sql ) );
	}

	private function query( $sql ) {
		return $this->connection->query( $sql );
	}

	private function fetch( $stmt ):array {
		$results = [];
		if ( ! empty( $stmt ) ) {
			while ( $stmt_return = $stmt->fetch_assoc() ) {
				$results[] = $stmt_return;
			}
		}

		return $results;
	}

	private function getAppendOptions( $options ): string {
		$append = '';

		if ( ! empty( $options ) ) {
			$append = ', ' . implode( ', ', $options );
		}

		return $append;
	}
}
