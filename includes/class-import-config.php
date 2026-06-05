<?php
/**
 * Import performance profiles for large CSV runs.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Import_Config
 */
class Boulk_UP_Import_Config {

	const PROFILE_QUICK      = 'quick';
	const PROFILE_STANDARD   = 'standard';
	const PROFILE_AUTO_QUEUE = 'auto_queue';
	const PROFILE_LARGE      = 'large';
	const PROFILE_MAX        = 'max';

	/**
	 * Default profile when row count is unknown.
	 */
	const DEFAULT_PROFILE = self::PROFILE_QUICK;

	/**
	 * Get profile definitions.
	 *
	 * @return array<string, array{batch_size: int, time_limit: int, schedule_delay: int, chain_ticks: int, rows_per_run: int, label: string, description: string}>
	 */
	public static function get_profiles() {
		$profiles = array(
			self::PROFILE_QUICK => array(
				'batch_size'     => 100,
				'time_limit'     => 60,
				'schedule_delay' => 0,
				'chain_ticks'    => 8,
				'rows_per_run'   => 0,
				'label'          => __( 'Quick (under ~1,000 rows)', 'boulk-update-products' ),
				'description'    => __( 'Best for small/medium files. Finishes in one or few runs.', 'boulk-update-products' ),
			),
			self::PROFILE_STANDARD => array(
				'batch_size'     => 150,
				'time_limit'     => 45,
				'schedule_delay' => 0,
				'chain_ticks'    => 8,
				'rows_per_run'   => 0,
				'label'          => __( 'Standard (up to ~5,000 rows)', 'boulk-update-products' ),
				'description'    => __( 'Balanced speed for medium catalogs.', 'boulk-update-products' ),
			),
			self::PROFILE_AUTO_QUEUE => array(
				'batch_size'     => 250,
				'time_limit'     => 120,
				'schedule_delay' => 2,
				'chain_ticks'    => 1,
				'rows_per_run'   => 1000,
				'label'          => __( 'Auto-queue (1,000 per run — 20k–30k files)', 'boulk-update-products' ),
				'description'    => __( 'CSV is saved on the server. Each background run updates 1,000 products, then automatically continues until the file is finished.', 'boulk-update-products' ),
			),
			self::PROFILE_LARGE => array(
				'batch_size'     => 250,
				'time_limit'     => 90,
				'schedule_delay' => 0,
				'chain_ticks'    => 8,
				'rows_per_run'   => 0,
				'label'          => __( 'Large (up to ~10,000 rows)', 'boulk-update-products' ),
				'description'    => __( 'For large monthly updates.', 'boulk-update-products' ),
			),
			self::PROFILE_MAX => array(
				'batch_size'     => 300,
				'time_limit'     => 120,
				'schedule_delay' => 0,
				'chain_ticks'    => 8,
				'rows_per_run'   => 0,
				'label'          => __( 'Maximum (50,000+ rows)', 'boulk-update-products' ),
				'description'    => __( 'Longest run per tick; use during low traffic.', 'boulk-update-products' ),
			),
		);

		return apply_filters( 'boulk_up_import_profiles', $profiles );
	}

	/**
	 * Pick a sensible profile from row count when user leaves default.
	 *
	 * @param int    $total_rows Row count.
	 * @param string $profile    User-selected profile.
	 * @return string
	 */
	public static function auto_profile( $total_rows, $profile ) {
		$profile = self::sanitize_profile( $profile );

		if ( $total_rows <= 500 ) {
			return self::PROFILE_QUICK;
		}

		if ( $total_rows > 2000 ) {
			return self::PROFILE_AUTO_QUEUE;
		}

		if ( $total_rows <= 2000 && in_array( $profile, array( self::PROFILE_LARGE, self::PROFILE_MAX ), true ) ) {
			return self::PROFILE_STANDARD;
		}

		return $profile;
	}

	/**
	 * Rows at or below this count are processed immediately on upload.
	 *
	 * @return int
	 */
	public static function get_inline_threshold() {
		return (int) apply_filters( 'boulk_up_inline_threshold', 500 );
	}

	/**
	 * Resolve profile key from user input.
	 *
	 * @param string $profile Profile slug.
	 * @return string
	 */
	public static function sanitize_profile( $profile ) {
		$profiles = self::get_profiles();
		return isset( $profiles[ $profile ] ) ? $profile : self::DEFAULT_PROFILE;
	}

	/**
	 * Get settings for a profile.
	 *
	 * @param string $profile Profile slug.
	 * @return array{batch_size: int, time_limit: int, schedule_delay: int, chain_ticks: int, rows_per_run: int, label: string, description: string}
	 */
	public static function get_profile_settings( $profile ) {
		$profiles = self::get_profiles();
		$profile  = self::sanitize_profile( $profile );

		return $profiles[ $profile ];
	}

	/**
	 * Batch size for a job (rows read per inner loop).
	 *
	 * @param Boulk_UP_Import_Job|null $job Job instance.
	 * @return int
	 */
	public static function get_batch_size( $job = null ) {
		$settings = self::get_job_settings( $job );
		$size     = (int) $settings['batch_size'];

		return max( 10, (int) apply_filters( 'boulk_up_batch_size', $size, $job ) );
	}

	/**
	 * Max seconds to process per scheduler tick.
	 *
	 * @param Boulk_UP_Import_Job|null $job Job instance.
	 * @return int
	 */
	public static function get_time_limit( $job = null ) {
		$settings = self::get_job_settings( $job );
		$limit    = (int) $settings['time_limit'];

		return max( 15, (int) apply_filters( 'boulk_up_batch_time_limit', $limit, $job ) );
	}

	/**
	 * Delay before next scheduler tick (seconds).
	 *
	 * @param Boulk_UP_Import_Job|null $job Job instance.
	 * @return int
	 */
	public static function get_schedule_delay( $job = null ) {
		$settings = self::get_job_settings( $job );
		$delay    = (int) $settings['schedule_delay'];

		return max( 0, (int) apply_filters( 'boulk_up_schedule_delay', $delay, $job ) );
	}

	/**
	 * Max rows processed per scheduler tick (0 = time-based only).
	 *
	 * @param Boulk_UP_Import_Job|null $job Job instance.
	 * @return int
	 */
	public static function get_rows_per_run( $job = null ) {
		$settings = self::get_job_settings( $job );
		$rows     = isset( $settings['rows_per_run'] ) ? (int) $settings['rows_per_run'] : 0;

		return max( 0, (int) apply_filters( 'boulk_up_rows_per_run', $rows, $job ) );
	}

	/**
	 * Max extra ticks to run back-to-back in one HTTP request.
	 *
	 * @param Boulk_UP_Import_Job|null $job Job instance.
	 * @return int
	 */
	public static function get_chain_ticks( $job = null ) {
		if ( $job ) {
			$settings = self::get_job_settings( $job );
			$chains   = isset( $settings['chain_ticks'] ) ? (int) $settings['chain_ticks'] : 8;
		} else {
			$chains = 8;
		}

		return max( 1, (int) apply_filters( 'boulk_up_chain_ticks', $chains, $job ) );
	}

	/**
	 * Max upload size in bytes.
	 *
	 * @return int
	 */
	public static function get_max_upload_size() {
		return (int) apply_filters( 'boulk_up_max_upload_size', 128 * 1024 * 1024 );
	}

	/**
	 * Settings from job or default profile.
	 *
	 * @param Boulk_UP_Import_Job|null $job Job.
	 * @return array{batch_size: int, time_limit: int, schedule_delay: int, chain_ticks: int, rows_per_run: int, label: string, description: string}
	 */
	private static function get_job_settings( $job ) {
		if ( $job ) {
			$profile = $job->get( 'profile', self::DEFAULT_PROFILE );
			return self::get_profile_settings( $profile );
		}

		return self::get_profile_settings( self::DEFAULT_PROFILE );
	}
}
