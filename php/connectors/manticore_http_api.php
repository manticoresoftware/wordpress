<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class ManticoreHttpApi extends ManticoreConnector implements SplObserver {

	const MAX_REQUEST_SIZE = 524288;
	const CURL_TIMEOUT = 30;
	private $apiHost = '';
	public $execution_time;


	public function __construct( Manticore_Config $config ) {
		$this->config  = $config;
		$this->apiHost = $this->config->admin_options['api_host'];
	}


	/**
	 * @throws JsonException
	 */
	public function updateConfig( $content ) {

		$args = $this->prepareRequestBody( [ 'config' => $content ] );

		return $this->wp_remote_post( $this->apiHost . '/json/config/',
			$args );
	}


	/**
	 * @param WP_Error|array $response
	 *
	 * @return ManticoreHttpApi
	 * @throws Exception
	 */
	protected function handle_server_response( $response ) {
		$response_code = wp_remote_retrieve_response_code( $response );
		$result        = '';
		if ( (int)$response_code === 200 ) {
			if ( is_wp_error( $response ) ) {
				$this->errors[] = $response->get_error_message();
			} else {
				if ( isset( $response['body'] ) ) {
					$content = $response['body'];
					$content = json_decode( $content, true );
					if ( ! empty( $content['attrs'] ) ) {
						$content['attrs'] = array_flip( $content['attrs'] );
					}

					if ( ! empty( $content['hits'] ) ) {
						$result = $content['hits']['hits'];
					} else {
						$result = $content;
					}
				}
			}

		} else {
			$this->errors[] = 'Error #' . $response_code . ' ';
			if ( isset( $response->errors->http_request_failed ) ) {
				return $this->execute();
			}

			if ( is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() );
			}

			throw new \RuntimeException( $response['body'] );
		}

		return $result;
	}

	public function update( SplSubject $subject ):void {
		$this->config = $subject;
	}

	/**
	 * @throws JsonException
	 */
	public function insert( array $insertArray, $escapeFields = [] ): ManticoreHttpApi {

		$this->type = self::TYPE_INSERT;

		$insert = [];

		if ( empty( $this->index ) ) {
			throw new Exception( 'index can\'t be empty!!!' );
		}

		foreach ( $insertArray as $k => $result ) {

			foreach ( $result as $field => $value ) {

				if ( in_array( $field, $escapeFields ) ) {
					$result[ $field ] = $this->escape_string( $result[ $field ] );
				}
			}

			if ( isset( $result['ID'] ) ) {
				$id = $result['ID'];
				unset( $result['ID'] );
			} else {
				$id = $result['id'];
				unset( $result['id'] );
			}

			$singleQuery     = [ 'replace' => [ 'index' => $this->index, 'id' => (int) $id , 'doc' => $result ] ];
			$singleQueryJson = json_encode( $singleQuery, JSON_THROW_ON_ERROR );

			$insert[] = $singleQueryJson;
		}

		if ( ! empty( $insert ) ) {

			$insert      = $this->chunkExplode( $insert );
			$this->query = $insert;
		}

		return $this;
	}

	public function delete( $id, $field = 'id' ):void {
		// TODO: Implement delete() method.
	}

	public function query( $query ) {
		throw new \RuntimeException( 'Query method are not allowed in Manticore http api' );
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 */
	public function execute(): ManticoreHttpApi {

		$this->results = [];
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


		switch ( $this->type ) {
			case self::TYPE_SELECT:


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

				$this->results = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/sql/?query=' . urlencode( $this->query ),
					[
						'timeout' => self::CURL_TIMEOUT,
						//'sslcertificates' => $this->config->admin_options['cert_path']
					] ) );

				break;

			case self::TYPE_DELETE:

				$this->results = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/json/delete/',
					$this->prepareRequestBody( $this->query ) ) );
				break;

			case self::TYPE_INSERT:
			case self::TYPE_UPDATE:

				foreach ( $this->query as $item ) {
					$args = [
						'headers' => [
							'Content-Type' => 'application/x-ndjson',
						],
						'body'    => $item,
						'timeout' => self::CURL_TIMEOUT,
						//'sslcertificates' => $this->config->admin_options['cert_path']
					];


					$responseResults = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/json/bulk/',
						$args ) );

					if ( isset( $responseResults['errors'] ) ) {
						$this->results['errors'] = $responseResults['errors'];
					}
					if ( ! empty( $responseResults['items'] ) ) {
						foreach ( $responseResults['items'] as $items ) {
							$this->results['items'][] = $items;
						}
					}
				}
				break;

		}

		$this->status = 'success';

		$this->clear();

		return $this;
	}

	public function get_results(): array {

		$results  = [];
		$affected = 0;

		if ( ! empty( $this->results['items'] ) ) {
			foreach ( $this->results['items'] as $k => $itemType ) {
				if ( isset( $itemType['replace'] ) ) {
					if ( $itemType['replace']['result'] === "updated" ) {
						$affected ++;
					}
				}
			}
		}


		return [
			'status'   => $this->status,
			'results'  => $results,
			'affected' => ! empty( $affected ) ? $affected : false
		];

	}

	public function get_all() {

		$results = [];
		if ( ! empty( $this->results ) ) {

			foreach ( $this->results as $resultKey => $match ) {
				$match['_source']['id'] = $match['_id'];
				$results[]              = $match['_source'];
			}
		}

		return ! empty( $results ) ? $results : false;

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

		if ( isset( $this->results[0]['_source'][ $field ] ) ) {
			return $this->results[0]['_source'][ $field ];
		}

		return false;
	}

	public function deleteWhere( $index, array $data ): ManticoreHttpApi {

		$this->index = $index;
		$this->type  = self::TYPE_DELETE;

		[$field, $operand, $value] = $this->parseDataFields($data);

		$this->query = [
			'index' => $this->index,
			'doc'   => [
				$field => [ $operand => $value ]
			]
		];

		return $this;
	}

	private function chunkExplode( $explodedQuery ): array {
		$chunkedLength = 0;

		$i       = 0;
		$chunked = [];
		foreach ( $explodedQuery as $query ) {
			$chunkedLength += mb_strlen( $query, '8bit' );
			if ( $chunkedLength > self::MAX_REQUEST_SIZE ) {
				$i ++;
				$chunkedLength = mb_strlen( $query, '8bit' );
			}

			$chunked[ $i ][] = $query;

		}
		$result = [];

		foreach ( $chunked as $batch ) {
			$result[] = implode( "\n", $batch );
		}

		return $result;
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 */
	public function call_snippets( $data, $index, $query, $options ): string {

		$args = $this->prepareRequestBody( [
			'data'    => $data,
			'index'   => $index,
			'query'   => $query,
			'options' => $options
		] );

		$responseResults = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/json/snippets/',
			$args ) );

		return $responseResults['results'];
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 */
	public function flush( $index ): string {
		$args = $this->prepareRequestBody( [ 'index' => $index ] );

		$responseResults = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/json/flush/',
			$args ) );

		return $responseResults['results'];
	}

	/**
	 * @throws JsonException
	 * @throws Exception
	 */
	public function optimize( $index ): string {
		$args = $this->prepareRequestBody( [ 'index' => $index ] );

		$responseResults = $this->handle_server_response( $this->wp_remote_post( $this->apiHost . '/json/optimize/',
			$args ) );

		return $responseResults['results'];
	}


	/**
	 * @param $index
	 * @param array $updateData ['key1' => 'value1', 'key2' => 'value2']
	 * @param array $whereData ['id', 'IN', '(1,2,3)']
	 * @param array $escapeFields
	 *
	 * @return $this
	 */
	public function updateWhere( $index, array $updateData, array $whereData, $escapeFields = [] ): ManticoreHttpApi {
		$this->index = $index;
		$this->type  = self::TYPE_UPDATE;


		foreach ( $updateData as $k => $v ) {
			if ( in_array( $k, $escapeFields ) ) {
				$updateData[ $k ] = $this->escape_string( $v );
			}
		}

		[$field, $operand, $value] = $this->parseDataFields($whereData);

		$this->query = [
			'index' => $this->index,
			'doc'   => [
				$field => $value
			],

			// WHERE CONDITION
			'query' => [
				$field => [ $operand => $value ]
			]
		];

		return $this;
	}


	/**
	 * @throws JsonException
	 */
	private function prepareRequestBody( array $parameters ): array {
		return [
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'timeout' => self::CURL_TIMEOUT,
			'body'    => json_encode( $parameters, JSON_THROW_ON_ERROR ),
		];
	}

	/**
	 * @param $url
	 * @param array $args
	 *
	 * @return array|WP_Error
	 */

	private function wp_remote_post( $url, array $args = [] ) {

		$start  = microtime( true );
		$result = wp_remote_post( $url, $args );

		if ( empty( $this->execution_time[ $url ] ) ) {
			$this->execution_time[ $url ] = 0;
		}
		$this->execution_time[ $url ] += ( microtime( true ) - $start );

		return $result;
	}


}
