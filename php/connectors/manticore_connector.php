<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

abstract class ManticoreConnector {

	const TYPE_QUERY = 'query';
	const TYPE_DELETE = 'delete';
	const TYPE_INSERT = 'insert';
	const TYPE_SELECT = 'select';
	const TYPE_UPDATE = 'update';

	protected $config;
	/**
	 * Connection to searchhd
	 *
	 * @var mysqli|ManticoreHttpApi
	 */
	protected $connection;

	/**
	 * Index name
	 *
	 * @var string
	 */
	protected $index;

	/**
	 * Query type
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * @var string|array
	 */
	protected $query;

	/**
	 * Error message
	 * @var string
	 */
	protected $error;

	/**
	 * Filters can bee:
	 * [array of strings] eq a=1
	 * [array of array of strings] eq a=1 and a=2
	 * @var array
	 */
	protected $filters = [];

	/**
	 * Min value of range filter (BETWEEN)
	 * @var int
	 */
	protected $range_filter_from = 0;

	/**
	 * Max value of range filter (BETWEEN)
	 * @var int
	 */
	protected $range_filter_to = 0;

	/**
	 * Field of range filter (BETWEEN)
	 * @var string
	 */
	protected $range_filter_field = '';

	/**
	 * Value of IN filter
	 * @var int
	 */
	protected $in_filter_value = [];

	/**
	 * Field of IN filter
	 * @var string
	 */
	protected $in_filter_field = '';

	/**
	 * Array of append fields
	 * @var array
	 */
	protected $append_select = [];

	/**
	 * Array of sorting fields
	 * @var array
	 */
	protected $sort = [];

	/**
	 * Offset for query
	 * @var int
	 */
	protected $offset = 0;

	/**
	 * Limit for query
	 * @var int
	 */
	protected $limit = 0;

	/**
	 * Array of grouping fields
	 * @var array
	 */
	protected $group = [];

	/**
	 * Array of options
	 * @var array
	 */
	protected $field_weights = [];


	/**
	 * Max matches count for query
	 * @var int
	 */
	protected $max_matches = 0;

	/**
	 * Query results object
	 * @var Mysqli_result
	 */
	protected $results;

	/**
	 * Query status;
	 *
	 * @var string
	 */
	protected $status;

	/**
	 * Message (usually error text)
	 * @var string
	 */
	protected $message = '';

	/**
	 * Array of matches
	 *
	 * Can be:
	 *
	 * [int|string => value]
	 * if (key == int) => match ('value')
	 * if (key == str) => match ('@key "value"')
	 *
	 * @var array
	 */
	protected $matches = [];


	/**
	 * Index setter
	 *
	 * @param $index
	 *
	 * @return $this
	 */
	public function index( $index ): ManticoreConnector {
		if ( empty( $index ) ) {
			$this->error = 'Index can\'t be empty';
		}

		$this->index = $index;

		return $this;
	}


	abstract public function query( $query );

	/**
	 * Select setter.
	 *
	 * @return $this
	 */
	public function select(): ManticoreConnector {
		$this->type = self::TYPE_SELECT;

		return $this;
	}

	abstract public function insert( array $insertArray, $escapeFields = [] );

	abstract public function delete( $id, $field = 'id' );

	abstract public function deleteWhere( $index, array $data );

	abstract public function execute();

	abstract public function updateWhere( $index, array $updateData, array $whereData, $escapeFields = [] );

	abstract public function get_results();

	abstract public function get_all();

	abstract public function get_column( $field );

	abstract public function call_snippets( $data, $index, $query, $options );

	abstract public function flush( $index );

	abstract public function optimize( $index );

	/**
	 * Limits setter
	 *
	 * @param $limit
	 * @param int $offset
	 * @param int $maxMatches
	 *
	 * @return $this
	 */
	public function limits( int $limit, int $offset = 0, int $maxMatches = 0 ): ManticoreConnector {
		if ( ! empty( $offset ) ) {
			$this->offset = $offset;
		}

		if ( ! empty( $maxMatches ) ) {
			$this->max_matches = $maxMatches;
		}

		$this->limit = $limit;

		return $this;
	}


	/**
	 * Group setter
	 *
	 * @param $field
	 * @param $sort
	 * @param string $direction
	 *
	 * @return $this
	 */
	public function group( string $field, string $sort, string $direction = 'desc' ): ManticoreConnector {
		$this->group[]       = $field;
		$this->sort[ $sort ] = $direction;

		return $this;
	}

	/**
	 * Adding match into query
	 *
	 * @param $value
	 * @param string $field
	 *
	 * @return $this
	 */
	public function match( string $value, string $field = '' ): ManticoreConnector {
		if ( ! empty( $field ) ) {
			$this->matches[ $field ] = $value;
		} else {
			$this->matches[] = $value;
		}

		return $this;
	}


	/**
	 * @param string $sqlFunction
	 *
	 * @return $this
	 */
	public function append_select( string $sqlFunction ): ManticoreConnector {
		$this->append_select[] = $sqlFunction;

		return $this;
	}


	/**
	 * Get SphinxQl status. If connection has errors, status false. Usually calls after init,
	 * cause index or outher errors are excluded, and get only connection errors
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! empty( $this->error ) ) {
			return false;
		}

		return true;
	}


	/**
	 * Add (VALUE = 1 OR VALUE2 = 1) into WHERE statement
	 *
	 * @param array $fields
	 * @param bool $exclude
	 */
	public function add_or_filter( array $fields, bool $exclude = false ): void {
		if ( ! empty( $fields ) ) {
			$filter = [];
			foreach ( $fields as $field => $item ) {
				$filter[] = $field . ( $exclude ? '!' : '' ) . '=' . $item;
			}
			$this->filters[] = '(' . implode( ' OR ', $filter ) . ')';
		}
	}


	/**
	 * Adding filters into WHERE statement
	 *
	 * @param string $field
	 * @param array $value
	 * @param bool $exclude
	 */
	public function add_filter( string $field, array $value, bool $exclude = false ): void {

		if ( count( $value ) > 1 ) {
			$filter = [];
			foreach ( $value as $item ) {
				$filter[] = $field . ( $exclude ? '!' : '' ) . '=' . $item;
			}
			$this->filters[] = '(' . implode( ' OR ', $filter ) . ')';
		} else {
			$this->filters[] = $field . ( $exclude ? '!' : '' ) . '=' . $value[0];
		}

	}

	/**
	 * Adding IN filter into WHERE statement
	 *
	 * @param string $field
	 * @param array $values
	 */
	public function add_filter_in( string $field, array $values ): void {
		$this->in_filter_field = $field;
		$this->in_filter_value = implode( ',', $values );
	}

	/**
	 * Adding BETWEEN filter into WHERE statement
	 *
	 * @param string $field
	 * @param int $from
	 * @param int $to
	 */
	public function add_filter_range( string $field, int $from, int $to ): void {
		$this->range_filter_from  = $from;
		$this->range_filter_to    = $to;
		$this->range_filter_field = $field;
	}

	/**
	 * Compile and returns all filters
	 *
	 * @return string
	 */
	protected function get_filters(): string {

		if ( ! empty( $this->filters ) || ! empty( $this->range_filter_field )
		     || ! empty( $this->in_filter_field ) ) {

			$filtersArray = $this->filters;
			if ( ! empty( $this->range_filter_field ) ) {

				$filtersArray[] = $this->range_filter_field . ' BETWEEN ' .
				                  $this->range_filter_from . ' AND ' . $this->range_filter_to;
			}

			if ( ! empty( $this->in_filter_field ) ) {
				$filtersArray[] = $this->in_filter_field . ' IN (' . $this->in_filter_value . ')';
			}

			return implode( ' AND ', $filtersArray );
		}

		return '';
	}

	/**
	 * Returns query (response from searchhd server) error
	 *
	 * @return string
	 */
	public function get_query_error(): string {
		if ( ! empty( $this->connection->error ) ) {
			return $this->connection->error;
		}

		return $this->error;
	}

	/**
	 * Compile and returns matches
	 *
	 * @return string
	 */
	protected function get_matches(): string {
		$matchesArray = [];
		if ( ! empty( $this->matches ) ) {
			foreach ( $this->matches as $key => $match ) {
				if ( ! is_int( $key ) ) {
					$matchesArray[] = '@' . $key . ' ' . $match;
				} else {
					$matchesArray[] = $match;
				}
			}

			return "match ('" . implode( ' ', $matchesArray ) . "')";
		}

		return '';
	}

	public function field_weights( array $field_weights ): ManticoreConnector {
		if ( ! empty( $field_weights ) ) {
			$weights = [];
			foreach ( $field_weights as $field_name => $field_weight ) {
				if ( (int) $field_weight === 1 ) {
					/* Continue default value */
					continue;
				}
				$weights[] = $field_name . '=' . $field_weight;
			}
			if ( ! empty( $weights ) ) {
				$this->field_weights = 'field_weights = (' . implode( ', ', $weights ) . ')';
			}
		}

		return $this;
	}

	/**
	 * Adding sort parameters
	 *
	 * @param $filed
	 * @param string $direction
	 *
	 * @return $this
	 */
	public function sort( string $filed, string $direction = 'DESC' ): ManticoreConnector {
		$this->sort[ $filed ] = $direction;

		return $this;
	}

	/**
	 * Compiling all WHERE clause
	 * @return string
	 */
	protected function get_where_clause(): string {
		$filters = $this->get_filters();
		$matches = $this->get_matches();

		if ( ! empty( $filters ) && ! empty( $matches ) ) {
			return ' WHERE ' . $matches . ' AND ' . $filters;
		}

		if ( ! empty( $filters ) && empty( $matches ) ) {
			return ' WHERE ' . $filters;
		}

		if ( empty( $filters ) && ! empty( $matches ) ) {
			return ' WHERE ' . $matches;
		}

		return '';
	}

	/**
	 * Get limits, offset, orderm options for query
	 *
	 * @return string
	 */
	protected function get_limits(): string {
		$limit = '';
		if ( ! empty( $this->sort ) ) {
			$orderArray = [];
			foreach ( $this->sort as $sort => $direction ) {
				$orderArray[] = $sort . ' ' . $direction;
			}
			$limit .= ' ORDER BY ' . implode( ', ', $orderArray );
		}

		if ( ! empty( $this->limit ) ) {
			$limit .= ' LIMIT ' . $this->offset . ',' . $this->limit;
		}


		if ( ! empty( $this->max_matches ) || ! empty( $this->field_weights ) ) {
			$limit .= ' OPTION ';
			if ( ! empty( $this->max_matches ) && ! empty( $this->field_weights ) ) {
				$limit .= 'max_matches=' . $this->max_matches . ', ' . $this->field_weights;
			} elseif ( ! empty( $this->max_matches ) && empty( $this->field_weights ) ) {
				$limit .= 'max_matches=' . $this->max_matches;
			} elseif ( empty( $this->max_matches ) && ! empty( $this->field_weights ) ) {
				$limit .= $this->field_weights;
			}

		}

		return $limit;
	}

	/**
	 * Returns group by clause
	 *
	 * @return string
	 */
	protected function get_group_by(): string {
		if ( ! empty( $this->group ) ) {
			return ' GROUP BY ' . implode( ', ', array_values( $this->group ) );
		}

		return '';
	}

	/**
	 * Escape special chars
	 *
	 * @param $string
	 *
	 * @return mixed
	 */
	protected function escape_string( $string ) {
		$from = [ '\\', '(', ')', '|', '-', '!', '@', '~', '"', '&', '/', '^', '$', '=', '<', "'" ];
		$to   = [ '\\\\', '\(', '\)', '\|', '\-', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '\<', "\'" ];

		return str_replace( $from, $to, $string );
	}

	/**
	 * Clear all parameters
	 */
	public function clear(): void {
		$this->limit              = 0;
		$this->offset             = 0;
		$this->max_matches        = 0;
		$this->filters            = [];
		$this->range_filter_from  = 0;
		$this->range_filter_to    = 0;
		$this->range_filter_field = 0;
		$this->in_filter_value    = [];
		$this->in_filter_field    = '';
		$this->index              = '';
		$this->type               = '';
		$this->query              = '';
		$this->sort               = [];
		$this->matches            = [];
		$this->group              = [];
		$this->field_weights      = [];
	}

	protected function parseDataFields(array $whereData): array {
		$field   = $whereData[0];
		$operand = '=';
		if ( in_array( $whereData[1], [ '<', '>', '', 'IN' ] ) ) {
			$operand = $whereData[1];
			$value   = $whereData[2];
		} else {
			$value = $whereData[1];
		}

		return [$field, $operand, $value];
	}

}
