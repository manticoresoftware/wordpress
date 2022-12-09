<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class SphinxQL extends ManticoreConnector implements SplObserver {

	/**
	 * SphinxQL constructor.
	 *
	 * @param Manticore_Config $config
	 */
	public function __construct( Manticore_Config $config ) {
		mysqli_report( MYSQLI_REPORT_STRICT );
		$this->config = $config;
		$this->connect();
	}

	public function connect(): bool {
		$this->error   = '';
		$this->message = '';

		try {
			if ( $this->config->admin_options['sphinx_use_socket'] === 'true' ) {
				$this->connection = new mysqli( '', '', '', '', 0, $this->config->admin_options['sphinx_socket'] );
			} else {
				$this->connection = new mysqli( $this->config->admin_options['sphinx_host'] . ':' .
				                                $this->config->admin_options['sphinx_port'], '', '', '' );
			}

			return true;

		} catch ( Exception $exception ) {
			$this->error = $exception->getMessage();

			return false;
		}

	}

	public function close(): void {
		if ( ! empty( $this->connection ) ) {
			$this->connection->refresh( MYSQLI_REFRESH_HOSTS | MYSQLI_REFRESH_THREADS );
			unset( $this->connection );
		}
		$this->error = 'Connection refused';
	}


	/**
	 * Query setter
	 *
	 * @param $query
	 *
	 * @return $this
	 */
	public function query( $query ): SphinxQL {
		if ( empty( $query ) ) {
			$this->error = 'Query can\'t be empty';
		}

		$this->query = $query;
		$this->type  = self::TYPE_QUERY;

		return $this;
	}


	/**
	 * Execution query
	 *
	 * @return $this
	 */
	public function execute(): SphinxQL {
		if ( ! empty( $this->error ) ) {
			$this->status  = 'error';
			$this->message = $this->error;

			return $this;
		}
		if ( empty( $this->index ) && $this->type != self::TYPE_QUERY ) {
			$this->status  = 'error';
			$this->message = 'Index is undefined';

			return $this;
		}

		if ( $this->type === self::TYPE_INSERT ) {

			$this->query = 'REPLACE INTO ' . $this->index . ' ' . $this->query;
		} elseif ( $this->type === self::TYPE_DELETE ) {

			$this->query = 'DELETE FROM ' . $this->index . ' ' . $this->query;
		} elseif ( $this->type === self::TYPE_SELECT ) {
			$appendSelect = ', WEIGHT() AS weight';
			if ( ! empty( $this->append_select ) ) {
				$appendSelect .= ', ' . implode( ', ', $this->append_select );
			}
			if ( isset( $this->sort['date_relevance'] ) ) {
				$appendSelect .= ', INTERVAL(date_added, NOW()-90*86400, NOW()-30*86400, ' .
				                 'NOW()-7*86400, NOW()-86400, NOW()-3600) AS date_relevance';
			}

			if ( ! empty( $this->sort ) && array_key_exists( 'count', $this->sort ) ) {
				$appendSelect .= ', count(*) AS count';
			}
			$this->query = 'SELECT *' . $appendSelect . ' FROM ' . $this->index . $this->get_where_clause() . ' ' . $this->get_group_by() . $this->get_limits();
		}

		$this->status  = 'success';
		$this->results = $this->connection->query( $this->query );

		$this->clear();

		return $this;
	}

	public function show_meta(): array {
		$meta     = [];
		$raw_meta = $this
			->query( "SHOW META" )
			->execute()
			->get_all();

		if ( ! empty( $raw_meta ) ) {
			foreach ( $raw_meta as $row ) {
				$meta[ $row['Variable_name'] ] = $row['Value'];
			}
		}

		return $meta;
	}

	/**
	 * Reutrn query results
	 *
	 * @return array
	 */
	public function get_results(): array {

		return [
			'status'   => $this->status,
			'results'  => $this->results,
			'affected' => ! empty( $this->connection->affected_rows ) ? $this->connection->affected_rows : false
		];
	}

	/**
	 * Fetching query results
	 *
	 * @return bool|mixed
	 */
	public function get_all() {
		if ( empty( $this->results ) ) {
			return false;
		}

		return $this->results->fetch_all( MYSQLI_ASSOC );
	}

	/**
	 * Fetching query result
	 *
	 * @param $field string
	 *
	 * @return bool|mixed
	 */
	public function get_column( $field ) {
		if ( empty( $this->results ) ) {
			return false;
		}

		$res = $this->results->fetch_object();
		if ( isset( $res->$field ) ) {
			return $res->$field;
		}

		return false;
	}


	/**
	 * Insert setter
	 *
	 * @param array $insertArray
	 * @param array $escapeFields
	 *
	 * @return $this
	 */
	public function insert( array $insertArray, $escapeFields = [] ): SphinxQL {

		$this->type = self::TYPE_INSERT;
		$keys       = [];
		$rows       = [];
		foreach ( $insertArray as $k => $result ) {
			if ( (int) $k === 0 ) {
				$keys = array_keys( $result );
			}

			foreach ( $result as $field => $value ) {

				if ( in_array( $field, $escapeFields ) ) {
					$result[ $field ] = $this->escape_string( $result[ $field ] );
				}

				$result[ $field ] = '\'' . $result[ $field ] . '\'';
			}

			$rows[] = '(' . implode( ',', array_values( $result ) ) . ')';
		}

		if ( ! empty( $rows ) ) {
			$this->query = '(`' . implode( '`, `', $keys ) . '`) VALUES ' . implode( ', ', $rows );
		}

		return $this;
	}


	/**
	 * Delete setter
	 *
	 * @param $id
	 *
	 * @param string $field
	 *
	 * @return $this
	 */
	public function delete( $id, $field = 'id' ): SphinxQL {
		if ( empty( $id ) ) {
			$this->error = 'Id for delete can\'t be empty';
		}
		$this->type  = self::TYPE_DELETE;
		$this->query = 'WHERE ' . $field . '=' . (int) $id;

		return $this;
	}


	public function update( SplSubject $subject ):void {
		$this->config = $subject;
	}

	public function deleteWhere( $index, array $data ): SphinxQL {
		$this->index = $index;
		$this->type  = self::TYPE_DELETE;

		[$field, $operand, $value] = $this->parseDataFields($data);

		$this->query = 'WHERE ' . $field . $operand . '"' . $value . '"';

		return $this;
	}

	public function call_snippets( $data, $index, $query, $options ) {

		if ( count( $data ) > 1 ) {
			$content = '(\'' . implode( '\', \'', $data ) . '\')';
		} else {
			$content = '\'' . $data[0] . '\'';
		}


		return $this
			->query( "CALL SNIPPETS(" . $content . ", '" . $index . "', '" .
			         $query . "', " . implode( ' , ', $options ) . ")" )
			->execute()
			->get_all();
	}

	public function flush( $index ) {

		$sql = "FLUSH RAMCHUNK rt $index";

		$query = $this->connection->query( $sql );

		$results = false;
		if ( ! empty( $query ) ) {
			while ( $result = $query->fetch_assoc() ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * @throws Exception
	 */
	public function optimize( $index ) {
		$sql = "optimize index $index";

		$query = $this->connection->query( $sql );

		$results = false;
		if ( ! empty( $query ) && $query !== true ) {
			while ( $result = $query->fetch_assoc() ) {
				$results[] = $result;
			}
		}

		return $results;
	}

	public function updateWhere( $index, array $updateData, array $whereData, $escapeFields = [] ) {
		return $this;
		// TODO: Implement updateWhere() method.
	}
}
