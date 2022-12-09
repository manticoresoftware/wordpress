<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class Manticore_FrontEnd {
	/**
	 * Manticore Search Results
	 */
	private $search_results = [];

	/**
	 * Posts info returned by Sphinx
	 *
	 * @var array
	 */
	private $posts_info = array();

	/**
	 * Total posts found
	 *
	 * @var int
	 */
	public $post_count = 0;

	/**
	 *  Search keyword
	 *
	 * @var string
	 */
	private $search_string = '';

	/**
	 * Search keyword (as it was specified by user)
	 *
	 * @var string
	 */
	private $search_string_original = '';

	/**
	 * Search params
	 */
	public $params = [];

	/**
	 * Config object
	 */
	private $config = '';

	/**
	 * IS searchd running
	 */
	private $is_searchd_up = true;

	private $top_ten_is_related = false;

	/**
	 * IS search mode MATCH ANY
	 *
	 * @var boolean
	 */
	private $used_match_any = false;

	/**
	 * Post/Pages/Comments count variables
	 */
	private $posts_count = 0;
	private $pages_count = 0;
	private $comments_count = 0;

	/**
	 *
	 *
	 */
	private $_top_ten_total = 0;

	private $blog_id = 1;
	private $blog_urls = [];

	/**
	 * Delegate config object from SphinxSearch_Config class
	 * get search keyword from GET parameters
	 *
	 * @param Manticore_Config $config
	 *
	 */
	public function __construct( Manticore_Config $config ) {

		//initialize config
		$this->config = $config;

		if ( ! isset( $_GET['search_comments'] )
		     && ! isset( $_GET['search_posts'] )
		     && ! isset( $_GET['search_pages'] )
		     && ! isset( $_GET['search_tags'] )
		) {
			$this->params['search_comments'] = $this->config->admin_options['search_comments'] === 'false' ? '' : 'true';
			$this->params['search_posts']    = $this->config->admin_options['search_posts'] === 'false' ? '' : 'true';
			$this->params['search_pages']    = $this->config->admin_options['search_pages'] === 'false' ? '' : 'true';
			$this->params['search_tags']     = $this->config->admin_options['search_tags'] === 'false' ? '' : 'true';
		} else {
			$this->params['search_comments'] = isset( $_GET['search_comments'] ) ? esc_sql( $_GET['search_comments'] ) : false;
			$this->params['search_posts']    = isset( $_GET['search_posts'] ) ? esc_sql( $_GET['search_posts'] ) : false;
			$this->params['search_pages']    = isset( $_GET['search_pages'] ) ? esc_sql( $_GET['search_pages'] ) : false;
			$this->params['search_tags']     = isset( $_GET['search_tags'] ) ? esc_sql( $_GET['search_tags'] ) : false;
		}

		if ( $this->config->admin_options['search_sorting'] === 'user_defined' ) {

			if ( isset( $_GET['search_sortby_relevance'] ) && ! isset( $_GET['search_sortby_date'] ) ) {
				$this->params['search_sortby'] = 'relevance';
			} elseif ( ! isset( $_GET['search_sortby_relevance'] ) && isset( $_GET['search_sortby_date'] ) ) {
				$this->params['search_sortby'] = 'date';
			} else {
				$this->params['search_sortby'] = 'date_relevance';
			}

		} else {
			$this->params['search_sortby'] = $this->config->admin_options['search_sorting'];
		}

		$this->blog_id = get_current_blog_id();
		if ( ManticoreSearch::is_network() === 'true' ) {
			$this->blog_urls = ManticoreSearch::get_network_sites( false );
		}
	}

	/**
	 * Make Query to Sphinx search daemon and return result ids
	 *
	 * @param string $search_string
	 *
	 * @return array|$this
	 */
	public function query( string $search_string ) {

		global $wp_query;

		// checks weither SEO URLs are being used
		if ( $this->config->get_option( 'seo_url_all' ) === 'true' ) {
			$search_string = str_replace( '_', "'", $search_string );
		}

		$this->search_string_original = $search_string;
		$this->search_string          = $search_string;

		$manticore = ManticoreSearch::$plugin->sphinxQL;


		////////////
		// set filters
		////////////

		$typeFilters = [];

		if ( ! empty( $this->params['search_comments'] ) ) {
			$typeFilters['isComment'] = 1;
		}

		if ( ! empty( $this->params['search_pages'] ) ) {
			$typeFilters['isPage'] = 1;
		}

		if ( ! empty( $this->params['search_posts'] ) ) {
			$typeFilters['isPost'] = 1;
		}

		if ( ! empty( $typeFilters ) ) {
			$manticore->add_or_filter( $typeFilters );
		}

		if ( in_array( $this->params['search_sortby'], [ 'date', 'date_relevance' ] ) ) {

			if ( $this->params['search_sortby'] === 'date' ) {
				$this->params['search_sortby'] = 'date_added';
			}

			$manticore->sort( $this->params['search_sortby'] );
		}

		////////////
		// set limits
		////////////

		$search_page    = ( ! empty( $wp_query->query_vars['paged'] ) )
			? $wp_query->query_vars['paged'] : 1;
		$posts_per_page = (int) get_option( 'posts_per_page' );
		$offset         = (int) ( ( $search_page - 1 ) * $posts_per_page );
		$manticore->limits( $posts_per_page, $offset, $this->config->admin_options['sphinx_max_matches'] );

		////////////
		// do query
		////////////

		//replace key-buffer to key buffer
		//replace key -buffer to key -buffer
		//replace key- buffer to key buffer
		//replace key - buffer to key buffer
		$this->search_string = $this->unify_keywords( $this->search_string );

		$this->search_string = html_entity_decode( $this->search_string, ENT_QUOTES );

		$short_field_name = '';

		if ( strpos( $this->search_string, 'tax:' ) === 0 ) {

			$type_list        = $this->config->admin_options['taxonomy_indexing_fields'];
			$short_name       = 'tax:';
			$short_field_name = '@taxonomy ';
		}

		if ( strpos( $this->search_string, 'field:' ) === 0 ) {

			$type_list        = $this->config->admin_options['custom_fields_for_indexing'];
			$short_name       = 'field:';
			$short_field_name = '@custom_fields ';
		}

		if ( strpos( $this->search_string, 'tag:' ) === 0 ) {
			$tags_names = get_tags();
			$tags       = [];
			if ( ! empty( $tags_names ) ) {
				foreach ( $tags_names as $tag ) {
					$tags[] = $tag->slug;
				}
			}

			$type_list        = $tags;
			$short_name       = 'tag:';
			$short_field_name = '@tags ';
		}


		$originalQuery         = $this->search_string;
		$normalized_field_name = 'null';
		if ( ! empty( $type_list ) ) {
			foreach ( $type_list as $name ) {
				if ( strpos( $this->search_string, $short_name . $name ) !== false ) {

					$field_name            = $name;
					$normalized_field_name = Manticore_Indexing::normalize_key( $field_name );

					$this->search_string = str_replace( $short_name . $name, '', $this->search_string );

					$explodedRequest = explode( ' ', trim( $this->search_string ) );

					if ( ! empty( $explodedRequest[0] ) ) {

						if ( ! empty( $explodedRequest ) ) {
							foreach ( $explodedRequest as $k => $item ) {
								$explodedRequest[ $k ] = $normalized_field_name . $item;
							}
						}
						$this->search_string = implode( ' ', $explodedRequest );
					}

					break;
				}
			}

			$startUniqueString = Manticore_Indexing::STORED_KEY_RAND . $normalized_field_name . Manticore_Indexing::STORED_KEY_RAND . 'START';
			$endUniqueString   = Manticore_Indexing::STORED_KEY_RAND . $normalized_field_name . Manticore_Indexing::STORED_KEY_RAND . "END";

			$this->search_string = $short_field_name . $startUniqueString . ' << ' . $this->search_string . ' << ' . $endUniqueString;
		}

		$clonedManticore = clone $manticore;

		$res = $manticore
			->select()
			->index( Manticore_Config_Maker::MAIN_DISTRIBUTED_INDEX_PREFIX . $this->blog_id )
			->match( $this->search_string )
			->field_weights( $this->config->admin_options['weight_fields'] )
			->execute()
			->get_all();

		if ( empty( $res ) && $this->is_simple_query( $this->search_string ) ) {

			$explodedQuery = explode( ' ', $this->search_string );
			if ( ! empty( $explodedQuery[1] ) ) {
				$query = implode( ' | ', $explodedQuery );
			} else {
				$query = $this->search_string;
			}

			$res = $clonedManticore
				->select()
				->index( Manticore_Config_Maker::MAIN_DISTRIBUTED_INDEX_PREFIX . $this->blog_id )
				->match( $query )
				->field_weights( $this->config->admin_options['weight_fields'] )
				->execute()
				->get_all();

			$this->used_match_any = true;
		}
		$this->search_string = $originalQuery;

		//to do something useful with error
		if ( $res === false ) {
			$error = $clonedManticore->get_query_error();
			if ( false !== strpos( $error, "connection" )
			     && false !== strpos( $error, "failed" ) ) {
				$this->is_searchd_up = false;
			}

			return array();
		}


		////////////
		// try match any and save search string
		////////////
		$partial_keyword_match_or_adult_keyword = false;
		if ( $this->used_match_any === true ||
		     ( strtolower( $this->search_string ) !==
		       $this->clear_censor_keywords( $this->search_string ) ) ) {

			$partial_keyword_match_or_adult_keyword = true;
		}

		if ( $this->used_match_any ) {
			$meta = $clonedManticore->show_meta();
		} else {
			$meta = $manticore->show_meta();
		}


		unset( $clonedManticore );

		if ( ! empty( $_SERVER['HTTP_REFERER'] ) && strpos( $_SERVER['HTTP_REFERER'],
				$_SERVER['HTTP_HOST'] ) !== false ) {
			// make new query without filters
			if ( empty( $res ) ) {
				$this->used_match_any = false;

				$res_tmp = $manticore
					->select()
					->index( Manticore_Config_Maker::MAIN_DISTRIBUTED_INDEX_PREFIX . $this->blog_id )
					->match( $this->search_string )
					->field_weights( $this->config->admin_options['weight_fields'] )
					->limits( 1, 0 )
					->execute()
					->get_all();

				//to do something usefull with error
				if ( $res_tmp === false ) {
					$error = $manticore->get_query_error();
					if ( false !== strpos( $error, "connection" ) && false !== strpos( $error, "failed" ) ) {
						$this->is_searchd_up = false;
					}

					return array();
				}

				$meta = $manticore->show_meta();
				if ( is_array( $res_tmp ) && $partial_keyword_match_or_adult_keyword === false ) {
					$this->insert_sphinx_stats( $this->search_string );
				}
			} elseif ( $partial_keyword_match_or_adult_keyword === false ) {
				$this->insert_sphinx_stats( $this->search_string );
			}
		}


		//if no posts found return empty array
		if ( empty( $res ) ) {
			return [];
		}

		//group results
		$manticore->clear();

		$res_tmp = $manticore
			->select()
			->index( Manticore_Config_Maker::MAIN_DISTRIBUTED_INDEX_PREFIX . $this->blog_id )
			->match( $this->search_string )
			->field_weights( $this->config->admin_options['weight_fields'] )
			->limits( 1000, 0 )
			->group( 'post_type', 'count', 'desc' )
			->execute()
			->get_all();

		if ( ! empty( $res_tmp ) ) {
			foreach ( $res_tmp as $m ) {
				switch ( $m['post_type'] ) {
					case '0':
						$this->posts_count = $m['count'];
						break;
					case '1':
						$this->pages_count = $m['count'];
						break;
					case '2':
						$this->comments_count = $m['count'];
						break;
				}
			}

			//save matches
		}

		$this->search_results = $res;
		if ( ! empty( $meta ) ) {
			$this->post_count = $meta['total_found'];
		}

		return $this;
	}

	/**
	 * Is query simple, if yes we use match any mode if nothing found in extended mode
	 *
	 * @param string $query
	 *
	 * @return boolean
	 */
	public function is_simple_query( string $query ): bool {
		$stopWords = array( '@title', '@body', '@category', '!', '-', '~', '(', ')', '|', '"', '/' );
		foreach ( $stopWords as $st ) {
			if ( strpos( $query, $st ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parse matches and collect posts ids and comments ids
	 *
	 */
	public function parse_results(): Manticore_FrontEnd {
		$content = [ 'posts' => [] ];
		if ( ! empty( $this->search_results ) ) {
			foreach ( $this->search_results as $val ) {
				if ( empty( $val['comment_id'] ) ) {
					$content['posts'][] = [
						'post_id'    => $val['post_id'],
						'blog_id'    => $val['blog_id'],
						'weight'     => $val['weight'],
						'comment_id' => 0,
						'is_comment' => 0
					];
				} else {
					$content['posts'][] = [
						'post_id'    => $val['post_id'],
						'blog_id'    => $val['blog_id'],
						'weight'     => $val['weight'],
						'comment_id' => $val['comment_id'],
						'is_comment' => 1
					];
				}
			}
		}


		$this->posts_info = $content['posts'];

		return $this;
	}

	/**
	 * Make new posts based on our Manticore Search Results
	 *
	 * @return array $posts
	 */
	public function posts_results(): ?array {
		global $wpdb, $table_prefix;
		////////////////////////////
		//fetching comments and posts data
		////////////////////////////

		$posts_ids    = array();
		$comments_ids = array();
		foreach ( $this->posts_info as $p ) {
			if ( $p['is_comment'] ) {
				$comments_ids[ $p['blog_id'] ][]                          = $p['comment_id'];
				$comments_sorted_keys[ $p['blog_id'] . $p['comment_id'] ] = 1;
			}
			$posts_ids[ $p['blog_id'] ][]                       = $p['post_id'];
			$posts_sorted_keys[ $p['blog_id'] . $p['post_id'] ] = 1;
		}
		$posts_data = array();


		if ( ! empty( $posts_ids ) ) {
			$queries = [];
			/**
			 * @var int $blog_id
			 * @var array $post_id
			 */
			foreach ( $posts_ids as $blog_id => $blog_posts_ids ) {


				if ( $blog_id > 1 ) {
					$blog_prefix = preg_replace( '#_(\d+)_$#', '_' . $blog_id . '_', $table_prefix, 1 );

					$queries[] = 'SELECT *, ' . $blog_id . ' as blog_id FROM ' . $blog_prefix . 'posts wpposts' . $blog_id
					             . ' LEFT JOIN ' . $blog_prefix . 'sph_indexing_attachments att' . $blog_id
					             . ' ON wpposts' . $blog_id . '.ID = att' . $blog_id . '.id' .
					             ' WHERE  wpposts' . $blog_id . '.ID in (' . implode( ',', $blog_posts_ids ) . ')';
				} else {
					$queries[] = 'SELECT *, ' . $blog_id . ' as blog_id FROM ' . $wpdb->base_prefix . 'posts wp 
							LEFT JOIN ' . $wpdb->base_prefix . 'sph_indexing_attachments att 
							ON wp.ID = att.id 
							WHERE wp.ID in (' . implode( ',', $blog_posts_ids ) . ')';
				}


			}
			$query      = implode( ' UNION ', $queries );
			$posts_data = $wpdb->get_results( $query );

			$posts_sorted_keys = [];
			foreach ( $posts_data as $item ) {
				$posts_sorted_keys[ $item->blog_id . $item->ID ] = $item;
			}
			$posts_data = $posts_sorted_keys;
		}


		$comments_data = array();
		if ( ! empty( $comments_ids ) ) {
			$queries = [];
			foreach ( $comments_ids as $blog_id => $blog_comments_id ) {
				if ( $blog_id > 1 ) {
					$blog_prefix = preg_replace( '#_(\d+)_$#', '_' . $blog_id . '_', $table_prefix, 1 );

					$queries [] = 'SELECT *, ' . $blog_id . ' as blog_id FROM ' . $blog_prefix
					              . 'comments com' . $blog_id . ' WHERE com' . $blog_id . '.comment_ID in (' .
					              implode( ',', $blog_comments_id ) . ")";
				} else {
					$queries [] = 'SELECT *, ' . $blog_id . ' as blog_id FROM ' . $wpdb->base_prefix
					              . 'comments com WHERE com.comment_ID in (' . implode( ',', $blog_comments_id ) . ")";
				}
			}
			$query = implode( ' UNION ', $queries );

			$comments_data = $wpdb->get_results( $query );

			foreach ( $comments_data as $item ) {
				$comments_sorted_keys[ $item->blog_id . $item->comment_ID ] = $item;
			}
			$comments_data = $comments_sorted_keys;
		}

		unset( $posts_ids, $comments_ids );

		////////////////////////////
		//Make assoc array of
		//posts and comments data
		////////////////////////////

		$posts_content    = array();
		$posts_titles     = array();
		$posts_data_assoc = array();
		$comments_content = array();
		foreach ( $posts_data as $k => $p ) {
			//make id as indexes
			$posts_data_assoc[ $p->blog_id . $p->ID ] = $p;
			if ( $p->post_type === 'attachment' ) {
				$p->post_content .= PHP_EOL . $p->content;
			}
			$posts_content[ $p->blog_id . '|' . $p->ID ] = $p->post_content;
			$posts_titles[ $p->blog_id . '|' . $p->ID ]  = $p->post_title;
		}
		foreach ( $comments_data as $c ) {
			$comments_content[ $c->blog_id . '|' . $c->comment_ID ]                    = $c->comment_content;
			$comments_content_data[ $c->blog_id . $c->comment_ID ]['comment_date']     = $c->comment_date;
			$comments_content_data[ $c->blog_id . $c->comment_ID ]['comment_date_gmt'] = $c->comment_date_gmt;
			$comments_content_data[ $c->blog_id . $c->comment_ID ]['comment_author']   = $c->comment_author;
		}

		unset( $posts_data, $comments_data );

		////////////////////////////
		//excerpts of contents
		//and titles
		////////////////////////////

		$posts_content_excerpt    = $this->get_excerpt( $posts_content );
		$posts_titles_excerpt     = $this->get_excerpt( $posts_titles, true );
		$comments_content_excerpt = $this->get_excerpt( $comments_content );
		//check if server is down
//		if ( $posts_content_excerpt === [] && $posts_titles_excerpt === [] && $comments_content_excerpt === [] ) {
//			return null;
//		}

		unset( $posts_content, $posts_titles, $comments_content );
		////////////////////////////
		//merge posts and comments
		//excerpts into gloabl
		//posts array
		////////////////////////////

		$posts = array();
		foreach ( $this->posts_info as $post ) {
			$posts_data_assoc_array = array();
			$pID                    = $post['post_id'];
			$blogID                 = $post['blog_id'];
			if ( is_object( $posts_data_assoc[ $blogID . $pID ] ) ) {
				$posts_data_assoc_array[ $blogID . $pID ] = get_object_vars( $posts_data_assoc[ $blogID . $pID ] );
			}

			//it is comment
			if ( $post['is_comment'] ) {
				$cID = $post['comment_id'];

				$posts_data_assoc_array[ $blogID . $pID ]['post_content'] = $comments_content_excerpt[ $blogID . '|' . $cID ];
				$posts_data_assoc_array[ $blogID . $pID ]['post_excerpt'] = $comments_content_excerpt[ $blogID . '|' . $cID ];

				$posts_data_assoc_array[ $blogID . $pID ]['post_title']         = strip_tags( $posts_titles_excerpt[ $blogID . '|' . $pID ] );
				$posts_data_assoc_array[ $blogID . $pID ]['sphinx_post_title']  = $this->config->admin_options['before_comment'] . $posts_titles_excerpt[ $blogID . '|' . $pID ];
				$posts_data_assoc_array[ $blogID . $pID ]['comment_id']         = $cID;
				$posts_data_assoc_array[ $blogID . $pID ]['post_date_orig']     = $posts_data_assoc_array[ $blogID . $pID ]['post_date'];
				$posts_data_assoc_array[ $blogID . $pID ]['post_date_gmt_orig'] = $posts_data_assoc_array[ $blogID . $pID ]['post_date_gmt'];
				$posts_data_assoc_array[ $blogID . $pID ]['post_date']          = $comments_content_data[ $blogID . $cID ]['comment_date'];
				$posts_data_assoc_array[ $blogID . $pID ]['comment_author']     = $comments_content_data[ $blogID . $cID ]['comment_author'];
				$posts_data_assoc_array[ $blogID . $pID ]['comment_date']       = $comments_content_data[ $blogID . $cID ]['comment_date'];
				$posts[]                                                        = $posts_data_assoc_array[ $blogID . $pID ];
			} else {
				$posts_data_assoc_array[ $blogID . $pID ]['post_content'] = $posts_content_excerpt[ $blogID . '|' . $pID ];
				$posts_data_assoc_array[ $blogID . $pID ]['post_excerpt'] = $posts_content_excerpt[ $blogID . '|' . $pID ];
				if ( 'page' === $posts_data_assoc_array[ $blogID . $pID ]['post_type'] ) {
					$posts_data_assoc_array[ $blogID . $pID ]['post_title']        = strip_tags( $posts_titles_excerpt[ $blogID . '|' . $pID ] );
					$posts_data_assoc_array[ $blogID . $pID ]['sphinx_post_title'] = $this->config->admin_options['before_page'] . $posts_titles_excerpt[ $blogID . '|' . $pID ];
				} else {
					$posts_data_assoc_array[ $blogID . $pID ]['post_title']        = strip_tags( $posts_titles_excerpt[ $blogID . '|' . $pID ] );
					$posts_data_assoc_array[ $blogID . $pID ]['sphinx_post_title'] = $this->config->admin_options['before_post'] . $posts_titles_excerpt[ $blogID . '|' . $pID ];
				}
				$posts[] = $posts_data_assoc_array[ $blogID . $pID ];
			}
		}

		////////////////////////////
		//Convert posts array to
		//posts object required by WP
		////////////////////////////

		$obj_posts = array();
		foreach ( $posts as $index => $post ) {
			foreach ( $post as $var => $value ) {
				if ( ! isset( $obj_posts[ $index ] ) ) {
					$obj_posts[ $index ] = new stdClass();
				}
				$obj_posts[ $index ]->$var = $value;
			}

			if ( ! empty( $obj_posts[ $index ]->post_excerpt ) ) {
				$post_id                           = $obj_posts[ $index ]->ID;
				$blog_id                           = $obj_posts[ $index ]->blog_id;
				$content                           = $posts_data_assoc[ $blog_id . $post_id ]->post_content;
				$obj_posts[ $index ]->post_excerpt .= $this->get_bottom_links( $content,
					get_permalink( $obj_posts[ $index ]->ID ) );
			}
		}

		return $obj_posts;
	}


	public function get_bottom_links( $content, $url = '/' ): string {
		$links = '';

		$unique_links = [];
		preg_match_all( '#<h[1-6]+.{0,25}>(.{1,20})</h[1-6]+>#usi', $content, $matches );
		if ( ! empty( $matches[0][0] ) && ! empty( $matches[1][0] ) ) {
			$links .= '<div class="bottom-links">';
			foreach ( $matches[0] as $k => $match ) {
				if ( in_array( $matches[1][ $k ], $unique_links ) ) {
					continue;
				}
				$unique_links[] = $matches[1][ $k ];
				$links          .= '<a href="' . $url . '#s_' . $k . '">' . $matches[1][ $k ] . '</a> ';
			}
			$links .= '</div>';
		}


		return $links;
	}


	/**
	 * Return modified blog title
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	public function wp_title( string $title = '' ): string {
		return urldecode( $title );
	}

	/**
	 * Return modified post title
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	public function the_title( string $title = '' ): string {
		global $post;

		if ( ! is_search() || ! in_the_loop() ) {
			return $title;
		}

		return $post->sphinx_post_title;
	}

	/**
	 * Custom title Tag for post title
	 *
	 * @return string
	 */
	public function sphinx_the_title(): string {
		return the_title();
	}

	/**
	 * Replace post time to comment time
	 *
	 * @param string $the_time - post time
	 * @param string $d - time format
	 *
	 * @return string
	 */
	public function the_time( string $the_time, string $d ): string {
		global $post;
		if ( ! $post->comment_id ) {
			return $the_time;
		}
		if ( $d === '' ) {
			$the_time = date( get_option( 'time_format' ), strtotime( $post->comment_date ) );
		} else {
			$the_time = date( $d, strtotime( $post->comment_date ) );
		}

		return $the_time;
	}

	/**
	 * Replace post author name to comment author name
	 *
	 * @param string $display_name - post author name
	 *
	 * @return string
	 */
	public function the_author( string $display_name ): string {
		global $post;
		if ( empty( $post->comment_id ) ) {
			return $display_name;
		}

		return $post->comment_author;
	}

	/**
	 * Return modified permalink for comments
	 *
	 * @param string $permalink
	 *
	 * @return string
	 */
	public function the_permalink( string $permalink = '' ): string {
		global $post;

		if ( ! empty( $post->comment_id ) ) {
			return $permalink . '#comment-' . $post->comment_id;
		}

		return $permalink;
	}

	/**
	 * Correct date time for comment records in search results
	 *
	 * @param string $permalink
	 * @param object|null $post usually null so we use global post object
	 *
	 * @return string
	 */
	public function post_link( string $permalink, object $post = null ): string {
		global $post;

		if ( $post === null ) {
			return $permalink;
		}

		if ( $post !== null && empty( $post->comment_id ) ) {

			if ( ! empty( $post->blog_id ) && (int) $post->blog_id !== $this->blog_id ) {
				$permalink = str_replace( '/' . $this->blog_urls[ $this->blog_id ] . '/',
					'/' . $this->blog_urls[ $post->blog_id ] . '/', $permalink );
			}

			return $permalink;
		}

		$rewriteCode = array(
			'%year%',
			'%monthnum%',
			'%day%',
			'%hour%',
			'%minute%',
			'%second%',
			'%postname%',
			'%post_id%',
			'%category%',
			'%author%',
			'%pagename%'
		);

		$permalink = get_option( 'permalink_structure' );

		if ( '' !== $permalink && ! in_array( $post->post_status, array( 'draft', 'pending' ) ) ) {
			//Fix comment date to post date
			$unixtime = strtotime( $post->post_date_orig );

			$category = '';
			if ( strpos( $permalink, '%category%' ) !== false ) {
				$cats = get_the_category( $post->ID );
				if ( $cats ) {
					usort( $cats, '_usort_terms_by_ID' ); // order by ID
				}
				$category = $cats[0]->slug;
				if ( $parent = $cats[0]->parent ) {
					$category = get_category_parents( $parent, false, '/', true ) . $category;
				}
			}

			$authordata = get_userdata( $post->post_author );
			$author     = '';
			if ( is_object( $authordata ) ) {
				$author = $authordata->user_nicename;
			}
			$date           = explode( " ", date( 'Y m d H i s', $unixtime ) );
			$rewritereplace =
				array(
					$date[0],
					$date[1],
					$date[2],
					$date[3],
					$date[4],
					$date[5],
					$post->post_name,
					$post->ID,
					$category,
					$author,
					$post->post_name,
				);
			$permalink      = get_option( 'home' ) . str_replace( $rewriteCode, $rewritereplace, $permalink );
			$permalink      = user_trailingslashit( $permalink, 'single' );

			if ( ! empty( $post->blog_id ) && $post->blog_id != $this->blog_id ) {
				$permalink = str_replace( '/' . $this->blog_urls[ $this->blog_id ] . '/',
					'/' . $this->blog_urls[ $post->blog_id ] . '/', $permalink );
			}

			return $permalink;
		}

		// if they're not using the fancy permalink option
		$permalink = get_option( 'home' ) . '/?p=' . $post->ID;

		return $permalink;
	}

	/**
	 * Return Sphinx based Excerpts with highlitted words
	 *
	 * @param array $post_content keys of array is id numbers of search results
	 *                             can be as _title or empty
	 * @param string $isTitle it is postfix for array key, can be as 'title' for titles or FALSE for contents
	 *                             used to add tags around titles or contents
	 *
	 * @return array
	 */
	public function get_excerpt( array $post_content, $isTitle = false ): array {
		$sphinx = ManticoreSearch::$plugin->sphinxQL;

		$results_count = count( $this->search_results );
		if ( empty( $post_content ) ) {
			return [];
		}

		if ( $isTitle ) {
			$isTitle = "_title";
		}

		$around = $this->config->admin_options['excerpt_around'];
		$limit  = $this->config->admin_options['excerpt_limit'];

		if ( $this->config->admin_options['excerpt_dynamic_around'] === 'true' ) {
			/**
			 * If lot of results, then this value will dynamically decrease, but not less min limit
			 * If few results, this value will increase dynamically
			 */


			$values = explode( '-', $this->config->admin_options['excerpt_range'] );

			$min_around = (int) $values[0];
			$max_around = (int) $values[1];

			$around = $max_around - $results_count + 1;
			if ( $around < $min_around ) {
				$around = $min_around;
			}

			$limitValues = explode( '-', $this->config->admin_options['excerpt_range_limit'] );
			$min_limit   = (int) $limitValues[0];
			$max_limit   = (int) $limitValues[1];

			$limit = $max_limit - $results_count * 200;
			if ( $limit < $min_limit ) {
				$limit = $min_limit;
			}
		}


		$opts = [
			$limit . ' as limit',
			$around . ' as around',
			'\'' . $this->config->admin_options['excerpt_chunk_separator'] . '\' as chunk_separator',
			(int) $this->config->admin_options['passages-limit'] . ' as limit_passages',
			'\'{sphinx_after_match}\' as after_match',
			'\'{sphinx_before_match}\' as before_match'
		];

		$sphinx_after_match  = stripslashes( $this->config->admin_options[ 'after_' . ( $isTitle ? 'title' : 'text' ) . '_match' ] );
		$sphinx_before_match = stripslashes( $this->config->admin_options[ 'before_' . ( $isTitle ? 'title' : 'text' ) . '_match' ] );

		$excerpts_query = $this->clear_from_tags( $this->search_string_original );
		$excerpts_query = html_entity_decode( $excerpts_query, ENT_QUOTES );
		$excerpts_query = str_replace( "'", "\'", $excerpts_query );

		//strip html tags
		//strip user defined tag

		$blogs         = [];
		$keyed_results = [];

		foreach ( $post_content as $post_key => $post_value ) {
			$post_content[ $post_key ]                   = addslashes( $this->strip_udf_tags( $post_value, false ) );
			$blog_id                                     = explode( '|', $post_key );
			$blogs[ $blog_id[0] ][]                      = $post_content[ $post_key ];
			$keyed_results[ $blog_id[0] ][ $blog_id[1] ] = true;
			//$post_content_snippet[] = addslashes( $this->strip_udf_tags( $post_value, false ) );
		}

		$results = [];
		foreach ( $blogs as $blog_id => $content ) {

			$excerpts = $sphinx->call_snippets( $content, Manticore_Config_Maker::MAIN_INDEX_PREFIX . $blog_id,
				$excerpts_query, $opts );

			//to do something usefull with error
			// todo check om mysql
			if ( $excerpts === false ) {
				$error = $sphinx->get_query_error();
				if ( false !== strpos( $error, "connection" ) && false !== strpos( $error, "failed" ) ) {
					$this->is_searchd_up = false;
				}

				return [];
			}

			$results[ $blog_id ] = $excerpts;
		}


		foreach ( $keyed_results as $blog => $keys ) {
			$i = 0;
			foreach ( $keys as $key => $value ) {
				$keyed_results[ $blog ][ $key ] = $results[ $blog ][ $i ]['snippet'];
				$i ++;
			}
		}


		$i = 0;
		foreach ( $post_content as $k => $v ) {
			$blog_id = explode( '|', $k );

			if ( empty( $keyed_results[ $blog_id[0] ][ $blog_id[1] ] ) ) {
				continue;
			}
			$result = str_replace(
				[ '{sphinx_after_match}', '{sphinx_before_match}' ],
				[ $sphinx_after_match, $sphinx_before_match ],
				esc_html( $keyed_results[ $blog_id[0] ][ $blog_id[1] ] ) );


			$post_content[ $k ] = $result;
			$i ++;
		}

		return $post_content;
	}

	/**
	 * Clear content from user defined tags
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function the_content( string $content = '' ): string {
		$content = $this->strip_udf_tags( $content, false );

		return $content;
	}

	/**
	 * Strip html and user defined tags
	 *
	 * @param string $str
	 *
	 * @return string
	 */
	public function strip_udf_tags( string $str, $strip_tags = false ): string {
		if ( $strip_tags ) {
			$str = strip_tags( $str, $this->config->admin_options['excerpt_before_match'] .
			                         $this->config->admin_options['excerpt_after_match'] );
		}
		if ( ! empty( $this->config->admin_options['strip_tags'] ) ) {
			foreach ( explode( "\n", $this->config->admin_options['strip_tags'] ) as $tag ) {
				$tag = trim( $tag );
				if ( empty( $tag ) ) {
					continue;
				}
				$str = str_replace( $tag, '', $str );
			}
		}

		return $str;
	}

	public function get_search_string(): string {
		return $this->search_string_original;
	}

	/**
	 * Save statistic by about each search query
	 *
	 * @param string $keywords
	 *
	 * @return boolean
	 */
	public function insert_sphinx_stats( $keywords_full ): bool {
		global $wpdb, $table_prefix;

		if ( is_paged() || ManticoreSearch::sphinx_is_redirect_required( $this->config->get_option( 'seo_url_all' ) ) ) {
			return false;
		}

		$keywords      = $this->clear_from_tags( $keywords_full );
		$keywords      = trim( $keywords );
		$keywords_full = trim( $keywords_full );

		$sql    = "select status from {$table_prefix}sph_stats
                where keywords_full = '" . esc_sql( $keywords_full ) . "'
                    limit 1";
		$status = $wpdb->get_var( $sql );
		$status = ( int ) $status;

		$sql = $wpdb->prepare(
			"INSERT INTO {$table_prefix}sph_stats (keywords, keywords_full, date_added, status)
            VALUES ( %s, %s, NOW(), %d )
            ", $keywords, $keywords_full, $status );

		$wpdb->query( $sql );


		ManticoreSearch::$plugin->sphinxQL
			->index( Manticore_Config_Maker::STATS_INDEX_PREFIX . get_current_blog_id() )
			->insert( [
				[
					'id'           => $wpdb->insert_id,
					'keywords'     => $keywords,
					'status'       => $status,
					'keywords_crc' => crc32( $keywords ),
					'date_added'   => time()
				]
			], [ 'keywords' ] )->execute()->get_results();


		return true;
	}

	/**
	 * Return TOP-N popual search keywords
	 *
	 * @param integer $limit
	 * @param integer $width
	 * @param string $break
	 *
	 * @return array
	 */
	function sphinx_stats_top_ten( $limit = 10, $width = 0, $break = '...' ) {
		$keywords = $this->search_string_original;

		//try to get related results on search page
		if ( is_search() && ! empty( $keywords ) ) {
			$results = $this->sphinx_stats_related( $keywords, $limit, $width, $break );
			if ( ! empty( $results ) ) {
				return $results;
			}
		}
		$results = $this->sphinx_stats_top( $limit, $width, $break );

		return $results;
	}


	public function sphinx_stats_top(
		$limit = 10,
		$width = 0,
		$break = '...',
		$approved = false,
		$period_limit = 30,
		$start = 0
	) {
		global $wpdb, $table_prefix;

		$sphinx = ManticoreSearch::$plugin->sphinxQL;


		if ( $approved ) {
			$sphinx->add_filter( 'status', [ 1 ] );
		}

		if ( $period_limit ) {
			$minTime = strtotime( "-{$period_limit} days" );
			$sphinx->add_filter_range( 'date_added', $minTime, time() );
		}

		$res = $sphinx
			->select()
			->index( Manticore_Config_Maker::STATS_INDEX_PREFIX . get_current_blog_id() )
			->group( "keywords_crc", 'count', 'DESC' )
			->limits( $limit + 30, $start, $this->config->admin_options['sphinx_max_matches'] )
			->execute()
			->get_all();

		if ( empty( $res ) ) {
			return false;
		}
		foreach ( $res as $key => $item ) {
			$ids[] = $item['id'];
		}

		$this->_top_ten_total = count( $res );

		$sql     = "SELECT
                    distinct keywords_full,
                    keywords,
                    date_added
		FROM
                    {$table_prefix}sph_stats
                WHERE
                    id in (" . implode( ",", $ids ) . ")
                ORDER BY FIELD(id, " . implode( ",", $ids ) . ")
		LIMIT
                    " . ( $limit + 30 );
		$results = $wpdb->get_results( $sql );

		$results = $this->make_results_clear( $results, $limit, $width, $break );

		return $results;
	}

	function get_top_ten_total(): int {
		return $this->_top_ten_total;
	}

	public function sphinx_stats_related( $keywords, $limit = 10, $width = 0, $break = '...', $approved = false ) {
		global $wpdb, $table_prefix;

		$sphinx = ManticoreSearch::$plugin->sphinxQL;

		$explodedKeywords = explode( ' ', $keywords );
		if ( ! empty( $explodedKeywords[1] ) ) {
			$keywords = implode( ' | ', $explodedKeywords );
		}


		$keywords = $this->clear_keywords( $keywords );
		$keywords = $this->unify_keywords( $keywords );

		$keywords = explode( "\|", $keywords );

		$keywords = array_filter( $keywords, static function ( $word ) {
			$word = trim( $word );
			if ( $word === '' ) {
				return false;
			}

			return $word;
		} );

		$keywords = implode( '\|', $keywords );

		if ( $approved ) {
			$sphinx->add_filter( 'status', [ 1 ] );
		}

		$res = $sphinx
			->select()
			->index( Manticore_Config_Maker::STATS_INDEX_PREFIX . get_current_blog_id() )
			->group( "keywords_crc", 'weight', 'DESC' )
			->limits( $limit + 30, 0, $this->config->admin_options['sphinx_max_matches'] )
			->match( $keywords )
			->execute()
			->get_all();

		if ( empty( $res ) ) {
			return false;
		}
		foreach ( $res as $key => $item ) {
			$ids[] = $item['id'];
		}


		$sql = "SELECT
                    keywords,
                    keywords_full
                FROM
                    {$table_prefix}sph_stats
		        WHERE
                    id in (" . implode( ",", $ids ) . ")
                    and keywords_full != '" . trim( esc_sql( $keywords ) ) . "'
                ORDER BY FIELD(id, " . implode( ",", $ids ) . ")
		        LIMIT " . ( $limit + 30 );

		$results = $wpdb->get_results( $sql );

		$results = $this->make_results_clear( $results, $limit, $width, $break );

		return $results;
	}

	public function sphinx_stats_latest( $limit = 10, $width = 0, $break = '...', $approved = false ) {
		global $wpdb, $table_prefix;

		$sphinx = ManticoreSearch::$plugin->sphinxQL;

		if ( $approved ) {
			$sphinx->add_filter( 'status', [ 1 ] );
		}

		$res = $sphinx
			->select()
			->index( Manticore_Config_Maker::STATS_INDEX_PREFIX . get_current_blog_id() )
			->group( "keywords_crc", 'date_added', 'DESC' )
			->sort( 'date_added', 'DESC' )
			->limits( $limit + 30, 0, $this->config->admin_options['sphinx_max_matches'] )
			->execute()
			->get_all();

		if ( empty( $res ) ) {
			return false;
		}
		foreach ( $res as $key => $item ) {
			$ids[] = $item['id'];
		}

		$sql = "SELECT
                    distinct keywords_full,
                    keywords,
                    date_added
		FROM
                    {$table_prefix}sph_stats
                WHERE
                    id in (" . implode( ",", $ids ) . ")
                ORDER BY FIELD(id, " . implode( ",", $ids ) . ")
		LIMIT
                    " . ( $limit + 30 );

		$results = $wpdb->get_results( $sql );

		$results = $this->make_results_clear( $results, $limit, $width, $break );

		return $results;
	}

	public function make_results_clear( $results, $limit, $width = 0, $break = '...' ): array {
		$counter       = 0;
		$clear_results = array();

		foreach ( $results as $res ) {
			if ( $counter === $limit ) {
				break;
			}
			$keywords = $this->clear_censor_keywords( $res->keywords );
			if ( $keywords === strtolower( $res->keywords ) ) {
				$counter ++;
			} else {
				continue;
			}
			if ( $width && strlen( $res->keywords ) > $width ) {
				$res->keywords_cut = substr( $res->keywords, 0, $width ) . $break;
			} else {
				$res->keywords_cut = $res->keywords;
			}
			$clear_results[] = $res;
		}

		return $clear_results;
	}

	/**
	 * Is sphinx top ten is related
	 *
	 * @return boolean
	 */
	public function sphinx_stats_top_ten_is_related(): bool {
		return $this->top_ten_is_related;
	}

	/**
	 * Is sphinx daemon running
	 *
	 * @return boolean
	 */
	public function sphinx_is_up(): bool {
		return $this->is_searchd_up;
	}

	/**
	 * Remove non-valuable keywords from search string
	 *
	 * @param string $keywords
	 *
	 * @return string
	 */
	public function clear_keywords( string $keywords ): string {
		$temp = strtolower( trim( $keywords ) );

		$prepositions            = array(
			'aboard',
			'about',
			'above',
			'absent',
			'across',
			'after',
			'against',
			'along',
			'alongside',
			'amid',
			'amidst',
			'among',
			'amongst',
			'into ',
			'onto',
			'around',
			'as',
			'astride',
			'at',
			'atop',
			'before',
			'behind',
			'below',
			'beneath',
			'beside',
			'besides',
			'between',
			'beyond',
			'by',
			'despite',
			'down',
			'during',
			'except',
			'following',
			'for',
			'from',
			'in',
			'inside',
			'into',
			'like',
			'mid',
			'minus',
			'near',
			'nearest',
			'notwithstanding',
			'of',
			'off',
			'on',
			'onto',
			'opposite',
			'out',
			'outside',
			'over',
			'past',
			're',
			'round',
			'since',
			'through',
			'throughout',
			'till',
			'to',
			'toward',
			'towards',
			'under',
			'underneath',
			'unlike',
			'until',
			'up',
			'upon',
			'via',
			'with',
			'within',
			'without',
			'anti',
			'betwixt',
			'circa',
			'cum',
			'per',
			'qua',
			'sans',
			'unto',
			'versus',
			'vis-a-vis',
			'concerning',
			'considering',
			'regarding'
		);
		$twoWordPrepositions     = array(
			'according to',
			'ahead of',
			'as to',
			'aside from',
			'because of',
			'close to',
			'due to',
			'far from',
			'in to',
			'inside of',
			'instead of',
			'on to',
			'out of',
			'outside of',
			'owing to',
			'near to',
			'next to',
			'prior to',
			'subsequent to'
		);
		$threeWordPrepositions   = array(
			'as far as',
			'as well as',
			'by means of',
			'in accordance with',
			'in addition to',
			'in front of',
			'in place of',
			'in spite of',
			'on account of',
			'on behalf of',
			'on top of',
			'with regard to',
			'in lieu of'
		);
		$coordinatingConjuctions = array( 'for', 'and', 'nor', 'but', 'or', 'yet', 'so', 'not' );

		$articles = array( 'a', 'an', 'the', 'is', 'as' );

		$stopWords = array_merge( $prepositions, $twoWordPrepositions );
		$stopWords = array_merge( $stopWords, $threeWordPrepositions );
		$stopWords = array_merge( $stopWords, $coordinatingConjuctions );
		$stopWords = array_merge( $stopWords, $articles );
		foreach ( $stopWords as $k => $word ) {
			$stopWords[ $k ] = '/\b' . preg_quote( $word, null ) . '\b/';
		}

		$temp = preg_replace( $stopWords, ' ', $temp );
		$temp = str_replace( '"', ' ', $temp );
		$temp = preg_replace( '/\s+/', ' ', $temp );
		$temp = trim( $temp );

		return $temp;
	}

	public function clear_censor_keywords( string $keywords ): string {
		$temp = strtolower( trim( $keywords ) );

		if ( ! empty( $this->config->admin_options['censor_words'] ) ) {
			$censorWordsAdminOptions = explode( "\n", $this->config->admin_options['censor_words'] );
			foreach ( $censorWordsAdminOptions as $k => $v ) {
				$censorWordsAdminOptions[ $k ] = trim( $v );
			}

			$temp = preg_replace( $censorWordsAdminOptions, ' ', $temp );
		}


		$temp = str_replace( '"', ' ', $temp );
		$temp = preg_replace( '/\s+/', ' ', $temp );
		$temp = trim( $temp );

		return $temp;
	}

	/**
	 * Remove search tags from search keyword
	 *
	 * @param string $keywords
	 *
	 * @return string
	 */
	public function clear_from_tags( string $keywords ): string {
		$stopWords = array(
			'@title',
			'@body',
			'@category',
			'@tags',
			'@!title',
			'@!body',
			'@!category',
			'@!tags',
			'!',
			'-',
			'~',
			'(',
			')',
			'|',
			'@'
		);
		$keywords  = trim( str_replace( $stopWords, ' ', $keywords ) );

		if ( empty( $keywords ) ) {
			return '';
		}

		return trim( preg_replace( '/\s+/', ' ', $keywords ) );
	}

	public function get_type_count( string $type ): int {
		switch ( $type ) {
			case 'posts':
				return $this->posts_count;
			case 'pages':
				return $this->pages_count;
			case 'comments':
				return $this->comments_count;
			default:
				return 0;
		}
	}

	public function unify_keywords( string $keywords ) {
		//replace key-buffer to key buffer
		//replace key -buffer to key -buffer
		//replace key- buffer to key buffer
		//replace key - buffer to key buffer
		$keywords = preg_replace( "#([\w\S])\-([\w\S])#", "\$1 \$2", $keywords );
		$keywords = preg_replace( "#([\w\S])\s\-\s([\w\S])#", "\$1 \$2", $keywords );
		$keywords = preg_replace( "#([\w\S])-\s([\w\S])#", "\$1 \$2", $keywords );

		$from = array( '\\', '(', ')', '|', '!', '@', '~', '"', '&', '/', '^', '$', '=', "'" );
		$to   = array( '\\\\', '\(', '\)', '\|', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '' );

		$keywords = str_replace( $from, $to, $keywords );
		$keywords = str_ireplace( array( '\@title', '\@body', '\@category', '\@tags', '\@\!tags' ),
			array( '@title', '@body', '@category', '@tags', '@!tags' ), $keywords );

		return $keywords;
	}

}
