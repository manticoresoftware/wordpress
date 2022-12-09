<?php

/**
Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License version 3 or any later
version. You should have received a copy of the GPL license along with this
program; if you did not, you can find it at http://www.gnu.org/
 */

class StatsController {
	/**
	 * Special object to get/set plugin configuration parameters
	 * @access private
	 * @var Manticore_Config
	 *
	 */
	private $config;

	/**
	 * Special object used for template system
	 * @access private
	 * @var Manticore_View
	 *
	 */
	public $view;

	private $sphinx ;
	public $_results = [];

	public $_wpdb ;
	public $_table_prefix;
	public $_keywords_per_page = 50;

	public function __construct( Manticore_Config $config ) {
		global $wpdb, $table_prefix;

		$this->sphinx        = ManticoreSearch::$plugin->sphinxQL;
		$this->_wpdb         = $wpdb;
		$this->_table_prefix = $table_prefix;
		$this->view          = $config->get_view();
		$this->config        = $config;
		$this->view->assign( 'header', 'Manticore Search :: Statistics' );
	}

	public function index_action() {
		if ( isset( $_POST['apage'] ) ) {
			$page = abs( (int) $_POST['apage'] );
		} elseif ( isset( $_GET['apage'] ) ) {
			$page = abs( (int) $_GET['apage'] );
		} else {
			$page = 1;
		}
		$tab = ! empty( $_GET['tab'] ) ? $_GET['tab'] : 'stats';

		$period_param = ! empty( $_REQUEST['period'] ) ? (int) $_REQUEST['period'] : 7;

		$keywords = $this->_get_stat_keywords( $page, $period_param );
		//run after get keywords list
		$page_links = $this->_build_pagination( $page );

		$this->view->keywords = $keywords;
		$this->view->start    = ( $page - 1 ) * $this->_keywords_per_page;
		$this->view->sterm    = ! empty( $_REQUEST['sterm'] ) ? stripslashes( $_REQUEST['sterm'] ) : '';
		$this->view->period   = $period_param;

		$this->view->page_links        = $page_links;
		$this->view->page              = $page;
		$this->view->total             = ! empty( $this->_results['total_found'] ) ? $this->_results['total_found'] : 0;
		$this->view->keywords_per_page = $this->_keywords_per_page;

		$this->view->tab        = $tab;
		$this->view->plugin_url = $this->config->get_plugin_url();


	}

	public function _build_pagination( $page ) {
		if ( empty( $this->_results ) ) {
			return false;
		}
		//$sql_found_rows = 'SELECT FOUND_ROWS()';
		//$total = $this->_wpdb->get_var($sql_found_rows);

		$total = count($this->_results);

		$pagination = array(
			'base'      => add_query_arg( 'apage', '%#%' ),
			'format'    => '',
			'prev_text' => __( '&laquo;' ),
			'next_text' => __( '&raquo;' ),
			'total'     => ceil( $total / $this->_keywords_per_page ),
			'current'   => $page
		);

		if ( ! empty( $_REQUEST['period'] ) ) {
			$pagination['add_args']['period'] = $_REQUEST['period'];
		}
		if ( ! empty( $_REQUEST['sort_order'] ) ) {
			$pagination['add_args']['sort_order'] = $_REQUEST['sort_order'];
		}
		if ( ! empty( $_REQUEST['sort_by'] ) ) {
			$pagination['add_args']['sort_by'] = $_REQUEST['sort_by'];
		}

		return paginate_links( $pagination );
	}

	public function _get_stat_keywords( $page, $period_param ) {
		$start = ( $page - 1 ) * $this->_keywords_per_page;

		if ( $period_param > 0 ) {
			$this->sphinx->add_filter_range( "date_added", strtotime( "-{$period_param} days" ), time() );
		}

		$sort_order = 'desc';
		if ( ! empty( $_REQUEST['sort_order'] ) && strtolower( $_REQUEST['sort_order'] ) === 'asc' ) {
			$sort_order = 'asc';
		}

		$sort_by_param = ! empty( $_REQUEST['sort_by'] ) ? $_REQUEST['sort_by'] : 'date';
		switch ( strtolower( $sort_by_param ) ) {
			case 'cnt':
				$sort_by = 'count';
				break;
			case 'date':
			default:
				$sort_by = 'date_added';
				break;
		}

		$res = $this->sphinx
			->select()
			->index( Manticore_config_maker::STATS_INDEX_PREFIX . get_current_blog_id() )
			->limits( $this->_keywords_per_page, $start)
			->group("keywords_crc", $sort_by, $sort_order)
			->sort('date_added', $sort_order)
			->sort("count", $sort_order)
			->execute()
			->get_all();

		if ( empty( $res ) ) {
			return [];
		}

		$this->_results = $res;
		foreach ($res as $key=>$item){
			$ids[] = $item['id'];
		}

		$sql = 'select id, keywords,  date_added
            from ' . $this->_table_prefix . 'sph_stats
            where id in (' . implode( ',', $ids ) . ')
            order by FIELD(id, ' . implode( ',', $ids ) . ')';

		$keywords = $this->_wpdb->get_results( $sql, OBJECT_K );

		foreach ($res as $k => $match) {
			$keywords[$match['id']]->cnt = $match['count'];
		}

		return $keywords;
	}

}
