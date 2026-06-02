<?php
/**
 * Import job storage and management.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Import_Job
 */
class Boulk_UP_Import_Job {

	const OPTION_PREFIX = 'boulk_up_job_';
	const LIST_OPTION   = 'boulk_up_job_ids';

	/**
	 * Job statuses.
	 */
	const STATUS_QUEUED   = 'queued';
	const STATUS_RUNNING  = 'running';
	const STATUS_COMPLETE = 'complete';
	const STATUS_FAILED   = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Job ID.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Job data.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string               $id   Job ID.
	 * @param array<string, mixed> $data Job data.
	 */
	public function __construct( $id, $data = array() ) {
		$this->id   = $id;
		$this->data = $data;
	}

	/**
	 * Create a new import job.
	 *
	 * @param string $source_file Path to uploaded CSV.
	 * @param bool   $dry_run     Whether this is a dry run.
	 * @param string   $profile       Import performance profile.
	 * @param string[] $update_fields  Fields to update (empty = all).
	 * @param bool     $create_missing Create products when SKU not found.
	 * @return Boulk_UP_Import_Job|WP_Error
	 */
	public static function create( $source_file, $dry_run = false, $profile = '', $update_fields = array(), $create_missing = false ) {
		self::ensure_upload_dir();

		$id = 'job_' . wp_generate_password( 12, false, false );

		$parser = new Boulk_UP_CSV_Parser( $source_file );
		$result = $parser->initialize();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$dest = self::get_upload_dir() . '/' . $id . '.csv';
		if ( ! copy( $source_file, $dest ) ) {
			return new WP_Error( 'boulk_copy_failed', __( 'Could not store CSV file for import.', 'boulk-update-products' ) );
		}

		$profile       = Boulk_UP_Import_Config::sanitize_profile( $profile );
		$update_fields = Boulk_UP_Update_Fields::sanitize_selection( $update_fields );

		if ( empty( $update_fields ) ) {
			return new WP_Error(
				'boulk_no_fields',
				__( 'Select at least one field to update.', 'boulk-update-products' )
			);
		}

		$total_rows = $parser->get_total_rows();
		$profile    = Boulk_UP_Import_Config::auto_profile( $total_rows, $profile );

		$job = new self(
			$id,
			array(
				'id'             => $id,
				'status'         => self::STATUS_QUEUED,
				'dry_run'        => (bool) $dry_run,
				'profile'        => $profile,
				'update_fields'  => $update_fields,
				'create_missing' => (bool) $create_missing,
				'file_path'      => $dest,
				'csv_column_map' => $parser->get_column_map(),
				'csv_delimiter'  => $parser->get_delimiter(),
				'file_offset'    => 0,
				'row_index'      => 0,
				'total_rows'     => $total_rows,
				'processed'      => 0,
				'updated'        => 0,
				'created'        => 0,
				'skipped'        => 0,
				'errors'         => 0,
				'offset'       => 0,
				'created_at'   => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
				'completed_at' => null,
				'log_entries'  => array(),
			)
		);

		$job->save();

		$ids = get_option( self::LIST_OPTION, array() );
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}
		array_unshift( $ids, $id );
		$ids = array_slice( $ids, 0, 100 );
		update_option( self::LIST_OPTION, $ids, false );

		return $job;
	}

	/**
	 * Load job by ID.
	 *
	 * @param string $id Job ID.
	 * @return Boulk_UP_Import_Job|null
	 */
	public static function load( $id ) {
		$data = get_option( self::OPTION_PREFIX . $id, null );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return new self( $id, $data );
	}

	/**
	 * List recent jobs.
	 *
	 * @param int $limit Max jobs.
	 * @return Boulk_UP_Import_Job[]
	 */
	public static function list_jobs( $limit = 20 ) {
		$ids  = get_option( self::LIST_OPTION, array() );
		$jobs = array();

		if ( ! is_array( $ids ) ) {
			return $jobs;
		}

		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$job = self::load( $id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}

	/**
	 * Get upload directory for imports.
	 *
	 * @return string
	 */
	public static function get_upload_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'boulk-imports';
	}

	/**
	 * Ensure upload directory exists.
	 */
	public static function ensure_upload_dir() {
		$dir = self::get_upload_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "deny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Save job data.
	 */
	public function save() {
		$this->data['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_PREFIX . $this->id, $this->data, false );
	}

	/**
	 * Get job ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get all job data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Get a data field.
	 *
	 * @param string $key Field key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}

	/**
	 * Set a data field.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	/**
	 * Increment counter.
	 *
	 * @param string $key Counter key.
	 * @param int    $by  Amount.
	 */
	public function increment( $key, $by = 1 ) {
		$current = isset( $this->data[ $key ] ) ? (int) $this->data[ $key ] : 0;
		$this->data[ $key ] = $current + $by;
	}

	/**
	 * Append log entry.
	 *
	 * @param int    $row_number CSV row number (1-based, including header offset).
	 * @param string $sku        Product SKU.
	 * @param string $status     updated|skipped|error.
	 * @param string $message    Log message.
	 */
	public function add_log( $row_number, $sku, $status, $message = '' ) {
		$sku = '' !== trim( $sku ) ? trim( $sku ) : __( '(empty SKU)', 'boulk-update-products' );

		$entry = array(
			'row'     => $row_number,
			'sku'     => $sku,
			'status'  => $status,
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		);

		if ( ! isset( $this->data['log_entries'] ) || ! is_array( $this->data['log_entries'] ) ) {
			$this->data['log_entries'] = array();
		}
		$this->data['log_entries'][] = $entry;

		if ( count( $this->data['log_entries'] ) > 100 ) {
			$this->data['log_entries'] = array_slice( $this->data['log_entries'], -100 );
		}

		if ( 'error' === $status ) {
			$this->append_issue_cache( 'error_entries', $entry );
		} elseif ( 'skipped' === $status ) {
			$this->append_issue_cache( 'skipped_entries', $entry );
		}

		// Successful updates are counted only — skip disk writes for speed.
		if ( 'updated' === $status ) {
			return;
		}

		$this->append_log_file( $row_number, $sku, $status, $message );

		if ( in_array( $status, array( 'error', 'skipped', 'created' ), true ) ) {
			if ( in_array( $status, array( 'error', 'skipped' ), true ) ) {
				$this->append_status_log_file( $status, $row_number, $sku, $message );
			}
		}
	}

	/**
	 * Keep recent error/skipped entries in job data for live UI updates.
	 *
	 * @param string               $key   error_entries|skipped_entries.
	 * @param array<string, mixed> $entry Log entry.
	 */
	private function append_issue_cache( $key, $entry ) {
		if ( ! isset( $this->data[ $key ] ) || ! is_array( $this->data[ $key ] ) ) {
			$this->data[ $key ] = array();
		}
		$this->data[ $key ][] = $entry;
		if ( count( $this->data[ $key ] ) > 200 ) {
			$this->data[ $key ] = array_slice( $this->data[ $key ], -200 );
		}
	}

	/**
	 * Append to log file on disk.
	 *
	 * @param int    $row_number Row number.
	 * @param string $sku        SKU.
	 * @param string $status     Status.
	 * @param string $message    Message.
	 */
	private function append_log_file( $row_number, $sku, $status, $message ) {
		$log_path = self::get_upload_dir() . '/' . $this->id . '-log.csv';
		$exists   = file_exists( $log_path );

		$handle = fopen( $log_path, 'ab' );
		if ( ! $handle ) {
			return;
		}

		if ( ! $exists ) {
			fputcsv( $handle, array( 'row', 'sku', 'status', 'message', 'time' ) );
		}

		fputcsv( $handle, array( $row_number, $sku, $status, $message, current_time( 'mysql' ) ) );
		fclose( $handle );
	}

	/**
	 * Append errors or skipped rows to a dedicated CSV file.
	 *
	 * @param string $status     error|skipped.
	 * @param int    $row_number Row number.
	 * @param string $sku        SKU.
	 * @param string $message    Message.
	 */
	private function append_status_log_file( $status, $row_number, $sku, $message ) {
		$path   = $this->get_status_log_path( $status );
		$exists = file_exists( $path );

		$handle = fopen( $path, 'ab' );
		if ( ! $handle ) {
			return;
		}

		if ( ! $exists ) {
			fputcsv( $handle, array( 'row', 'sku', 'reason', 'time' ) );
		}

		fputcsv( $handle, array( $row_number, $sku, $message, current_time( 'mysql' ) ) );
		fclose( $handle );
	}

	/**
	 * Get full log file path.
	 *
	 * @return string|null
	 */
	public function get_log_file_path() {
		$path = self::get_upload_dir() . '/' . $this->id . '-log.csv';
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Get errors or skipped log file path.
	 *
	 * @param string $status error|skipped|all.
	 * @return string|null
	 */
	public function get_status_log_path( $status ) {
		if ( 'all' === $status ) {
			return $this->get_log_file_path();
		}

		if ( ! in_array( $status, array( 'error', 'skipped' ), true ) ) {
			return null;
		}

		$path = self::get_upload_dir() . '/' . $this->id . '-' . $status . 's.csv';
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Read error or skipped log entries from disk.
	 *
	 * @param string $status error|skipped.
	 * @param int    $limit  Max rows to read.
	 * @return array<int, array{row: int|string, sku: string, message: string, time: string}>
	 */
	public function read_status_log( $status, $limit = 500 ) {
		$path = $this->get_status_log_path( $status );
		if ( $path ) {
			$entries = $this->read_csv_log_file( $path, $status, $limit, false );
			if ( ! empty( $entries ) ) {
				return $entries;
			}
		}

		$full_log = $this->get_log_file_path();
		if ( $full_log ) {
			$entries = $this->read_csv_log_file( $full_log, $status, $limit, true );
			if ( ! empty( $entries ) ) {
				return $entries;
			}
		}

		return $this->get_cached_issues( $status, $limit );
	}

	/**
	 * Read rows from a CSV log file.
	 *
	 * @param string $path           File path.
	 * @param string $status         Filter status for full log.
	 * @param int    $limit          Max rows.
	 * @param bool   $filter_by_status Whether CSV has a status column to filter.
	 * @return array<int, array<string, mixed>>
	 */
	private function read_csv_log_file( $path, $status, $limit, $filter_by_status ) {
		$entries = array();
		$handle  = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return $entries;
		}

		fgetcsv( $handle );

		while ( ( $data = fgetcsv( $handle ) ) !== false && count( $entries ) < $limit ) {
			if ( empty( $data ) ) {
				continue;
			}

			if ( $filter_by_status ) {
				$row_status = isset( $data[2] ) ? $data[2] : '';
				if ( $row_status !== $status ) {
					continue;
				}
				$entries[] = array(
					'row'     => isset( $data[0] ) ? $data[0] : '',
					'sku'     => isset( $data[1] ) ? $data[1] : '',
					'message' => isset( $data[3] ) ? $data[3] : '',
					'time'    => isset( $data[4] ) ? $data[4] : '',
					'status'  => $status,
				);
			} else {
				$entries[] = array(
					'row'     => isset( $data[0] ) ? $data[0] : '',
					'sku'     => isset( $data[1] ) ? $data[1] : '',
					'message' => isset( $data[2] ) ? $data[2] : '',
					'time'    => isset( $data[3] ) ? $data[3] : '',
					'status'  => $status,
				);
			}
		}

		fclose( $handle );
		return $entries;
	}

	/**
	 * Get cached issue entries from job option (fallback / live preview).
	 *
	 * @param string $status error|skipped.
	 * @param int    $limit  Max entries.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_cached_issues( $status, $limit = 200 ) {
		$key = 'error' === $status ? 'error_entries' : 'skipped_entries';
		$entries = $this->get( $key, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_slice( $entries, -$limit );
	}

	/**
	 * Count lines in a status log file (excluding header).
	 *
	 * @param string $status error|skipped.
	 * @return int
	 */
	public function count_status_log( $status ) {
		$path = $this->get_status_log_path( $status );
		if ( ! $path ) {
			$key = 'error' === $status ? 'error_entries' : 'skipped_entries';
			$cached = $this->get( $key, array() );
			return is_array( $cached ) ? count( $cached ) : 0;
		}

		$count  = 0;
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return 0;
		}

		fgetcsv( $handle );
		while ( fgetcsv( $handle ) !== false ) {
			++$count;
		}
		fclose( $handle );

		return $count;
	}

	/**
	 * Mark job as running.
	 */
	public function mark_running() {
		$this->data['status'] = self::STATUS_RUNNING;
		$this->save();
	}

	/**
	 * Mark job complete.
	 */
	public function mark_complete() {
		$this->data['status']       = self::STATUS_COMPLETE;
		$this->data['completed_at'] = current_time( 'mysql' );
		$this->save();
	}

	/**
	 * Mark job failed.
	 *
	 * @param string $message Error message.
	 */
	public function mark_failed( $message = '' ) {
		$this->data['status']        = self::STATUS_FAILED;
		$this->data['error_message'] = $message;
		$this->data['completed_at']  = current_time( 'mysql' );
		$this->save();
	}

	/**
	 * Mark job cancelled.
	 */
	public function mark_cancelled() {
		$this->data['status']       = self::STATUS_CANCELLED;
		$this->data['completed_at'] = current_time( 'mysql' );
		$this->save();
	}

	/**
	 * Check if job is finished.
	 *
	 * @return bool
	 */
	public function is_finished() {
		return in_array(
			$this->data['status'],
			array( self::STATUS_COMPLETE, self::STATUS_FAILED, self::STATUS_CANCELLED ),
			true
		);
	}

	/**
	 * Get progress percentage.
	 *
	 * @return float
	 */
	public function get_progress_percent() {
		$total = (int) $this->get( 'total_rows', 0 );
		if ( $total <= 0 ) {
			return 0;
		}
		return min( 100, round( ( (int) $this->get( 'processed', 0 ) / $total ) * 100, 1 ) );
	}
}
