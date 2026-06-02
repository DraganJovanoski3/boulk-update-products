<?php
/**
 * Background jobs for bulk product update/delete from Product Manager.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Bulk_Action_Job
 */
class Boulk_UP_Bulk_Action_Job {

	const OPTION_PREFIX = 'boulk_up_bulk_job_';
	const LIST_OPTION   = 'boulk_up_bulk_job_ids';
	const HOOK          = 'boulk_up_process_bulk_action';

	const TYPE_UPDATE = 'bulk_update';
	const TYPE_DELETE = 'bulk_delete';

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
	 * Create a bulk action job.
	 *
	 * @param string               $type    bulk_update|bulk_delete.
	 * @param int[]                $ids     Product IDs.
	 * @param array<string, mixed> $payload Update fields for bulk_update.
	 * @return Boulk_UP_Bulk_Action_Job
	 */
	public static function create( $type, $ids, $payload = array() ) {
		Boulk_UP_Import_Job::ensure_upload_dir();

		$id = 'bulk_' . wp_generate_password( 12, false, false );

		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_filter( $ids );

		$ids_file = Boulk_UP_Import_Job::get_upload_dir() . '/' . $id . '-ids.json';
		file_put_contents( $ids_file, wp_json_encode( $ids ) );

		$job = new self(
			$id,
			array(
				'id'         => $id,
				'type'       => $type,
				'status'     => Boulk_UP_Import_Job::STATUS_QUEUED,
				'payload'    => $payload,
				'ids_file'   => $ids_file,
				'total'      => count( $ids ),
				'processed'  => 0,
				'succeeded'  => 0,
				'failed'     => 0,
				'offset'     => 0,
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
				'completed_at' => null,
			)
		);

		$job->save();

		$list = get_option( self::LIST_OPTION, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		array_unshift( $list, $id );
		update_option( self::LIST_OPTION, array_slice( $list, 0, 50 ), false );

		return $job;
	}

	/**
	 * Load job.
	 *
	 * @param string $id Job ID.
	 * @return Boulk_UP_Bulk_Action_Job|null
	 */
	public static function load( $id ) {
		$data = get_option( self::OPTION_PREFIX . $id, null );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return new self( $id, $data );
	}

	/**
	 * Get product IDs for this job.
	 *
	 * @return int[]
	 */
	public function get_product_ids() {
		$file = isset( $this->data['ids_file'] ) ? $this->data['ids_file'] : '';
		if ( ! $file || ! file_exists( $file ) ) {
			return array();
		}
		$json = json_decode( file_get_contents( $file ), true );
		return is_array( $json ) ? array_map( 'absint', $json ) : array();
	}

	/**
	 * Save job.
	 */
	public function save() {
		$this->data['updated_at'] = current_time( 'mysql' );
		update_option( self::OPTION_PREFIX . $this->id, $this->data, false );
	}

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	/**
	 * @param string $key Key.
	 * @param int    $by  Amount.
	 */
	public function increment( $key, $by = 1 ) {
		$current = isset( $this->data[ $key ] ) ? (int) $this->data[ $key ] : 0;
		$this->data[ $key ] = $current + $by;
	}

	/**
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * @return bool
	 */
	public function is_finished() {
		return in_array(
			$this->data['status'],
			array(
				Boulk_UP_Import_Job::STATUS_COMPLETE,
				Boulk_UP_Import_Job::STATUS_FAILED,
				Boulk_UP_Import_Job::STATUS_CANCELLED,
			),
			true
		);
	}

	/**
	 * @return float
	 */
	public function get_progress_percent() {
		$total = (int) $this->get( 'total', 0 );
		if ( $total <= 0 ) {
			return 0;
		}
		return min( 100, round( ( (int) $this->get( 'processed', 0 ) / $total ) * 100, 1 ) );
	}

	/**
	 * Mark running.
	 */
	public function mark_running() {
		$this->data['status'] = Boulk_UP_Import_Job::STATUS_RUNNING;
		$this->save();
	}

	/**
	 * Mark complete.
	 */
	public function mark_complete() {
		$this->data['status']       = Boulk_UP_Import_Job::STATUS_COMPLETE;
		$this->data['completed_at'] = current_time( 'mysql' );
		$this->save();
	}

	/**
	 * Mark failed.
	 *
	 * @param string $message Error message.
	 */
	public function mark_failed( $message = '' ) {
		$this->data['status']        = Boulk_UP_Import_Job::STATUS_FAILED;
		$this->data['error_message'] = $message;
		$this->data['completed_at']  = current_time( 'mysql' );
		$this->save();
	}
}
