<?php
/**
 * Main plugin bootstrap.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Plugin
 */
class Boulk_UP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Boulk_UP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Boulk_UP_Plugin
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
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	/**
	 * Initialize plugin components when dependencies are met.
	 */
	public function init() {
		if ( ! self::dependencies_met() ) {
			return;
		}

		load_plugin_textdomain( 'boulk-update-products', false, dirname( plugin_basename( BOULK_UP_PLUGIN_FILE ) ) . '/languages' );

		Boulk_UP_Batch_Processor::instance();
		Boulk_UP_Bulk_Action_Processor::init();
		Boulk_UP_Admin::instance();
	}

	/**
	 * Check WooCommerce and Yoast SEO are active.
	 *
	 * @return bool
	 */
	public static function dependencies_met() {
		return class_exists( 'WooCommerce' ) && defined( 'WPSEO_VERSION' );
	}

	/**
	 * Show admin notice when dependencies missing.
	 */
	public function dependency_notice() {
		if ( self::dependencies_met() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$missing = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			$missing[] = 'Yoast SEO';
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names */
					__( 'Boulk Bulk Product Update requires %s to be installed and active.', 'boulk-update-products' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		if ( ! self::dependencies_met() ) {
			deactivate_plugins( plugin_basename( BOULK_UP_PLUGIN_FILE ) );
			wp_die(
				esc_html__( 'Boulk Bulk Product Update requires WooCommerce and Yoast SEO to be active.', 'boulk-update-products' ),
				esc_html__( 'Plugin Activation Error', 'boulk-update-products' ),
				array( 'back_link' => true )
			);
		}

		Boulk_UP_Import_Job::ensure_upload_dir();
	}

	/**
	 * Plugin deactivation — unschedule pending batches.
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( BOULK_UP_BATCH_HOOK );
		}
		wp_clear_scheduled_hook( BOULK_UP_CRON_HOOK );
		if ( defined( 'BOULK_UP_BULK_HOOK' ) ) {
			wp_clear_scheduled_hook( BOULK_UP_BULK_HOOK );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Boulk_UP_Bulk_Action_Job::HOOK );
		}
	}
}
