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
	 * Schedule first batch for a job.
	 *
	 * @param string $job_id Job ID.
	 */
	public function schedule_job( $job_id ) {
		$this->schedule_next( $job_id, 1 );
		$this->maybe_spawn_runner();
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
	 * Nudge Action Scheduler / WP-Cron to run soon after upload.
	 */
	private function maybe_spawn_runner() {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
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
	 * Process batches until time limit or job complete.
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

		$job = Boulk_UP_Import_Job::load( $job_id );
		if ( ! $job ) {
			return;
		}

		if ( $job->is_finished() ) {
			return;
		}

		if ( Boulk_UP_Import_Job::STATUS_CANCELLED === $job->get( 'status' ) ) {
			return;
		}

		$job->mark_running();

		$file_path = $job->get( 'file_path' );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			$job->mark_failed( __( 'Import file not found.', 'boulk-update-products' ) );
			return;
		}

		$this->begin_bulk_mode();

		try {
			$done = $this->process_until_time_limit( $job, $file_path );
		} finally {
			$this->end_bulk_mode();
		}

		if ( $done ) {
			$job->mark_complete();
			return;
		}

		$delay = Boulk_UP_Import_Config::get_schedule_delay( $job );
		$this->schedule_next( $job_id, $delay );
	}

	/**
	 * Process rows until time limit or all rows done.
	 *
	 * @param Boulk_UP_Import_Job $job       Job.
	 * @param string              $file_path CSV path.
	 * @return bool True if job finished.
	 */
	private function process_until_time_limit( $job, $file_path ) {
		$parser    = new Boulk_UP_CSV_Parser( $file_path );
		$init      = $parser->initialize();
		$time_limit = Boulk_UP_Import_Config::get_time_limit( $job );
		$batch_size = Boulk_UP_Import_Config::get_batch_size( $job );
		$started    = time();

		if ( is_wp_error( $init ) ) {
			$job->mark_failed( $init->get_error_message() );
			return true;
		}

		$update_fields = $job->get( 'update_fields', null );
		$updater       = new Boulk_UP_Product_Updater( $update_fields );
		$dry_run = (bool) $job->get( 'dry_run', false );
		$total   = (int) $job->get( 'total_rows', 0 );

		while ( true ) {
			if ( ( time() - $started ) >= $time_limit ) {
				break;
			}

			$offset = (int) $job->get( 'offset', 0 );
			if ( $offset >= $total ) {
				return true;
			}

			$rows = $parser->get_rows( $offset, $batch_size );
			if ( empty( $rows ) ) {
				return true;
			}

			foreach ( $rows as $data_index => $row ) {
				$row_number = $data_index + 2;
				$sku        = isset( $row['sku'] ) ? $row['sku'] : '';

				$result = $updater->process_row( $row, $row_number, $dry_run );

				$job->add_log( $row_number, $sku, $result['status'], $result['message'] );

				switch ( $result['status'] ) {
					case 'updated':
						$job->increment( 'updated' );
						break;
					case 'skipped':
						$job->increment( 'skipped' );
						break;
					case 'error':
						$job->increment( 'errors' );
						break;
				}

				$job->increment( 'processed' );
			}

			$new_offset = $offset + count( $rows );
			$job->set( 'offset', $new_offset );
			$job->save();

			if ( $new_offset >= $total ) {
				return true;
			}

			if ( ( time() - $started ) >= $time_limit ) {
				break;
			}
		}

		return (int) $job->get( 'offset', 0 ) >= $total;
	}

	/**
	 * Defer expensive WP/WC counting during bulk updates.
	 */
	private function begin_bulk_mode() {
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( true );
		}
	}

	/**
	 * Restore counting and flush product sync queue.
	 */
	private function end_bulk_mode() {
		if ( function_exists( 'wc_defer_product_sync' ) ) {
			wc_defer_product_sync( false );
		}
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
	}
}
