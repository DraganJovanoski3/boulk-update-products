<?php
/**
 * Background batch processor for import jobs.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Batch_Processor
 */
class Boulk_UP_Batch_Processor {

	/**
	 * Singleton.
	 *
	 * @var Boulk_UP_Batch_Processor|null
	 */
	private static $instance = null;

	/**
	 * Product IDs updated this tick (flush transients once).
	 *
	 * @var int[]
	 */
	private $touched_product_ids = array();

	/**
	 * Get instance.
	 *
	 * @return Boulk_UP_Batch_Processor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( BOULK_UP_BATCH_HOOK, array( $this, 'process_batch' ), 10, 1 );
		add_action( BOULK_UP_CRON_HOOK, array( $this, 'process_batch' ), 10, 1 );
	}

	/**
	 * Schedule first batch for a job (runs immediately when file is small).
	 *
	 * @param string $job_id Job ID.
	 */
	public function schedule_job( $job_id ) {
		$job = Boulk_UP_Import_Job::load( $job_id );
		if ( ! $job ) {
			return;
		}

		$threshold = Boulk_UP_Import_Config::get_inline_threshold();
		$total     = (int) $job->get( 'total_rows', 0 );

		if ( $total > 0 && $total <= $threshold ) {
			$this->run_inline( $job_id, 120 );
			return;
		}

		$this->process_batch( array( 'job_id' => $job_id ) );
		$this->run_action_scheduler_queue();
	}

	/**
	 * Process entire small import in the current request.
	 *
	 * @param string $job_id       Job ID.
	 * @param int    $max_seconds  Time budget.
	 */
	private function run_inline( $job_id, $max_seconds ) {
		$started = time();
		$job     = Boulk_UP_Import_Job::load( $job_id );
		$chains  = $job ? Boulk_UP_Import_Config::get_chain_ticks( $job ) + 4 : 12;

		while ( ( time() - $started ) < $max_seconds && $chains > 0 ) {
			$job = Boulk_UP_Import_Job::load( $job_id );
			if ( ! $job || $job->is_finished() ) {
				return;
			}

			$done = $this->run_single_tick( $job );
			if ( $done ) {
				return;
			}
			--$chains;
		}

		$job = Boulk_UP_Import_Job::load( $job_id );
		if ( $job && ! $job->is_finished() ) {
			$this->schedule_next( $job_id, 0 );
			$this->run_action_scheduler_queue();
		}
	}

	/**
	 * Schedule next batch.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $delay  Delay in seconds.
	 */
	public function schedule_next( $job_id, $delay = 0 ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delay,
				BOULK_UP_BATCH_HOOK,
				array( 'job_id' => $job_id ),
				'boulk-update-products'
			);
			return;
		}

		wp_schedule_single_event( time() + $delay, BOULK_UP_CRON_HOOK, array( $job_id ) );
	}

	/**
	 * Run pending Action Scheduler actions for this plugin.
	 */
	private function run_action_scheduler_queue() {
		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) ) {
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
			return;
		}

		try {
			ActionScheduler_QueueRunner::instance()->run( 10 );
		} catch ( Exception $e ) {
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Cancel pending batches for a job.
	 *
	 * @param string $job_id Job ID.
	 */
	public function cancel_job( $job_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( BOULK_UP_BATCH_HOOK, array( 'job_id' => $job_id ), 'boulk-update-products' );
		}
		wp_clear_scheduled_hook( BOULK_UP_CRON_HOOK, array( $job_id ) );
	}

	/**
	 * Process one or more batch ticks.
	 *
	 * @param array<string, string>|string $args Job ID or args array.
	 */
	public function process_batch( $args ) {
		if ( is_array( $args ) && isset( $args['job_id'] ) ) {
			$job_id = $args['job_id'];
		} else {
			$job_id = (string) $args;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		$job    = Boulk_UP_Import_Job::load( $job_id );
		$chains = $job ? Boulk_UP_Import_Config::get_chain_ticks( $job ) : Boulk_UP_Import_Config::get_chain_ticks();
		$done   = false;

		for ( $i = 0; $i < $chains; $i++ ) {
			$job = Boulk_UP_Import_Job::load( $job_id );
			if ( ! $job || $job->is_finished() ) {
				$done = true;
				break;
			}

			if ( Boulk_UP_Import_Job::STATUS_CANCELLED === $job->get( 'status' ) ) {
				$done = true;
				break;
			}

			$done = $this->run_single_tick( $job );
			if ( $done ) {
				break;
			}
		}

		if ( $done ) {
			return;
		}

		$this->schedule_next( $job_id, Boulk_UP_Import_Config::get_schedule_delay( Boulk_UP_Import_Job::load( $job_id ) ) );
		$this->run_action_scheduler_queue();
	}

	/**
	 * Run a single processing tick for a job.
	 *
	 * @param Boulk_UP_Import_Job $job Job.
	 * @return bool True when job is finished.
	 */
	private function run_single_tick( $job ) {
		$job->mark_running();

		$file_path = $job->get( 'file_path' );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$job->mark_failed( __( 'Import file not found.', 'boulk-update-products' ) );
			return true;
		}

		$this->touched_product_ids = array();
		$this->begin_bulk_mode();

		try {
			$done = $this->process_until_time_limit( $job, $file_path );
		} finally {
			$this->flush_product_transients();
			$this->end_bulk_mode();
		}

		if ( $done ) {
			$job->mark_complete();
		}

		return $done;
	}

	/**
	 * Process rows until time limit or all rows done.
	 *
	 * @param Boulk_UP_Import_Job $job       Job.
	 * @param string              $file_path CSV path.
	 * @return bool True if job finished.
	 */
	private function process_until_time_limit( $job, $file_path ) {
		$parser = new Boulk_UP_CSV_Parser( $file_path );

		$column_map = $job->get( 'csv_column_map', array() );
		$delimiter  = $job->get( 'csv_delimiter', ',' );

		if ( ! empty( $column_map ) && is_array( $column_map ) ) {
			$parser->load_state( $column_map, $delimiter, (int) $job->get( 'total_rows', 0 ) );
		} else {
			$init = $parser->initialize();
			if ( is_wp_error( $init ) ) {
				$job->mark_failed( $init->get_error_message() );
				return true;
			}
			$job->set( 'csv_column_map', $parser->get_column_map() );
			$job->set( 'csv_delimiter', $parser->get_delimiter() );
		}

		$time_limit    = Boulk_UP_Import_Config::get_time_limit( $job );
		$batch_size    = Boulk_UP_Import_Config::get_batch_size( $job );
		$rows_per_run  = Boulk_UP_Import_Config::get_rows_per_run( $job );
		$started       = time();
		$rows_this_tick = 0;
		$update_fields = $job->get( 'update_fields', null );
		$updater = new Boulk_UP_Product_Updater( $update_fields );
		$dry_run        = (bool) $job->get( 'dry_run', false );
		$total          = (int) $job->get( 'total_rows', 0 );
		$file_offset    = (int) $job->get( 'file_offset', 0 );
		$row_index      = (int) $job->get( 'row_index', 0 );

		while ( true ) {
			if ( ( time() - $started ) >= $time_limit ) {
				break;
			}

			if ( $row_index >= $total ) {
				$job->save();
				return true;
			}

			if ( $rows_per_run > 0 && $rows_this_tick >= $rows_per_run ) {
				$job->save();
				return false;
			}

			$chunk_limit = $rows_per_run > 0 ? 1 : $batch_size;
			$chunk       = $parser->read_chunk( $file_offset, $row_index, $chunk_limit );
			$rows        = $chunk['rows'];

			if ( empty( $rows ) ) {
				$job->save();
				return true;
			}

			foreach ( $rows as $data_index => $row ) {
				$row_number = $data_index + 2;
				$sku        = Boulk_UP_Update_Fields::resolve_sku( $row );

				$result = $updater->process_row( $row, $row_number, $dry_run );

				$job->add_log( $row_number, $sku, $result['status'], $result['message'] );

				switch ( $result['status'] ) {
					case 'updated':
						$job->increment( 'updated' );
						if ( ! empty( $result['product_id'] ) ) {
							$this->touched_product_ids[ (int) $result['product_id'] ] = true;
						}
						break;
					case 'created':
						$job->increment( 'created' );
						if ( ! empty( $result['product_id'] ) ) {
							$this->touched_product_ids[ (int) $result['product_id'] ] = true;
						}
						break;
					case 'skipped':
						$job->increment( 'skipped' );
						break;
					case 'error':
						$job->increment( 'errors' );
						break;
				}

				$job->increment( 'processed' );
				++$rows_this_tick;

				if ( $rows_per_run > 0 && $rows_this_tick >= $rows_per_run ) {
					$file_offset = $chunk['next_offset'];
					$row_index   = $chunk['next_row_index'];
					$job->set( 'file_offset', $file_offset );
					$job->set( 'row_index', $row_index );
					$job->save();

					return $row_index >= $total;
				}
			}

			$file_offset = $chunk['next_offset'];
			$row_index   = $chunk['next_row_index'];
			$job->set( 'file_offset', $file_offset );
			$job->set( 'row_index', $row_index );
			$job->save();

			if ( $row_index >= $total ) {
				return true;
			}

			if ( ( time() - $started ) >= $time_limit ) {
				break;
			}
		}

		return $row_index >= $total;
	}

	/**
	 * Flush transients for products touched this tick.
	 */
	private function flush_product_transients() {
		foreach ( array_keys( $this->touched_product_ids ) as $product_id ) {
			wc_delete_product_transients( $product_id );
		}
	}

	/**
	 * Defer expensive WP/WC counting during bulk updates.
	 */
	private function begin_bulk_mode() {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_addition( true );
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( true );
		}
	}

	/**
	 * Restore counting and flush product sync queue.
	 */
	private function end_bulk_mode() {
		wp_suspend_cache_addition( false );
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( false );
		}
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
	}
}
