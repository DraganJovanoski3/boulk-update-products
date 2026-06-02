<?php
/**
 * Plugin Name:       Boulk Bulk Product Update
 * Plugin URI:        https://github.com/boulk/bulk-update-products
 * Description:       Bulk update WooCommerce products and Yoast SEO fields from CSV files. Matched by SKU with batched background processing.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Boulk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       boulk-update-products
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

define( 'BOULK_UP_VERSION', '1.5.0' );
define( 'BOULK_UP_BULK_HOOK', 'boulk_up_process_bulk_action' );
define( 'BOULK_UP_PLUGIN_FILE', __FILE__ );
define( 'BOULK_UP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOULK_UP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BOULK_UP_BATCH_HOOK', 'boulk_up_process_batch' );
define( 'BOULK_UP_CRON_HOOK', 'boulk_up_process_batch_cron' );

require_once BOULK_UP_PLUGIN_DIR . 'includes/class-import-config.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-update-fields.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-csv-parser.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-import-job.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-yoast-updater.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-product-updater.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-batch-processor.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-bulk-action-job.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-product-manager.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-admin.php';
require_once BOULK_UP_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Boulk_UP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Boulk_UP_Plugin', 'deactivate' ) );

Boulk_UP_Plugin::instance();
