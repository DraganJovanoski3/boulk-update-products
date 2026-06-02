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
	 * @param string $profile     Import performance profile.
	 * @return Boulk_UP_Import_Job|WP_Error
	 */
	public static function create( $source_file, $dry_run = false, $profile = '' ) {
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

		$profile = Boulk_UP_Import_Config::sanitize_profile( $profile );

		$job = new self(
			$id,
			array(
				'id'           => $id,
				'status'       => self::STATUS_QUEUED,
				'dry_run'      => (bool) $dry_run,
				'profile'      => $profile,
				'file_path'    => $dest,
				'total_rows'   => $parser->get_total_rows(),
				'processed'    => 0,
				'updated'      => 0,
				'skipped'      => 0,
				'errors'       => 0,
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
		if ( ! isset( $this->data['log_entries'] ) || ! is_array( $this->data['log_entries'] ) ) {
			$this->data['log_entries'] = array();
		}

		$this->data['log_entries'][] = array(
			'row'     => $row_number,
			'sku'     => $sku,
			'status'  => $status,
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		);

		// Keep last 5000 entries in option; full log in file.
		if ( count( $this->data['log_entries'] ) > 100 ) {
			$this->data['log_entries'] = array_slice( $this->data['log_entries'], -100 );
		}

		$this->append_log_file( $row_number, $sku, $status, $message );
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
	 * Get log file path.
	 *
	 * @return string|null
	 */
	public function get_log_file_path() {
		$path = self::get_upload_dir() . '/' . $this->id . '-log.csv';
		return file_exists( $path ) ? $path : null;
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
