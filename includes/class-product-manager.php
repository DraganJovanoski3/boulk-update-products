<?php
/**
 * Product Manager list and bulk actions.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Product_Manager
 */
class Boulk_UP_Product_Manager {

	/**
	 * Active search term for posts_where filter.
	 *
	 * @var string
	 */
	private $search_term = '';

	const PER_PAGE_OPTIONS = array( 1000, 2000, 3000, 4000, 5000, 10000 );
	const DISPLAY_ALL_CAP  = 10000;
	const CHUNK_SIZE       = 5000;
	const SYNC_THRESHOLD   = 500;
	const SYNC_BATCH       = 100;
	const BACKGROUND_BATCH = 75;

	/**
	 * Allowed per-page values including "all".
	 *
	 * @return array<int|string, int|string>
	 */
	public static function get_per_page_options() {
		return array_merge( self::PER_PAGE_OPTIONS, array( 'all' => -1 ) );
	}

	/**
	 * Sanitize per_page from request.
	 *
	 * @param string|int $per_page Raw value.
	 * @return int -1 for all.
	 */
	public static function sanitize_per_page( $per_page ) {
		if ( 'all' === $per_page || -1 === (int) $per_page ) {
			return -1;
		}
		$per_page = (int) $per_page;
		return in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : 1000;
	}

	/**
	 * List products for the manager table.
	 *
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Rows per load (-1 = all with display cap).
	 * @param string $search   Search SKU or title.
	 * @param int    $chunk    Chunk index when loading "all" (0-based).
	 * @return array<string, mixed>
	 */
	public function list_products( $page = 1, $per_page = 1000, $search = '', $chunk = 0 ) {
		$page   = max( 1, (int) $page );
		$search = trim( $search );
		$chunk  = max( 0, (int) $chunk );

		$total = $this->count_products( $search );

		if ( -1 === $per_page ) {
			$effective_total = min( $total, self::DISPLAY_ALL_CAP );
			$offset          = $chunk * self::CHUNK_SIZE;
			$limit           = min( self::CHUNK_SIZE, max( 0, $effective_total - $offset ) );
			$from            = $effective_total > 0 && $limit > 0 ? $offset + 1 : 0;
			$to              = min( $offset + $limit, $effective_total );
			$capped          = $total > self::DISPLAY_ALL_CAP;
			$ids             = $limit > 0 ? $this->query_product_ids( $limit, $offset, $search ) : array();
		} else {
			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;
			$from   = $total > 0 ? $offset + 1 : 0;
			$to     = min( $offset + $limit, $total );
			$capped = false;
			$ids    = $this->query_product_ids( $limit, $offset, $search );
		}

		$rows = $this->hydrate_rows( $ids );

		$has_more = false;
		if ( -1 === $per_page ) {
			$effective_total = min( $total, self::DISPLAY_ALL_CAP );
			$has_more        = ( $chunk + 1 ) * self::CHUNK_SIZE < $effective_total;
		} else {
			$has_more = $page * $per_page < $total;
		}

		return array(
			'rows'        => $rows,
			'total'       => $total,
			'from'        => $from,
			'to'          => $to,
			'page'        => $page,
			'per_page'    => $per_page,
			'chunk'       => $chunk,
			'has_more'    => $has_more,
			'capped'      => $capped,
			'display_cap' => self::DISPLAY_ALL_CAP,
		);
	}

	/**
	 * Get all matching product IDs for select-all.
	 *
	 * @param string $search Search term.
	 * @return array{ids: int[], total: int}
	 */
	public function get_all_matching_ids( $search = '' ) {
		$total = $this->count_products( $search );
		$ids   = $this->query_product_ids( $total > 0 ? $total : 1, 0, $search );

		return array(
			'ids'   => $ids,
			'total' => count( $ids ),
		);
	}

	/**
	 * List duplicate product groups (same SKU + regular price).
	 *
	 * @param int    $page     Page number (1-based).
	 * @param int    $per_page Groups per page.
	 * @param string $search   Filter by SKU or product name.
	 * @return array<string, mixed>
	 */
	public function list_duplicate_groups( $page = 1, $per_page = 50, $search = '' ) {
		$page     = max( 1, (int) $page );
		$per_page = max( 10, min( 200, (int) $per_page ) );
		$search   = trim( $search );

		$total_groups = $this->count_duplicate_groups( $search );
		$offset       = ( $page - 1 ) * $per_page;
		$keys         = $this->query_duplicate_group_keys( $per_page, $offset, $search );

		$groups = array();
		foreach ( $keys as $key ) {
			$products = $this->get_duplicate_group_products( $key['sku'], $key['regular_price'] );
			$groups[] = array(
				'sku'            => $key['sku'],
				'regular_price'  => $key['regular_price'],
				'count'          => count( $products ),
				'keep_id'        => ! empty( $products ) ? (int) $products[0]['id'] : 0,
				'products'       => $products,
			);
		}

		return array(
			'groups'               => $groups,
			'total_groups'         => $total_groups,
			'duplicate_products'   => $this->count_duplicate_products( $search ),
			'page'                 => $page,
			'per_page'             => $per_page,
			'from'                 => $total_groups > 0 ? $offset + 1 : 0,
			'to'                   => min( $offset + count( $groups ), $total_groups ),
			'has_more'             => $page * $per_page < $total_groups,
		);
	}

	/**
	 * Product IDs that are duplicate copies (keep lowest ID per SKU + price group).
	 *
	 * @param string $search Filter by SKU or name.
	 * @return array{ids: int[], total: int}
	 */
	public function get_duplicate_extra_ids( $search = '' ) {
		$search = trim( $search );
		$keys   = $this->query_duplicate_group_keys( 5000, 0, $search );
		$extra  = array();

		foreach ( $keys as $key ) {
			$products = $this->get_duplicate_group_products( $key['sku'], $key['regular_price'] );
			if ( count( $products ) < 2 ) {
				continue;
			}
			array_shift( $products );
			foreach ( $products as $product ) {
				$extra[] = (int) $product['id'];
			}
		}

		$extra = array_values( array_unique( $extra ) );
		if ( count( $extra ) > 10000 ) {
			$extra = array_slice( $extra, 0, 10000 );
		}

		return array(
			'ids'   => $extra,
			'total' => count( $extra ),
		);
	}

	/**
	 * Count duplicate groups (SKU + regular price).
	 *
	 * @param string $search Search filter.
	 * @return int
	 */
	private function count_duplicate_groups( $search = '' ) {
		global $wpdb;

		$sql = $this->build_duplicate_groups_sql( $search, false );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$sql}) AS dup_groups" );
	}

	/**
	 * Count products that belong to a duplicate group.
	 *
	 * @param string $search Search filter.
	 * @return int
	 */
	private function count_duplicate_products( $search = '' ) {
		global $wpdb;

		$sql = $this->build_duplicate_groups_sql( $search, false );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(cnt), 0) FROM ({$sql}) AS dup_groups" );
	}

	/**
	 * Fetch duplicate group keys for pagination.
	 *
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @param string $search Search.
	 * @return array<int, array{sku: string, regular_price: string, cnt: int}>
	 */
	private function query_duplicate_group_keys( $limit, $offset, $search = '' ) {
		global $wpdb;

		$sql   = $this->build_duplicate_groups_sql( $search, true );
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( $row ) {
				return array(
					'sku'            => (string) $row['sku'],
					'regular_price'  => (string) $row['regular_price'],
					'cnt'            => (int) $row['cnt'],
				);
			},
			$rows
		);
	}

	/**
	 * Build SQL for duplicate SKU + regular price groups.
	 *
	 * @param string $search  Search filter.
	 * @param bool   $ordered Include ORDER BY.
	 * @return string
	 */
	private function build_duplicate_groups_sql( $search = '', $ordered = true ) {
		global $wpdb;

		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$search_sql = '';
		$search_arg = trim( $search );
		if ( '' !== $search_arg ) {
			$like        = '%' . $wpdb->esc_like( $search_arg ) . '%';
			$search_sql  = $wpdb->prepare(
				" AND (sku.meta_value LIKE %s OR p.post_title LIKE %s)",
				$like,
				$like
			);
		}

		$sql = $wpdb->prepare(
			"SELECT sku.meta_value AS sku, IFNULL(price.meta_value, '') AS regular_price, COUNT(DISTINCT p.ID) AS cnt
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
			LEFT JOIN {$wpdb->postmeta} price ON p.ID = price.post_id AND price.meta_key = '_regular_price'
			WHERE p.post_type = 'product'
			AND p.post_status IN ({$in})
			AND TRIM(sku.meta_value) <> ''
			{$search_sql}
			GROUP BY sku.meta_value, IFNULL(price.meta_value, '')
			HAVING cnt > 1",
			...$statuses
		);

		if ( $ordered ) {
			$sql .= ' ORDER BY cnt DESC, sku ASC';
		}

		return $sql;
	}

	/**
	 * Products in one duplicate group.
	 *
	 * @param string $sku           SKU.
	 * @param string $regular_price Regular price meta value.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_duplicate_group_products( $sku, $regular_price ) {
		global $wpdb;

		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $wpdb->prepare(
			"SELECT p.ID AS id, p.post_title AS name, p.post_date AS created, p.post_status AS status,
				IFNULL(sale.meta_value, '') AS sale_price,
				IFNULL(stock.meta_value, '') AS stock_status
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku' AND sku.meta_value = %s
			LEFT JOIN {$wpdb->postmeta} price ON p.ID = price.post_id AND price.meta_key = '_regular_price'
			LEFT JOIN {$wpdb->postmeta} sale ON p.ID = sale.post_id AND sale.meta_key = '_sale_price'
			LEFT JOIN {$wpdb->postmeta} stock ON p.ID = stock.post_id AND stock.meta_key = '_stock_status'
			WHERE p.post_type = 'product'
			AND p.post_status IN ({$in})
			AND IFNULL(price.meta_value, '') = %s
			ORDER BY p.ID ASC",
			...array_merge( array( $sku ), $statuses, array( $regular_price ) )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$products = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			$products[] = array(
				'id'            => $id,
				'sku'           => $sku,
				'name'          => $row['name'],
				'regular_price' => $regular_price,
				'sale_price'    => $row['sale_price'],
				'stock_status'  => $row['stock_status'],
				'status'        => $row['status'],
				'created'       => $row['created'],
				'edit_url'      => get_edit_post_link( $id, 'raw' ),
				'is_keeper'     => false,
			);
		}

		if ( ! empty( $products ) ) {
			$products[0]['is_keeper'] = true;
		}

		return $products;
	}

	/**
	 * Bulk update products (sync or queue job).
	 *
	 * @param int[]                $ids     Product IDs.
	 * @param array<string, mixed> $payload regular_price, sale_price, stock_status.
	 * @return array<string, mixed>
	 */
	public function bulk_update( array $ids, array $payload ) {
		$ids = $this->sanitize_ids( $ids );
		if ( empty( $ids ) ) {
			return array( 'success' => false, 'message' => __( 'No products selected.', 'boulk-update-products' ) );
		}

		$payload = $this->sanitize_update_payload( $payload );
		if ( empty( $payload ) ) {
			return array( 'success' => false, 'message' => __( 'Enter at least one field to update.', 'boulk-update-products' ) );
		}

		if ( count( $ids ) > self::SYNC_THRESHOLD ) {
			$job = Boulk_UP_Bulk_Action_Job::create( Boulk_UP_Bulk_Action_Job::TYPE_UPDATE, $ids, $payload );
			Boulk_UP_Bulk_Action_Processor::schedule( $job->get_id() );
			return array(
				'success' => true,
				'queued'  => true,
				'job_id'  => $job->get_id(),
				'total'   => count( $ids ),
				'message' => __( 'Bulk update started in the background.', 'boulk-update-products' ),
			);
		}

		$result = $this->process_update_ids( $ids, $payload );
		return array_merge(
			array( 'success' => true, 'queued' => false ),
			$result
		);
	}

	/**
	 * Bulk trash products.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<string, mixed>
	 */
	public function bulk_delete( array $ids ) {
		$ids = $this->sanitize_ids( $ids );
		if ( empty( $ids ) ) {
			return array( 'success' => false, 'message' => __( 'No products selected.', 'boulk-update-products' ) );
		}

		if ( count( $ids ) > self::SYNC_THRESHOLD ) {
			$job = Boulk_UP_Bulk_Action_Job::create( Boulk_UP_Bulk_Action_Job::TYPE_DELETE, $ids, array() );
			Boulk_UP_Bulk_Action_Processor::schedule( $job->get_id() );
			return array(
				'success' => true,
				'queued'  => true,
				'job_id'  => $job->get_id(),
				'total'   => count( $ids ),
				'message' => __( 'Bulk delete started in the background.', 'boulk-update-products' ),
			);
		}

		$result = $this->process_delete_ids( $ids );
		return array_merge(
			array( 'success' => true, 'queued' => false ),
			$result
		);
	}

	/**
	 * Process update for ID list.
	 *
	 * @param int[]                $ids     IDs.
	 * @param array<string, mixed> $payload Fields.
	 * @return array{succeeded: int, failed: int}
	 */
	public function process_update_ids( array $ids, array $payload ) {
		$this->begin_bulk_mode();

		$succeeded = 0;
		$failed    = 0;
		$touched   = array();

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				++$failed;
				continue;
			}

			try {
				if ( isset( $payload['regular_price'] ) ) {
					$product->set_regular_price( $payload['regular_price'] );
				}
				if ( isset( $payload['sale_price'] ) ) {
					$product->set_sale_price( $payload['sale_price'] );
				}
				if ( isset( $payload['stock_status'] ) ) {
					$product->set_stock_status( $payload['stock_status'] );
					$product->set_manage_stock( false );
				}
				$product->save();
				$touched[ $product_id ] = true;
				++$succeeded;
			} catch ( Exception $e ) {
				++$failed;
			}
		}

		$this->flush_transients( $touched );
		$this->end_bulk_mode();

		return array(
			'succeeded' => $succeeded,
			'failed'    => $failed,
		);
	}

	/**
	 * Trash products by ID.
	 *
	 * @param int[] $ids IDs.
	 * @return array{succeeded: int, failed: int}
	 */
	public function process_delete_ids( array $ids ) {
		$succeeded = 0;
		$failed    = 0;

		foreach ( $ids as $product_id ) {
			if ( wp_trash_post( $product_id ) ) {
				++$succeeded;
			} else {
				++$failed;
			}
		}

		return array(
			'succeeded' => $succeeded,
			'failed'    => $failed,
		);
	}

	/**
	 * Count products matching search.
	 *
	 * @param string $search Search.
	 * @return int
	 */
	private function count_products( $search ) {
		$this->maybe_add_search_filter( $search );

		$q = $this->build_query_args( 1, 0 );
		$q['fields']                 = 'ids';
		$q['posts_per_page']         = 1;
		$q['no_found_rows']          = false;
		$q['update_post_meta_cache'] = false;
		$q['update_post_term_cache'] = false;

		$query = new WP_Query( $q );
		$this->remove_search_filter();

		return (int) $query->found_posts;
	}

	/**
	 * Query product IDs.
	 *
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @param string $search Search.
	 * @return int[]
	 */
	private function query_product_ids( $limit, $offset, $search ) {
		$this->maybe_add_search_filter( $search );

		$q = $this->build_query_args( $limit, $offset );
		$q['fields']                 = 'ids';
		$q['no_found_rows']          = true;
		$q['update_post_meta_cache'] = false;
		$q['update_post_term_cache'] = false;

		$query = new WP_Query( $q );
		$this->remove_search_filter();

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Build WP_Query args.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return array<string, mixed>
	 */
	private function build_query_args( $limit, $offset ) {
		return array(
			'post_type'              => 'product',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => $limit,
			'offset'                 => $offset,
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
	}

	/**
	 * @param string $search Search term.
	 */
	private function maybe_add_search_filter( $search ) {
		$this->search_term = trim( $search );
		if ( '' !== $this->search_term ) {
			add_filter( 'posts_where', array( $this, 'filter_posts_where_search' ), 10, 2 );
		}
	}

	private function remove_search_filter() {
		remove_filter( 'posts_where', array( $this, 'filter_posts_where_search' ), 10 );
		$this->search_term = '';
	}

	/**
	 * Search product title or SKU.
	 *
	 * @param string   $where WHERE clause.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public function filter_posts_where_search( $where, $query ) {
		if ( '' === $this->search_term || 'product' !== $query->get( 'post_type' ) ) {
			return $where;
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( $this->search_term ) . '%';

		$where .= $wpdb->prepare(
			" AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = {$wpdb->posts}.ID
				AND pm.meta_key = '_sku'
				AND pm.meta_value LIKE %s
			))",
			$like,
			$like
		);

		return $where;
	}

	/**
	 * Hydrate table rows from product IDs.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrate_rows( array $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}

		$rows = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			$rows[] = array(
				'id'            => $id,
				'sku'           => $product->get_sku(),
				'name'          => $product->get_name(),
				'regular_price' => $product->get_regular_price(),
				'sale_price'    => $product->get_sale_price(),
				'stock_status'  => $product->get_stock_status(),
				'status'        => $product->get_status(),
				'edit_url'      => get_edit_post_link( $id, 'raw' ),
			);
		}

		return $rows;
	}

	/**
	 * @param int[] $ids Raw IDs.
	 * @return int[]
	 */
	private function sanitize_ids( array $ids ) {
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_update_payload( array $payload ) {
		$clean = array();

		if ( isset( $payload['regular_price'] ) && '' !== (string) $payload['regular_price'] ) {
			$clean['regular_price'] = wc_format_decimal( $payload['regular_price'] );
		}
		if ( isset( $payload['sale_price'] ) && '' !== (string) $payload['sale_price'] ) {
			$clean['sale_price'] = wc_format_decimal( $payload['sale_price'] );
		}
		if ( ! empty( $payload['stock_status'] ) ) {
			$status = sanitize_key( $payload['stock_status'] );
			if ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$clean['stock_status'] = $status;
			}
		}

		return $clean;
	}

	/**
	 * @param array<int, bool> $ids Touched product IDs.
	 */
	private function flush_transients( $ids ) {
		foreach ( array_keys( $ids ) as $product_id ) {
			wc_delete_product_transients( $product_id );
		}
	}

	private function begin_bulk_mode() {
		wp_defer_term_counting( true );
		wp_suspend_cache_addition( true );
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( true );
		}
	}

	private function end_bulk_mode() {
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( false );
		}
		wp_suspend_cache_addition( false );
		wp_defer_term_counting( false );
	}
}

/**
 * Background processor for bulk action jobs.
 */
class Boulk_UP_Bulk_Action_Processor {

	/**
	 * Register hook.
	 */
	public static function init() {
		add_action( Boulk_UP_Bulk_Action_Job::HOOK, array( __CLASS__, 'process' ), 10, 1 );
	}

	/**
	 * Schedule job processing.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function schedule( $job_id ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				Boulk_UP_Bulk_Action_Job::HOOK,
				array( 'job_id' => $job_id ),
				'boulk-update-products'
			);
		} else {
			wp_schedule_single_event( time(), Boulk_UP_Bulk_Action_Job::HOOK, array( $job_id ) );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
			try {
				ActionScheduler_QueueRunner::instance()->run( 5 );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}
	}

	/**
	 * Process one batch tick.
	 *
	 * @param array<string, string>|string $args Args.
	 */
	public static function process( $args ) {
		if ( is_array( $args ) && isset( $args['job_id'] ) ) {
			$job_id = $args['job_id'];
		} else {
			$job_id = (string) $args;
		}

		$job = Boulk_UP_Bulk_Action_Job::load( $job_id );
		if ( ! $job || $job->is_finished() ) {
			return;
		}

		$job->mark_running();

		$ids     = $job->get_product_ids();
		$offset  = (int) $job->get( 'offset', 0 );
		$batch   = array_slice( $ids, $offset, Boulk_UP_Product_Manager::BACKGROUND_BATCH );
		$manager = new Boulk_UP_Product_Manager();

		if ( empty( $batch ) ) {
			$job->mark_complete();
			return;
		}

		if ( Boulk_UP_Bulk_Action_Job::TYPE_DELETE === $job->get( 'type' ) ) {
			$result = $manager->process_delete_ids( $batch );
		} else {
			$payload = is_array( $job->get( 'payload' ) ) ? $job->get( 'payload' ) : array();
			$result  = $manager->process_update_ids( $batch, $payload );
		}

		$job->increment( 'processed', count( $batch ) );
		$job->increment( 'succeeded', $result['succeeded'] );
		$job->increment( 'failed', $result['failed'] );
		$job->set( 'offset', $offset + count( $batch ) );
		$job->save();

		if ( $job->get( 'offset' ) >= count( $ids ) ) {
			$job->mark_complete();
			return;
		}

		self::schedule( $job_id );
	}

	/**
	 * Job status for AJAX polling.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_status( $job_id ) {
		$job = Boulk_UP_Bulk_Action_Job::load( $job_id );
		if ( ! $job ) {
			return null;
		}

		return array(
			'id'        => $job->get_id(),
			'type'      => $job->get( 'type' ),
			'status'    => $job->get( 'status' ),
			'processed' => (int) $job->get( 'processed', 0 ),
			'total'     => (int) $job->get( 'total', 0 ),
			'succeeded' => (int) $job->get( 'succeeded', 0 ),
			'failed'    => (int) $job->get( 'failed', 0 ),
			'percent'   => $job->get_progress_percent(),
			'finished'  => $job->is_finished(),
		);
	}
}
