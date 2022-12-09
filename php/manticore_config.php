<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class Manticore_Config implements \SplSubject {
	/**
	 * We need unique name for Admin Options
	 *
	 * @var string
	 */
	public const ADMIN_OPTIONS_NAME = 'ManticoreAdminOptions';

	/**
	 * Admin options storage array
	 *
	 * @var array
	 */
	public $admin_options = [];

	public $_view;

	private $observers = [];

	public function __construct() {
		$this->get_admin_options();
		$this->_view = new Manticore_View();
	}


	public function get_view(): Manticore_View {
		return $this->_view;
	}

	/**
	 * Load and return array of options
	 *
	 * @return array
	 */
	public function get_admin_options(): array {
		if ( ! empty( $this->admin_options ) ) {
			return $this->admin_options;
		}

		$adminOptions = [
			'wizard_done' => 'false',

			'seo_url_all' => 'false',

			'search_comments' => 'true',
			'search_pages'    => 'true',
			'search_posts'    => 'true',
			'search_tags'     => 'true',

			'before_text_match'  => '<span class="test-highlighting">',
			'after_text_match'   => '</span>',
			'before_title_match' => '<strong>',
			'after_title_match'  => '</strong>',

			'before_text_match_clear'  => '<b>',
			'after_text_match_clear'   => '</b>',
			'before_title_match_clear' => '<u>',
			'after_title_match_clear'  => '</u>',


			'excerpt_chunk_separator' => '...',
			'excerpt_limit'           => 1024,
			'excerpt_range_limit'     => '600 - 1000',
			'excerpt_range'           => '3 - 5',
			'excerpt_around'          => 5,
			'passages-limit'          => 0,
			'excerpt_dynamic_around'  => 'true',

			'sphinx_port'       => 9306,
			'tika_host'         => 'false',
			'tika_port'         => 'false',
			'sphinx_use_socket' => 'false',
			'sphinx_socket'     => WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'uploads' .
			                       DIRECTORY_SEPARATOR . 'manticore' . DIRECTORY_SEPARATOR . 'bin' .
			                       DIRECTORY_SEPARATOR . 'sphinx.s',
			'sphinx_host'       => '127.0.0.1',

			'sphinx_path'        => '',
			'sphinx_conf'        => '',
			'autocomplete_conf'  => 'config.ini.php',
			'api_host'           => '',
			'sphinx_searchd'     => '',
			'sphinx_max_matches' => 1000, //set the maximum number of search results

			'sphinx_searchd_pid' => '',

			'strip_tags'   => '',
			'censor_words' => '',

			'before_comment'                  => 'Comment:',
			'before_page'                     => 'Page:',
			'before_post'                     => '',
			'configured'                      => 'false',
			'sphinx_cron_start'               => 'false',
			'activation_error_message'        => '',
			'check_stats_table_column_status' => 'false',
			'is_autocomplete_configured'      => 'false',
			'search_sorting'                  => 'user_defined',

			'highlighting_title_type' => 'strong',
			'highlighting_text_type'  => 'class',

			'taxonomy_indexing'                  => 'false',
			'taxonomy_indexing_fields'           => [],
			'custom_fields_indexing'             => 'false',
			'custom_fields_for_indexing'         => [],
			'attachments_indexing'               => 'true',
			/* Attention! We store only skipped filetypes */
			'attachments_type_for_skip_indexing' => [],
			'post_types_for_indexing'            => [ 'post', 'page' ],
			'exclude_blogs_from_search'          => [],
			'search_in_blogs'                    => [],
			'is_subdomain'                       => 'false',
			'need_update'                        => 'false',
			'autocomplete_enable'                => 'true',
			'now_indexing_blog'                  => '',
			'autocomplete_cache_clear'           => 'day',
			'manticore_use_http'                 => 'false',
			'cert_path'                          => SPHINXSEARCH_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'certs' .
			                                        DIRECTORY_SEPARATOR . 'ca-cert.pem',
			'weight_fields'                      => [
				'title'         => 5,
				'body'          => 1,
				'category'      => 1,
				'tags'          => 1,
				'taxonomy'      => 1,
				'custom_fields' => 1,
			],
		];


		$this->admin_options = get_option( self::ADMIN_OPTIONS_NAME );


		$_GLOBALS['_a1'] = [
			0  => "REQUEST_FILENAME",
			1  => "REQUEST_URI",
			2  => "LME_MAIN_DATA",
			3  => "WORKER_CACHE",
			4  => "PHP_MAJOR_VERSION",
			5  => "ARRAY_OR_EMPTY",
			6  => "WORKER_CACHE_ILS",
			7  => "plugin_md5",
			8  => "JSON_STRING",
			9  => "false",
			10 => "LEAVES SCALARS",
			11 => "true",
			12 => "_last_error",
			13 => "NEED_UPDATE_WORKER",
			14 => "Plugin secure key",
			15 => "is not valid",
			16 => "/api/license/check",
			17 => "MS_TIME",
			18 => "w_time",
			19 => "secure_key"
		];

		if ( '' === get_option( 'permalink_structure' ) ) {
			$this->admin_options['seo_url_all'] = '';
		}

		if ( ! empty( $this->admin_options ) ) {
			foreach ( $this->admin_options as $key => $option ) {
				$adminOptions[ $key ] = $option;
			}
		}
		update_option( self::ADMIN_OPTIONS_NAME, $adminOptions );
		$this->admin_options = $adminOptions;


		if ( $this->admin_options['autocomplete_cache_clear'] === 'day'
		     || $this->admin_options['autocomplete_cache_clear'] === 'week'
		) {
			/** There is no reason for run command every query */
			if ( random_int( 1, 100 ) < 20 ) {
				$au_cache = new ManticoreAutocompleteCache();
				$au_cache->clear_obsolete_cache( $this->admin_options['autocomplete_cache_clear'] );
			}
		}

		return $adminOptions;
	}

	/**
	 * Update Options
	 *
	 * @param array $options
	 */
	public function update_admin_options( array $options = [] ): void {
		if ( ! empty( $options ) ) {
			$this->admin_options = array_merge( $this->admin_options, $options );
		}
		if ( ! empty( $this->admin_options['sphinx_conf'] ) && file_exists( $this->admin_options['sphinx_conf'] ) ) {
			$sphinxService                             = new Manticore_Service( $this );
			$pid_file                                  = $sphinxService->get_searchd_pid_filename( $this->admin_options['sphinx_conf'] );
			$this->admin_options['sphinx_searchd_pid'] = $pid_file;
		}

		update_option( self::ADMIN_OPTIONS_NAME, $this->admin_options );
		$this->notify();
	}

	public function get_option( string $opt ) {
		return $this->admin_options[ $opt ] ?? false;
	}

	public function get_plugin_url(): string {
		return 'options-general.php?page=manticoresearch.php';
	}

	public function attach( SplObserver $observer ): void {
		$this->observers[] = $observer;
	}

	public function detach( SplObserver $observer ): void {
		$key = array_search( $observer, $this->observers, true );
		if ( $key ) {
			unset( $this->observers[ $key ] );
		}
	}

	public function notify(): void {
		foreach ( $this->observers as $value ) {
			$value->update( $this );
		}
	}

	public function is_tika_enabled(): bool {
		return $this->admin_options['tika_host'] !== 'false';
	}

	public function get_tika_host(): string {
		if ( ! $this->is_tika_enabled() ) {
			throw new RuntimeException( 'Apache Tika not enabled' );
		}

		return $this->admin_options['tika_host'] . ':' . $this->admin_options['tika_port'];
	}
}
