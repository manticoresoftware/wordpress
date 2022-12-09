<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class Manticore_Indexing implements SplObserver {

	public const TYPE_POST = 'posts';
	public const TYPE_COMMENTS = 'comments';
	public const TYPE_ATTACHMENTS = 'attachments';
	public const TYPE_STATS = 'stats';

	public const INDEXING_POSTS_MAX_LIMIT = 100;
	public const INDEXING_COMMENTS_MAX_LIMIT = 500;
	public const INDEXING_SENTENCES_MAX_LIMIT = 5000;
	public const INDEXING_ATTACHMENTS_MAX_LIMIT = 200;
	public const INDEXING_STATS_MAX_LIMIT = 500;

	public const INDEXING_COUNTERS_TABLE = 'sph_indexing_counters';
	public const INDEXING_LOCK_NAME = 'manticore_indexing_lock';
	public const INDEXING_LOCK_TIME = 1;

	public const STORED_KEY_RAND = '43m4z87';


	private $autocomplete_max_id;

	/**
	 * Exploder for raw files
	 *
	 * @var string
	 */
	private $exploder = "\n\t\n\t\n";

	/**
	 * @var Manticore_Config
	 */
	private $config;
	/**
	 * @var wpdb
	 */
	private $wpdb;
	/**
	 * @var string
	 */
	private $table_prefix;
	/**
	 * @var string
	 */
	private $table_counters;
	/**
	 * @var array
	 */
	private $sql_queries;

	private $blog_id;

	/**
	 * @var array
	 */
	private $errors = [];

	/**
	 * Directory for storing raw data.
	 * If indexing error occurred, indexing data puts into storage
	 *
	 * @var string
	 */
	private $raw_directory;

	private $error_log_path;

	/**
	 * Manticore_Indexing constructor.
	 *
	 * @param Manticore_Config $config
	 */
	public function __construct( Manticore_Config $config ) {
		global $wpdb, $table_prefix;
		$this->config         = $config;
		$this->wpdb           = $wpdb;
		$this->table_prefix   = $table_prefix;
		$this->blog_id        = get_current_blog_id();
		$this->table_counters = $this->table_prefix . self::INDEXING_COUNTERS_TABLE;
		$this->raw_directory  = $this->config->admin_options['sphinx_path'] . DIRECTORY_SEPARATOR . 'reindex';
		$this->sql_queries    = $this->get_index_queries();
		$this->error_log_path = SPHINXSEARCH_SPHINX_INSTALL_DIR . DIRECTORY_SEPARATOR . 'indexing.log';
	}


	/**
	 * @param $index_data
	 * @param $index_limit
	 *
	 * @return float
	 */
	private function get_indexing_cycles_count( $index_data, $index_limit ): float {

		return ceil( ( $index_data['all_count'] - $index_data['indexed'] ) / $index_limit );
	}

	/**
	 * @param $indexed
	 *
	 * @return string
	 */
	private function get_indexing_offset( $indexed ): string {
		if ( $indexed === '0' ) {
			return '';
		}

		return ' OFFSET ' . $indexed;
	}


	/**
	 * @return array
	 */
	public function reindex(): array {
		if ( ! ManticoreSearch::$plugin->sphinxQL->is_active() ) {

			if ( ! file_exists( $this->config->get_option( 'sphinx_searchd' ) ) ||
			     ! file_exists( $this->config->get_option( 'sphinx_conf' ) ) ) {
				return [ 'status' => 'error', 'message' => 'Indexer: configuration files not found.' ];
			}

			return [ 'status' => 'error', 'message' => 'Manticore daemon inactive' ];
		}

		if ( $this->check_lock() !== '1' ) {
			return [ 'status' => 'error', /*'message' => 'Another indexer is still running'*/ ];
		}

		/*
		require_once( '/home/klim/xhprof/xhprof_lib/utils/xhprof_lib.php' );
		require_once( '/home/klim/xhprof/xhprof_lib/utils/xhprof_runs.php' );
		xhprof_enable( XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY );
		*/

		$results = [];

		if ( ManticoreSearch::is_main_blog() === 'true' ) {

			$exclude_blogs = $this->config->get_option( 'exclude_blogs_from_search' );
			$blogs_list    = [];

			foreach ( ManticoreSearch::get_network_sites( false ) as $blog_id => $blog_url ) {
				if ( in_array( $blog_id, $exclude_blogs ) ) {
					continue;
				}
				$blogs_list[] = $blog_id;
			}

			foreach ( $blogs_list as $blog ) {
				$this->config->update_admin_options( [ 'now_indexing_blog' => $blog ] );
				$this->blog_id = $blog;

				if ( $blog > 1 ) {
					$this->table_prefix = $this->wpdb->base_prefix . $blog . '_';
				} else {
					$this->table_prefix = $this->wpdb->base_prefix;
				}

				$this->table_counters = $this->table_prefix . self::INDEXING_COUNTERS_TABLE;

				$this->sql_queries = $this->get_index_queries();

				$results = $this->do_reindex();
			}
			$this->config->update_admin_options( [ 'now_indexing_blog' => '' ] );
		} else {

			$root_options  = get_blog_option( ManticoreSearch::get_main_blog_id(),
				Manticore_Config::ADMIN_OPTIONS_NAME );
			$exclude_blogs = $root_options['exclude_blogs_from_search'];
			if ( in_array( $this->blog_id, $exclude_blogs ) ) {
				return [ 'status' => 'error', 'message' => 'This blog excluded from search by administrator' ];
			}

			$results = $this->do_reindex();
		}

		$this->wpdb->get_var( 'SELECT RELEASE_LOCK("' . self::INDEXING_LOCK_NAME . '")' );

		/*
		$xhprof_data = xhprof_disable();
		$xhprof_runs = new \XHProfRuns_Default();
		$run_id      = $xhprof_runs->save_run( $xhprof_data, "xhprof_testing" );
		*/


		return [ 'status' => 'success', 'results' => $results ];

	}

	private function do_reindex(): array {

		if ( $this->config->admin_options['autocomplete_cache_clear'] === 'update' ) {
			$au_cache = new ManticoreAutocompleteCache();
			$au_cache->clean_cache();
		}

		$started = $this->get_results();

		if ( $started['steps'] === "4" ) {
			$this->reset_counters();
		}


		$counters        = $this->get_index_counters();
		$sorted_counters = [];
		foreach ( $counters as $k => $counter ) {
			$sorted_counters[ $counter['type'] ] = $counter;
		}
		unset( $counters );

		$results = [];
		foreach (
			[
				self::TYPE_POST,
				self::TYPE_COMMENTS,
				self::TYPE_ATTACHMENTS,
				self::TYPE_STATS
			] as $type
		) {
			if ( $sorted_counters[ $type ]['finished'] === "1" ) {
				continue;
			}
			$results[] = $this->index( $type );
		}

		return $results;
	}

	/**
	 * @return null|string
	 */
	private function check_lock(): ?string {
		return $this->wpdb->get_var(
			'SELECT GET_LOCK("' . self::INDEXING_LOCK_NAME . '", ' . self::INDEXING_LOCK_TIME . ')' );
	}


	private function get_index_counters() {
		return $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_counters, ARRAY_A );
	}


	private function reset_counters(): void {

		$counters = $this->get_index_counters();
		foreach ( $counters as $key => $counter ) {
			$counter['indexed']   = 0;
			$counter['finished']  = 0;
			$counter['all_count'] = $this->get_content_count( $counter['type'] );
			$this->wpdb->update( $this->table_counters, $counter, [ 'type' => $counter['type'] ] );
		}


		ManticoreSearch::$plugin->sphinxQL
			->deleteWhere( Manticore_Config_Maker::MAIN_INDEX_PREFIX . $this->blog_id, [ 'id', '>', 0 ] )->execute();
		ManticoreSearch::$plugin->sphinxQL
			->deleteWhere( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id,
				[ 'id', '>', 0 ] )->execute();
		ManticoreSearch::$plugin->sphinxQL
			->deleteWhere( Manticore_Config_Maker::STATS_INDEX_PREFIX . $this->blog_id, [ 'id', '>', 0 ] )->execute();

		$this->delete_indexing_log();
		$this->clear_raw_data();
	}


	/**
	 * @param string $type
	 *
	 * @return string|array
	 * @throws Exception
	 */
	private function index( string $type = self::TYPE_POST ) {

		$index_name = Manticore_Config_Maker::MAIN_INDEX_PREFIX . $this->blog_id;
		if ( $type === self::TYPE_POST ) {
			$index_limit = self::INDEXING_POSTS_MAX_LIMIT;

		} elseif ( $type === self::TYPE_COMMENTS ) {
			$index_limit = self::INDEXING_COMMENTS_MAX_LIMIT;

		} elseif ( $type === self::TYPE_ATTACHMENTS ) {
			$index_limit = self::INDEXING_ATTACHMENTS_MAX_LIMIT;

		} else {
			$index_limit = self::INDEXING_STATS_MAX_LIMIT;
			$index_name  = Manticore_Config_Maker::STATS_INDEX_PREFIX . $this->blog_id;
		}

		$indexing_result = $this->get_index_result_by_type( $type );
		if ( $indexing_result['all_count'] === "0" ) {
			$indexing_result['finished'] = "1";
			$this->wpdb->update( $this->table_counters, $indexing_result, [ 'type' => $type ] );

			return '';
		}
		$cycles            = $this->get_indexing_cycles_count( $indexing_result, $index_limit );
		$errors            = 0;
		$old_error_handler = set_error_handler( [ $this, "my_error_handler" ] );

		for ( $i = 0; $i <= $cycles; $i ++ ) {

			$offset  = $this->get_indexing_offset( $indexing_result['indexed'] );
			$results = $this->get_content_results( $type, $offset );

			if ( ! empty( $this->wpdb->last_error ) ) {
				$errors ++;
				$this->add_to_indexing_log( $this->wpdb->last_error );
				if ( $errors > 5 ) {
					break;
				}
				continue;
			}


			if ( empty( $results ) ) {
				$indexing_result['indexed']  = $indexing_result['all_count'];
				$indexing_result['finished'] = 1;
				$this->wpdb->update( $this->table_counters, $indexing_result, [ 'type' => $type ] );
				break;
			}

			$update = ManticoreSearch::$plugin->sphinxQL
				->index( $index_name )
				->insert( $results, [ 'title', 'body', 'category', 'tags', 'taxonomy', 'custom_fields', 'keywords' ] )
				->execute()
				->get_results();

			if ( $type !== self::TYPE_STATS ) {

				$ac_sentences = $this->explode_sentences( $results, false );

				$chunked = array_chunk( $ac_sentences, self::INDEXING_SENTENCES_MAX_LIMIT );

				foreach ( $chunked as $chunk ) {
					ManticoreSearch::$plugin->sphinxQL
						->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id )
						->insert( $chunk, [
							'content',
							'string_content'
						] )
						->execute()
						->get_results();

				}

			}


			if ( $update['status'] === 'success' && $update['results'] === false ) {

				return [ 'status' => 'error', 'message' => 'Indexer: Error indexing.' ];
			}

			if ( $update['status'] === 'error' ) {

				return [ 'status' => 'error', 'message' => $update['message'] ];
			}

			$indexing_result['indexed'] = min( $indexing_result['indexed'] + $update['affected'],
				$indexing_result['all_count'] );
			if ( $indexing_result['indexed'] === $indexing_result['all_count'] ) {
				$indexing_result['finished'] = 1;
			} else {
				$indexing_result['finished'] = 0;
			}

			$this->wpdb->update( $this->table_counters, $indexing_result, [ 'type' => $type ] );
		}


		restore_error_handler();


		if ( $type !== self::TYPE_STATS ) {
			ManticoreSearch::$plugin->sphinxQL->flush( $index_name );
			ManticoreSearch::$plugin->sphinxQL->flush( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id );
			ManticoreSearch::$plugin->sphinxQL->optimize( $index_name );
			ManticoreSearch::$plugin->sphinxQL->optimize( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id );

		} else {
			ManticoreSearch::$plugin->sphinxQL->optimize( $index_name );
		}

		return 'Indexing ' . $type . ' complete. <b>' . $indexing_result['all_count']
		       . '</b> document' . ( $indexing_result['all_count'] > 1 ? 's' : '' ) . ' indexed ';
	}

	/**
	 * @return array
	 */
	public function get_results(): array {

		$blog = $this->config->admin_options['now_indexing_blog'];

		if ( ! empty( $blog ) && $blog > 1 ) {
			$table_prefix = $this->wpdb->base_prefix . $blog . '_';
		} else {
			$table_prefix = $this->wpdb->base_prefix;
		}

		$table_counters = $table_prefix . self::INDEXING_COUNTERS_TABLE;

		$results = $this->wpdb->get_row(
			'SELECT ' .
			'sum(`indexed`) as indexed, ' .
			'sum(`all_count`) as all_count, ' .
			'sum(`finished`) as steps ' .
			'FROM ' . $table_counters, ARRAY_A );
		if ( file_exists( $this->error_log_path ) ) {
			$results['logs'] = file_get_contents( $this->error_log_path );
			$results['logs'] = str_replace( "\n", '<br>', $results['logs'] );
		}
		$results['blog_id'] = $this->config->admin_options['now_indexing_blog'];

		return $results;


	}

	/**
	 * @param $type
	 *
	 * @return array|null|object|void
	 */
	private function get_index_result_by_type( $type ) {
		return $this->wpdb->get_row( 'SELECT * FROM ' . $this->table_counters . ' WHERE type = "' . $type . '"',
			ARRAY_A );
	}

	/**
	 * @return array
	 */
	private function get_index_queries(): array {
		$queries_config = include( SPHINXSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'manticore_index_queries.php' );

		$indexing_taxonomy        = $this->get_taxonomy_indexing_fields();
		$indexing_custom_fields   = $this->get_custom_indexing_fields();
		$skip_indexing_mime_types = $this->get_skip_indexing_mime_types();
		$index_post_types         = $this->get_indexing_post_types();
		if ( ! empty( $queries_config ) ) {
			foreach ( $queries_config as $k => $query ) {
				$queries_config[ $k ] = str_replace( '{table_prefix}', $this->table_prefix, $query );
				$queries_config[ $k ] = str_replace( '{index_taxonomy}', $indexing_taxonomy, $queries_config[ $k ] );
				$queries_config[ $k ] = str_replace( '{index_custom_fields}', $indexing_custom_fields,
					$queries_config[ $k ] );
				$queries_config[ $k ] = str_replace( '{skip_indexing_mime_types}', $skip_indexing_mime_types,
					$queries_config[ $k ] );
				$queries_config[ $k ] = str_replace( '{index_post_types}', $index_post_types, $queries_config[ $k ] );
				$queries_config[ $k ] = str_replace( '{blog_id}', $this->blog_id, $queries_config[ $k ] );
				$queries_config[ $k ] = str_replace( '{shards_count}', ManticoreSearch::SHARDS_COUNT,
					$queries_config[ $k ] );

				if ( $k === 'query_stats' ) {
					$queries_config[ $k ] = str_replace( '{limit}', self::INDEXING_STATS_MAX_LIMIT,
						$queries_config[ $k ] );
				} elseif ( $k === 'query_attachments' ) {
					$queries_config[ $k ] = str_replace( '{limit}', self::INDEXING_ATTACHMENTS_MAX_LIMIT,
						$queries_config[ $k ] );
				} else {
					$queries_config[ $k ] = str_replace( '{limit}', self::INDEXING_POSTS_MAX_LIMIT,
						$queries_config[ $k ] );
				}

			}
		}

		return $queries_config;
	}

	/**
	 * @param $type
	 *
	 * @return int
	 */
	public function get_content_count( $type ): int {

		if ( $type === self::TYPE_POST ) {
			$sum = $this->wpdb->get_var( $this->sql_queries['query_posts_count'] );
		} elseif ( $type === self::TYPE_COMMENTS ) {
			$sum = $this->wpdb->get_var( $this->sql_queries['query_comments_count'] );
		} elseif ( $type === self::TYPE_ATTACHMENTS ) {
			$sum = $this->wpdb->get_var( $this->sql_queries['query_attachments_count'] );
		} else {
			$sum = $this->wpdb->get_var( $this->sql_queries['query_stats_count'] );
		}

		return (int) $sum;
	}

	/**
	 * @param string $type
	 * @param string $offset
	 *
	 * @return array|null|object
	 * @throws JsonException
	 */
	private function get_content_results( string $type, string $offset = '' ) {
		if ( $type === self::TYPE_POST ) {

			$prepare = $this->sql_queries['query_posts_ids'];
			$ids     = $this->wpdb->get_results( $prepare . $offset, ARRAY_N );

			$query = $this->sql_queries['query_posts'];

			$id_arr = [];
			foreach ( $ids as $id ) {
				$id_arr[] = $id[0];
			}

			if ( empty( $id_arr ) ) {
				return [];
			}

			$query = str_replace( '{in_ids}', implode( ',', $id_arr ), $query );

			$results = $this->wpdb->get_results( $query, ARRAY_A );


			/* Prepare taxonomy or custom fields to valid for storing format */
			if ( $this->config->admin_options['taxonomy_indexing'] === 'true'
			     || $this->config->admin_options['custom_fields_indexing'] === 'true' ) {
				foreach ( $results as $k => $result ) {

					if ( $this->config->admin_options['taxonomy_indexing'] === 'true' ) {
						if ( ! empty( $result['taxonomy'] ) ) {
							$one_row = explode( "\n", $result['taxonomy'] );
							foreach ( $one_row as $kk => $row ) {
								$exploded_rows  = explode( '|*|', $row );
								$field_name     = array_pop( $exploded_rows );
								$one_row[ $kk ] = $this->get_key_wrapper( trim( $field_name ),
									implode( ' ', $exploded_rows ) );
							}

							$results[ $k ]['taxonomy'] = implode( ' ', $one_row );
						}
					}
					if ( ( $this->config->admin_options['custom_fields_indexing'] === 'true' )
					     && ! empty( $result['custom_fields'] ) ) {

						$one_row = explode( "\n", $result['custom_fields'] );
						foreach ( $one_row as $kk => $row ) {
							$exploded_rows  = explode( '|*|', $row );
							$field_name     = array_pop( $exploded_rows );
							$one_row[ $kk ] = $this->get_key_wrapper( trim( $field_name ),
								implode( ' ', $exploded_rows ) );
						}

						$results[ $k ]['custom_fields'] = implode( ' ', $one_row );
					}
				}
			}


		} elseif ( $type === self::TYPE_COMMENTS ) {

			$query   = $this->sql_queries['query_comments'];
			$results = $this->wpdb->get_results( $query . $offset, ARRAY_A );

		} elseif ( $type === self::TYPE_ATTACHMENTS ) {

			$query       = $this->sql_queries['query_attachments'];
			$attachments = $this->wpdb->get_results( $query . $offset, OBJECT_K );
			if ( $this->config->admin_options['attachments_indexing'] === 'true' ) {
				$results = [];
				if ( ! empty( $attachments ) ) {
					foreach ( $attachments as $k => $attachment ) {
						$post              = new WP_Post( $attachment );
						$parsed_attachment = $this->parse_attachment( $post );
						if ( ! empty( $parsed_attachment ) ) {
							$results[] = $parsed_attachment;
						}
					}
				}
			} else {
				return [];
			}
		} else {

			$query   = $this->sql_queries['query_stats'];
			$results = $this->wpdb->get_results( $query . $offset, ARRAY_A );

		}

		return $results;
	}

	private function explode_sentences( $articles, $clear = true ): array {
		$content = [];

		if ( $this->autocomplete_max_id === null ) {

			$max_id = ManticoreSearch::$plugin->sphinxQL
				->select()
				->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id )
				->append_select( 'max(id) as max_id' )
				->execute()
				->get_column( 'max_id' );

			if ( empty( $max_id ) ) {
				$max_id = 0;
			}
		} else {
			$max_id = $this->autocomplete_max_id;
		}

		$posts_id = [];
		foreach ( $articles as $article ) {
			$post_id    = ! empty( $article['ID'] ) ? $article['ID'] : $article['id'];
			$posts_id[] = $post_id;


			$taxonomy      = $article['taxonomy'];
			$custom_fields = $article['custom_fields'];
			$tags          = $article['tags'];

			$article = strip_tags( $article['title'] . '. ' . $article['body'] );
			$article = str_replace( [ "\n", "\t", "\r" ], [ ' ', ' ', ' ' ], $article );

			$sentences = preg_split( '/[.?!;:]\s+/', $article, - 1, PREG_SPLIT_NO_EMPTY );

			$advanced_sentences = [];
			if ( $taxonomy !== 'null' ) {
				$taxonomy           = explode( "\n", $taxonomy );
				$advanced_sentences = array_merge( $advanced_sentences, $taxonomy );
			}

			if ( $custom_fields !== 'null' ) {
				$custom_fields      = explode( "\n", $custom_fields );
				$advanced_sentences = array_merge( $advanced_sentences, $custom_fields );
			}

			if ( $tags !== 'null' ) {
				$tags               = explode( "\n", $tags );
				$advanced_sentences = array_merge( $advanced_sentences, $tags );
			}
			foreach ( $sentences as $sentence ) {
				if ( empty( $sentence ) || strlen( $sentence ) <= 5 ) {
					continue;
				}
				$max_id ++;
				$sentence  = trim( $sentence );
				$content[] = [
					'id'             => $max_id,
					'post_ID'        => $post_id,
					'advanced'       => 0,
					'content'        => $sentence,
					'string_content' => $sentence
				];
			}

			foreach ( $advanced_sentences as $sentence ) {
				if ( empty( $sentence ) ) {
					continue;
				}
				$max_id ++;
				$sentence  = trim( $sentence );
				$content[] = [
					'id'             => $max_id,
					'post_ID'        => $post_id,
					'advanced'       => 1,
					'content'        => $sentence,
					'string_content' => $sentence
				];
			}
		}

		$this->autocomplete_max_id = $max_id;

		if ( ! empty( $post_id ) && $clear ) {
			ManticoreSearch::$plugin->sphinxQL->deleteWhere( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $this->blog_id,
				[ 'id', 'IN', '(' . implode( ',', $posts_id ) . ')' ] );
		}

		return $content;
	}

	/**
	 * @return string
	 */
	private function get_custom_indexing_fields(): string {

		if ( ! empty( $this->config->get_option( 'custom_fields_for_indexing' ) ) ) {

			foreach ( $this->config->get_option( 'custom_fields_for_indexing' ) as $k => $field ) {
				$fields[] = "'" . $field . "'";
			}

			if ( ! empty( $fields ) ) {
				return implode( ',', $fields );
			}
		}

		return "'manticore_non_indexing'";
	}

	/**
	 * @return string
	 */
	private function get_skip_indexing_mime_types(): string {

		if ( ! empty( $this->config->get_option( 'attachments_type_for_skip_indexing' ) ) ) {

			foreach ( $this->config->get_option( 'attachments_type_for_skip_indexing' ) as $k => $field ) {
				$fields[] = "'" . $field . "'";
			}

			if ( ! empty( $fields ) ) {
				return implode( ',', $fields );
			}
		}

		return "'manticore_non_indexing'";
	}

	private function get_indexing_post_types(): string {
		foreach ( $this->config->get_option( 'post_types_for_indexing' ) as $k => $field ) {
			$fields[] = "'" . $field . "'";
		}

		if ( ! empty( $fields ) ) {
			return implode( ',', $fields );
		}

		return "'manticore_non_indexing'";
	}

	/**
	 * @return string
	 */
	private function get_taxonomy_indexing_fields(): string {

		if ( $this->config->get_option( 'taxonomy_indexing' ) === 'true' ) {
			$fields = [];
			foreach ( $this->config->get_option( 'taxonomy_indexing_fields' ) as $k => $field ) {
				$fields[] = "'" . $field . "'";
			}
			if ( ! empty( $fields ) ) {
				return implode( ',', $fields );
			}
		}

		return "'manticore_non_indexing'";
	}


	/**
	 * Inserting posts id into rawdata if manticore are stopped
	 *
	 * @param string $type
	 * @param int $blog_id
	 * @param string|array $rawData
	 *
	 * @return bool
	 * @throws JsonException
	 */
	public function set_raw_data( string $type, int $blog_id, $rawData ): bool {
		$flag = false;
		if ( ! empty( $type ) && ! empty( $rawData ) ) {
			$handle = fopen( $this->raw_directory . DIRECTORY_SEPARATOR . sha1( microtime() ) . '.dat', "wb" );
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				fwrite( $handle, json_encode( $type, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) );
				fwrite( $handle, $this->exploder );
				fwrite( $handle, json_encode( $blog_id, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) );
				fwrite( $handle, $this->exploder );
				if ( is_string( $rawData ) ) {
					fwrite( $handle, $rawData );
				} else {
					fwrite( $handle, json_encode( $rawData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) );
				}
				flock( $handle, LOCK_UN );
				$flag = true;
			}
			fclose( $handle );
		}

		return $flag;
	}

	/**
	 * Return list of raw data by limit
	 *
	 * @param int $limit
	 * @param int $page
	 *
	 * @return array
	 */
	public function find_raw_data_files( int $limit = 0, int $page = 1 ): array {

		$cmd   = 'find ' . $this->raw_directory .
		         ' -type f -iname "*.dat" -printf \'%T@ %p\n\' 2>/dev/null'
		         . ' | sort -k1 -n | awk \'{print $2}\'';
		$limit = (int) $limit;
		$page  = (int) $page;
		if ( $limit > 0 ) {
			$line_to   = $limit * $page;
			$line_from = ( $line_to - $limit ) + 1;
			$cmd       .= ' | sed -n ' . $line_from . ',' . $line_to . 'p';
		}
		$stream  = '';
		$console = popen( $cmd, "r" );
		while ( ! feof( $console ) ) {
			$stream .= fread( $console, 2048 );
		}
		fclose( $console );
		if ( ! empty( $stream ) ) {
			return explode( "\n", trim( $stream ) );
		}

		return [];
	}


	/**
	 * Returns file content
	 *
	 * @param $file
	 *
	 * @return mixed
	 * @throws JsonException
	 */
	public function get_raw_data_file( $file ) {
		if ( ! empty( $file ) ) {
			$md5_file = md5( $file );

			$current_file = [
				$md5_file => explode( $this->exploder, file_get_contents( $file ) )
			];
			if ( ! empty( $current_file[ $md5_file ][0] ) ) {
				$current_file[ $md5_file ][0] =
					json_decode( $current_file[ $md5_file ][0], true, 512, JSON_THROW_ON_ERROR );
			} else {
				$current_file[ $md5_file ][0] = [];
			}
			if ( empty( $current_file[ $md5_file ][1] ) ) {
				$current_file[ $md5_file ][1] = '';
			}

			if ( empty( $current_file[ $md5_file ][2] ) ) {
				$current_file[ $md5_file ][2] = '';
			}

			return $current_file[ $md5_file ];
		}

		return '';
	}

	/**
	 * Delete all raw files when call "Index all posts"
	 *
	 */
	public function clear_raw_data(): void {
		$rawFiles = $this->find_raw_data_files();
		if ( ! empty( $rawFiles ) ) {
			foreach ( $rawFiles as $rawFile ) {
				unlink( $rawFile );
			}
		}
	}


	/**
	 * @param $comment_id
	 * @param $comment_object
	 *
	 * @throws JsonException
	 */
	public function on_comment_inserted( $comment_id, $comment_object ): void {
		$this->on_all_status_transitions( 'approved', '', $comment_object );
	}


	/**
	 * @return bool
	 * @throws JsonException
	 */
	public function check_raw_data(): bool {

		$rawFiles     = $this->find_raw_data_files( 100 );
		$skippedFiles = 0;
		if ( ! empty( $rawFiles ) ) {
			foreach ( $rawFiles as $rawFile ) {
				if ( $skippedFiles >= 10 ) {
					/**
					 * If more than 10 files are skipped,
					 * it makes no sense to continue.
					 * Something is wrong with the indexer
					 */
					return false;
				}
				$content = $this->get_raw_data_file( $rawFile );
				if ( ! empty( $content ) ) {
					if ( $content[0] === 'delete' ) {
						$deleteIndex = json_decode( $content[2], true, 512, JSON_THROW_ON_ERROR );

						$indexResult = ManticoreSearch::$plugin->sphinxQL
							->index( Manticore_Config_Maker::MAIN_INDEX_PREFIX . $content[1] )
							->delete( $deleteIndex )
							->execute()
							->get_results();

						ManticoreSearch::$plugin->sphinxQL
							->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $content[1] )
							->delete( $deleteIndex, 'post_id' )
							->execute()
							->get_results();
					} else {
						$insertIndex = json_decode( $content[2], true, 512, JSON_THROW_ON_ERROR );
						$indexResult = ManticoreSearch::$plugin->sphinxQL
							->index( Manticore_Config_Maker::MAIN_INDEX_PREFIX . $content[1] )
							->insert( $insertIndex, [ 'title', 'body', 'category' ] )
							->execute()
							->get_results();

						$ac_sentences = $this->explode_sentences( $insertIndex );

						$chunked = array_chunk( $ac_sentences, self::INDEXING_SENTENCES_MAX_LIMIT );

						foreach ( $chunked as $chunk ) {
							ManticoreSearch::$plugin->sphinxQL
								->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $content[1] )
								->insert( $chunk, [ 'title', 'body' ] )
								->execute()
								->get_results();
						}

					}

					if ( ! empty( $indexResult ) &&
					     ( ( $indexResult['status'] === 'success' && $indexResult['results'] === false )
					       || $indexResult['status'] === 'error' ) ) {
						$skippedFiles ++;
						continue;
					}
				}
				unlink( $rawFile );
			}
		}


		if ( $this->config->admin_options['attachments_indexing'] === 'true' ) {
			$non_indexed_attachments = $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_prefix . 'sph_indexing_attachments WHERE indexed = 0',
				ARRAY_A );
			if ( ! empty( $non_indexed_attachments ) ) {
				foreach ( $non_indexed_attachments as $not_indexed_attachment ) {
					$results    = [];
					$attachment = get_post( $not_indexed_attachment['id'] );
					if ( in_array( $attachment->post_mime_type,
						$this->config->admin_options['attachments_type_for_skip_indexing'] ) ) {
						$this->wpdb->delete( $this->table_prefix . 'sph_indexing_attachments',
							[ 'id' => $not_indexed_attachment['id'] ] );
						$this->delete_from_main_index( ManticoreSearch::set_sharding_id( $not_indexed_attachment['id'],
							$this->blog_id ), $this->blog_id );
						continue;
					}
					$parsed_attachment = $this->parse_attachment( $attachment );
					if ( ! empty( $parsed_attachment ) ) {
						$results[] = $parsed_attachment;
					}
				}
				if ( ! empty( $results ) ) {
					$this->insert_to_main_index( $results, $this->blog_id );
				}
			}
		}

		return true;
	}


	/**
	 * Remove all special characters from a key
	 *
	 * @param $string
	 *
	 * @return null|string
	 */
	public static function normalize_key( $string ): ?string {
		return preg_replace( '/[^A-Za-z0-9]/', '', $string );
	}

	/**
	 * Wrap key by special words wor taxonomy/custom fields searching
	 *
	 * @param $key
	 * @param $content
	 *
	 * @return string
	 */
	private function get_key_wrapper( $key, $content ): string {
		$key             = self::normalize_key( $key );
		$originalContent = $content;
		$content         = explode( ' ', $content );
		foreach ( $content as $k => $v ) {
			$content[ $k ] = $key . '' . $v;
		}
		$content = implode( ' ', $content );

		return self::STORED_KEY_RAND . $key . self::STORED_KEY_RAND . 'START ' . $content . ' | ' . $originalContent . ' | ' . self::STORED_KEY_RAND . $key . self::STORED_KEY_RAND . "END \n";
	}

	/**
	 * @return string
	 */
	private function get_content_taxonomy(): string {
		$post_string_taxonomy = 'null';
		if ( $this->config->admin_options['taxonomy_indexing'] === 'true' ) {
			$post_taxonomies = [];

			foreach ( $this->config->get_option( 'taxonomy_indexing_fields' ) as $key => $taxonomy ) {

				if ( ! empty( $_POST['tax_input'][ $taxonomy ] ) ) {

					foreach ( $_POST['tax_input'][ $taxonomy ] as $k => $item ) {
						if ( preg_match( '#^\d+$#u', $item ) ) {
							$term = get_term( ( int) $item );
							if ( ! empty( $term->name ) ) {
								$post_taxonomies[] = $this->get_key_wrapper( $term->taxonomy, $term->name );
							}
						} else {
							$post_taxonomies[] = $this->get_key_wrapper( $k, $item );
						}
					}
				}

			}

			if ( ! empty( $post_taxonomies ) ) {
				$post_string_taxonomy = implode( ' ', $post_taxonomies );
			}
		}

		return $post_string_taxonomy;
	}

	/**
	 * @param $post_id
	 *
	 * @return string
	 */
	private function get_custom_fields( $post_id ): string {
		$post_string_custom_fields = 'null';
		if ( $this->config->admin_options['custom_fields_indexing'] === 'true' ) {
			$post_custom_fields = [];

			foreach ( $this->config->get_option( 'custom_fields_for_indexing' ) as $k => $custom_field ) {
				$post_custom_fields_tmp = get_post_meta( $post_id, $custom_field );
				if ( ! empty( $post_custom_fields_tmp ) ) {
					$post_custom_fields[] = $this->get_key_wrapper( $custom_field,
						implode( ' ', $post_custom_fields_tmp ) );
				}
			}

			if ( ! empty( $post_custom_fields ) ) {
				$post_string_custom_fields = implode( ' ', $post_custom_fields );
			}
		}

		return $post_string_custom_fields;
	}


	/**
	 * @param int $post_id
	 *
	 * @throws JsonException
	 */
	public function on_delete_attachment( $post_id ): void {
		$this->delete_from_main_index( ManticoreSearch::set_sharding_id( $post_id, $this->blog_id ), $this->blog_id );
		$this->wpdb->delete( $this->table_prefix . 'sph_indexing_attachments', [ 'id' => $post_id ] );
	}


	/**
	 * @throws JsonException
	 */
	public function on_add_attachment( $post_id ): void {
		$attachment = $this->parse_attachment( get_post( $post_id ), true );
		if ( ! empty( $attachment ) ) {
			$this->insert_to_main_index( [ $attachment ], $this->blog_id );
		}
	}


	/**
	 * @param $new_status
	 * @param $old_status
	 * @param WP_Comment|WP_Post $object
	 *
	 * @throws JsonException
	 */
	public function on_all_status_transitions( $new_status, $old_status, $object ): void {

		if ( $this->config->admin_options['autocomplete_cache_clear'] === 'update' ) {
			$au_cache = new ManticoreAutocompleteCache();
			$au_cache->clean_cache();
		}

		$this->check_raw_data();

		if ( $object instanceof WP_Comment ) {
			$type = 'comment';
		} else {
			$type = 'post';
		}

		if ( $type === 'post' && ! in_array( $object->post_type,
				$this->config->admin_options['post_types_for_indexing'] ) ) {
			return;
		}
		if ( $new_status === 'publish' || $new_status === 'approved' ) {
			$post_string_taxonomy = $this->get_content_taxonomy();
			$object_type          = 2;

			if ( $type === 'post' ) {
				$post_custom_fields = $this->get_custom_fields( $object->ID );
				$tags               = wp_get_post_tags( $object->ID );
				$categories         = wp_get_post_categories( $object->ID, [ 'fields' => 'all' ] );

				$tag_list = 'null';
				if ( ! empty( $tags ) ) {
					$tag_list = [];
					foreach ( $tags as $tag ) {
						$tag_list[] = $tag->name;
					}
					$tag_list = implode( ',', $tag_list );
				}

				$categories_list = 'null';
				if ( ! empty( $categories ) ) {
					$categories_list = [];
					foreach ( $categories as $category ) {
						$categories_list[] = $category->name;
					}
					$categories_list = implode( ',', $categories_list );
				}

				if ( $object->post_type === 'post' ) {
					$object_type = 0;
				} elseif ( $object->post_type === 'page' ) {
					$object_type = 1;
				}

				$newPostIndex[] = [
					'id'            => ManticoreSearch::set_sharding_id( $object->ID, $this->blog_id ),
					'blog_id'       => $this->blog_id,
					'comment_ID'    => 0,
					'post_ID'       => $object->ID,
					'title'         => $object->post_title,
					'body'          => $object->post_content,
					'category'      => ! empty( $categories_list ) ? $categories_list : 'null',
					'taxonomy'      => $post_string_taxonomy,
					'custom_fields' => $post_custom_fields,
					'isPost'        => $object_type == 0 ? 1 : 0,
					'isComment'     => 0,
					'isPage'        => $object_type == 1 ? 1 : 0,
					'post_type'     => $object_type,
					'date_added'    => strtotime( $object->post_date ),
					'tags'          => ! empty( $tag_list ) ? $tag_list : 'null'
				];
			} else {
				$newPostIndex[] = [
					'id'            => ManticoreSearch::set_sharding_id( $object->comment_ID, $this->blog_id, true ),
					'blog_id'       => $this->blog_id,
					'comment_ID'    => $object->comment_ID,
					'post_ID'       => $object->comment_post_ID,
					'title'         => $object->comment_title,
					'body'          => $object->comment_content,
					'attachments'   => 'null',
					'category'      => 'null',
					'taxonomy'      => $post_string_taxonomy,
					'custom_fields' => 'null',
					'isPost'        => $object_type == 0 ? 1 : 0,
					'isComment'     => $type === 'post' ? 0 : 1,
					'isPage'        => $object_type == 1 ? 1 : 0,
					'post_type'     => $object_type,
					'date_added'    => strtotime( $object->comment_date ),
					'tags'          => 'null'
				];
			}

			$this->insert_to_main_index( $newPostIndex, $this->blog_id );
		}
		if ( in_array( $new_status, [ 'trash', 'spam', 'unapproved', 'delete' ] ) ) {
			if ( $type === 'post' ) {
				$ids[] = ManticoreSearch::set_sharding_id( $object->ID, $this->blog_id );

				$children_attachments = get_children( [
					'post_parent' => $object->ID,
					'post_type'   => 'attachment'
				] );

				if ( ! empty( $children_attachments ) ) {
					foreach ( $children_attachments as $attachment ) {
						$ids[] = ManticoreSearch::set_sharding_id( $attachment->ID, $this->blog_id );
					}
				}
			} else {
				$ids[] = ManticoreSearch::set_sharding_id( $object->comment_ID, $this->blog_id, true );
			}
			foreach ( $ids as $id ) {
				$this->delete_from_main_index( $id, $this->blog_id );
			}
		}
		// A function to perform actions any time any post changes status.
	}


	/**
	 * @param WP_Post $attachment
	 * @param bool $reparse
	 *
	 * @return array
	 * @throws JsonException
	 */
	private function parse_attachment( WP_Post $attachment, bool $reparse = false): array {

		if ( $this->config->admin_options['attachments_indexing'] === 'true' && $this->config->is_tika_enabled() ) {

			$file_name = get_attached_file( $attachment->ID );
			if ( empty( $file_name ) || ! file_exists( $file_name ) ||
			     in_array( $attachment->post_mime_type,
				     $this->config->admin_options['attachments_type_for_skip_indexing'] ) ) {

				return [
					'id'            => ManticoreSearch::set_sharding_id( $attachment->ID, $this->blog_id ),
					'blog_id'       => $this->blog_id,
					'comment_ID'    => 0,
					'post_ID'       => $attachment->ID,
					'title'         => $attachment->post_title,
					'body'          => $attachment->post_content,
					'category'      => 'null',
					'taxonomy'      => 'null',
					'custom_fields' => 'null',
					'isPost'        => 1,
					'isComment'     => 0,
					'isPage'        => 0,
					'post_type'     => 0,
					'date_added'    => $attachment->post_date,
					'tags'          => ''
				];
			}

			$hash        = sha1_file( $file_name );
			$cached_data = $this->wpdb->get_row( 'SELECT * FROM `' . $this->table_prefix . 'sph_indexing_attachments` WHERE id = ' . $attachment->ID,
				ARRAY_A );
			if ( $reparse === false && ! empty( $cached_data ) && $cached_data['hash'] === $hash && $cached_data['indexed'] === '1' ) {
				$attachment_content = $cached_data['content'];
			} else {

				$indexed = 0;
				if ( file_exists( $file_name ) ) {
					$file = fopen( $file_name, 'rb' );
					if ( false === $file ) {
						$response = new WP_Error( 'fopen', 'Could not open the file for reading.' );
					} else {
						$file_size = filesize( $file_name );
						$file_data = fread( $file, $file_size );

						$args          = [
							'headers' => [
								'accept'       => 'application/json',   // The API returns JSON.
								'content-type' => 'application/binary', // Set content type to binary.
							],
							'body'    => $file_data,
							'timeout' => 5,
						];
						$response      = wp_remote_post( $this->config->get_tika_host(),
							$args );
						$response_code = wp_remote_retrieve_response_code( $response );
						if ( $response_code === 200 ) {
							$indexed = 1;
						}

					}
				} else {
					$response = new WP_Error( 'file_exists', 'Could not find attachment file.' );
				}


				$content = $this->handle_server_response( $response );
				if ( empty( $content ) && ! empty( $this->errors ) ) {
					$indexed      = 0;
					$this->errors = [];
				}

				$attachment_content    = $content;
				$attachment_cache_data = [
					'id'        => $attachment->ID,
					'indexed'   => $indexed,
					'parent_id' => $attachment->post_parent,
					'content'   => $content,
					'hash'      => $hash
				];
				$this->wpdb->replace( $this->table_prefix . 'sph_indexing_attachments', $attachment_cache_data );
			}


			return [
				'id'            => ManticoreSearch::set_sharding_id( $attachment->ID, $this->blog_id ),
				'blog_id'       => $this->blog_id,
				'comment_ID'    => 0,
				'post_ID'       => $attachment->ID,
				'title'         => $attachment->post_title,
				'body'          => $attachment->post_content . ' ' . $attachment_content,
				'category'      => 'null',
				'taxonomy'      => 'null',
				'custom_fields' => 'null',
				'isPost'        => 1,
				'isComment'     => 0,
				'isPage'        => 0,
				'post_type'     => 0,
				'date_added'    => $attachment->post_date,
				'tags'          => ''
			];
		}

		return [];
	}


	/**
	 * @param WP_Error|array $response
	 *
	 * @return string|null
	 * @throws JsonException
	 */
	private function handle_server_response( $response ): ?string {
		$success = null;
		$result  = '';
		if ( is_wp_error( $response ) ) {
			$this->errors[] = $response->get_error_message();
		} else {
			if ( isset( $response['body'] ) ) {
				$content = $response['body'];
				$content = json_decode( $content, true, 512, JSON_THROW_ON_ERROR );

				if ( isset( $content['status'] ) && $content['status'] === 'error' ) {
					$this->errors[] = $content['result'];
				} else {

					$result = $content['result'];
				}
			}
		}

		return $result;
	}


	/**
	 * @throws JsonException
	 */
	private function delete_from_main_index( $post_id, $blog_id ): void {
		$manticore_is_active = ManticoreSearch::$plugin->sphinxQL->is_active();

		if ( $manticore_is_active ) {

			$indexResult = ManticoreSearch::$plugin->sphinxQL
				->index( Manticore_Config_Maker::MAIN_INDEX_PREFIX . $blog_id )
				->delete( $post_id )
				->execute()
				->get_results();

			ManticoreSearch::$plugin->sphinxQL
				->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $blog_id )
				->delete( $post_id, 'post_id' )
				->execute()
				->get_results();
		}

		if ( $manticore_is_active === false
		     || ( ! empty( $indexResult ) &&
		          ( ( $indexResult['status'] === 'success' && $indexResult['results'] === false )
		            || $indexResult['status'] === 'error' ) ) ) {

			/** Error handling, saving to temp storage */
			$this->set_raw_data( 'delete', $blog_id, $post_id );
		}
	}

	/**
	 * @throws JsonException
	 */
	private function insert_to_main_index( $data, $blog_id ): void {
		$manticore_is_active = ManticoreSearch::$plugin->sphinxQL->is_active();
		if ( $manticore_is_active ) {
			$indexResult = ManticoreSearch::$plugin->sphinxQL
				->index( Manticore_Config_Maker::MAIN_INDEX_PREFIX . $blog_id )
				->insert( $data, [
					'title',
					'body',
					'category',
					'tags',
					'taxonomy',
					'custom_fields',
					'attachments'
				] )
				->execute()
				->get_results();

			$ac_sentences = $this->explode_sentences( $data );

			$chunked = array_chunk( $ac_sentences, self::INDEXING_SENTENCES_MAX_LIMIT );

			foreach ( $chunked as $chunk ) {
				ManticoreSearch::$plugin->sphinxQL
					->index( Manticore_Config_Maker::AUTOCOMPLETE_INDEX_PREFIX . $blog_id )
					->insert( $chunk, [
						'content',
						'string_content'
					] )
					->execute()
					->get_results();
			}

		}

		if ( $manticore_is_active === false ||
		     ( ! empty( $indexResult ) &&
		       ( ( $indexResult['status'] === 'success' && $indexResult['results'] === false )
		         || $indexResult['status'] === 'error' ) ) ) {

			/** Error handling, saving to temp storage */
			$this->set_raw_data( 'insert', $blog_id, $data );
		}
	}


	private function add_to_indexing_log( $data ): void {
		file_put_contents( $this->error_log_path, date( 'Y-m-d H:i:s' ) . ' ' . $data, FILE_APPEND );
	}

	private function delete_indexing_log(): void {
		if ( file_exists( $this->error_log_path ) ) {
			unlink( $this->error_log_path );
		}
	}

	public function my_error_handler( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		switch ( $errno ) {
			case E_USER_ERROR:
				$this->add_to_indexing_log(
					"<b>Error</b> [$errno] $errstr<br />\n" .
					"  Fatal in line $errline file $errfile" .
					", PHP " . PHP_VERSION . " (" . PHP_OS . ")<br />\n" .
					"Exiting...<br />\n" );
				exit( 1 );

			case E_USER_WARNING:
				$this->add_to_indexing_log(
					"<b>Warning</b> [$errno] $errstr<br />\n" );
				break;

			case E_USER_NOTICE:
				$this->add_to_indexing_log(
					"<b>Notice</b> [$errno] $errstr<br />\n" );
				break;

			default:
				$this->add_to_indexing_log(
					"Uncaught error: [$errno] $errfile:$errline $errstr<br />\n" );
				break;
		}

		return true;
	}

	public function update( SplSubject $subject ):void {
		$this->config = $subject;
	}
}
