<?php
/**
 * Admin UI for bulk product imports.
 *
 * @package Boulk_Update_Products
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Boulk_UP_Admin
 */
class Boulk_UP_Admin {

	/**
	 * Singleton.
	 *
	 * @var Boulk_UP_Admin|null
	 */
	private static $instance = null;

	/**
	 * Menu slug.
	 */
	const MENU_SLUG = 'boulk-bulk-product-update';

	/**
	 * Get instance.
	 *
	 * @return Boulk_UP_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_boulk_up_start_import', array( $this, 'handle_start_import' ) );
		add_action( 'admin_post_boulk_up_cancel_job', array( $this, 'handle_cancel_job' ) );
		add_action( 'admin_post_boulk_up_download_sample', array( $this, 'handle_download_sample' ) );
		add_action( 'admin_post_boulk_up_download_log', array( $this, 'handle_download_log' ) );
		add_action( 'wp_ajax_boulk_up_job_status', array( $this, 'ajax_job_status' ) );
	}

	/**
	 * Register WooCommerce submenu.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Bulk Product Update', 'boulk-update-products' ),
			__( 'Bulk Product Update', 'boulk-update-products' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'boulk-up-admin',
			BOULK_UP_PLUGIN_URL . 'assets/admin.css',
			array(),
			BOULK_UP_VERSION
		);

		wp_enqueue_script(
			'boulk-up-admin',
			BOULK_UP_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			BOULK_UP_VERSION,
			true
		);

		wp_localize_script(
			'boulk-up-admin',
			'boulkUpAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'boulk_up_job_status' ),
				'pollInterval' => 3000,
				'i18n'     => array(
					'processing' => __( 'Processing…', 'boulk-update-products' ),
					'complete'   => __( 'Complete', 'boulk-update-products' ),
					'failed'     => __( 'Failed', 'boulk-update-products' ),
					'cancelled'  => __( 'Cancelled', 'boulk-update-products' ),
				),
			)
		);
	}

	/**
	 * Render main admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'boulk-update-products' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		$job = isset( $_GET['job_id'] ) ? Boulk_UP_Import_Job::load( sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) ) : null;

		$notice = isset( $_GET['boulk_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['boulk_notice'] ) ) : '';
		$message = isset( $_GET['boulk_message'] ) ? sanitize_text_field( wp_unslash( $_GET['boulk_message'] ) ) : '';

		?>
		<div class="wrap boulk-up-wrap">
			<h1><?php esc_html_e( 'Bulk Product Update', 'boulk-update-products' ); ?></h1>

			<?php if ( $notice && $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $notice ? 'error' : 'success' ); ?> is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=import' ) ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'New Import', 'boulk-update-products' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=history' ) ); ?>" class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Import History', 'boulk-update-products' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=docs' ) ); ?>" class="nav-tab <?php echo 'docs' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Documentation', 'boulk-update-products' ); ?>
				</a>
			</nav>

			<div class="boulk-up-tab-content">
				<?php
				switch ( $tab ) {
					case 'history':
						$this->render_history_tab( $job );
						break;
					case 'docs':
						$this->render_docs_tab();
						break;
					default:
						$this->render_import_tab( $job );
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render new import tab.
	 *
	 * @param Boulk_UP_Import_Job|null $active_job Job to show progress for.
	 */
	private function render_import_tab( $active_job = null ) {
		$max_size = Boulk_UP_Import_Config::get_max_upload_size();
		$profiles = Boulk_UP_Import_Config::get_profiles();
		?>
		<div class="boulk-up-panel">
			<h2><?php esc_html_e( 'Upload CSV', 'boulk-update-products' ); ?></h2>
			<p><?php esc_html_e( 'Upload a CSV file to bulk-update existing WooCommerce products matched by SKU. Empty cells are skipped (partial updates supported). There is no row cap — files with 10,000+ rows are supported via background processing.', 'boulk-update-products' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="boulk-up-import-form">
				<?php wp_nonce_field( 'boulk_up_start_import' ); ?>
				<input type="hidden" name="action" value="boulk_up_start_import" />

				<table class="form-table">
					<tr>
						<th scope="row"><label for="boulk_csv_file"><?php esc_html_e( 'CSV File', 'boulk-update-products' ); ?></label></th>
						<td>
							<input type="file" name="boulk_csv_file" id="boulk_csv_file" accept=".csv,text/csv" required />
							<p class="description">
								<?php
								printf(
									/* translators: %s: max file size */
									esc_html__( 'Maximum file size: %s. UTF-8 encoding recommended.', 'boulk-update-products' ),
									esc_html( size_format( $max_size ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="boulk_import_profile"><?php esc_html_e( 'Import size', 'boulk-update-products' ); ?></label></th>
						<td>
							<select name="boulk_import_profile" id="boulk_import_profile">
								<?php foreach ( $profiles as $key => $profile ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, Boulk_UP_Import_Config::DEFAULT_PROFILE ); ?>>
										<?php echo esc_html( $profile['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php
							$profile_descriptions = array();
							foreach ( $profiles as $key => $profile ) {
								$profile_descriptions[ $key ] = $profile['description'];
							}
							?>
							<p class="description boulk-profile-desc" data-profiles="<?php echo esc_attr( wp_json_encode( $profile_descriptions ) ); ?>">
								<?php echo esc_html( $profiles[ Boulk_UP_Import_Config::DEFAULT_PROFILE ]['description'] ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Dry Run', 'boulk-update-products' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="boulk_dry_run" value="1" />
								<?php esc_html_e( 'Validate only — do not write changes to the database', 'boulk-update-products' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Start Import', 'boulk-update-products' ) ); ?>
			</form>
		</div>

		<?php if ( $active_job && ! $active_job->is_finished() ) : ?>
			<?php $this->render_job_progress( $active_job ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render import history tab.
	 *
	 * @param Boulk_UP_Import_Job|null $selected_job Selected job for detail view.
	 */
	private function render_history_tab( $selected_job = null ) {
		$jobs = Boulk_UP_Import_Job::list_jobs( 20 );
		?>
		<div class="boulk-up-panel">
			<h2><?php esc_html_e( 'Import History', 'boulk-update-products' ); ?></h2>

			<?php if ( empty( $jobs ) ) : ?>
				<p><?php esc_html_e( 'No imports yet.', 'boulk-update-products' ); ?></p>
			<?php else : ?>
				<table class="widefat striped boulk-up-jobs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job ID', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Status', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Skipped', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Created', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'boulk-update-products' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $job ) : ?>
							<tr data-job-id="<?php echo esc_attr( $job->get_id() ); ?>" data-status="<?php echo esc_attr( $job->get( 'status' ) ); ?>">
								<td><code><?php echo esc_html( $job->get_id() ); ?></code></td>
								<td class="boulk-status-cell">
									<span class="boulk-status boulk-status-<?php echo esc_attr( $job->get( 'status' ) ); ?>">
										<?php echo esc_html( $this->status_label( $job->get( 'status' ) ) ); ?>
									</span>
									<?php if ( $job->get( 'dry_run' ) ) : ?>
										<span class="boulk-badge"><?php esc_html_e( 'Dry run', 'boulk-update-products' ); ?></span>
									<?php endif; ?>
									<?php
									$profile_key = $job->get( 'profile', Boulk_UP_Import_Config::DEFAULT_PROFILE );
									$profile_cfg = Boulk_UP_Import_Config::get_profile_settings( $profile_key );
									?>
									<span class="boulk-badge" title="<?php echo esc_attr( $profile_cfg['description'] ); ?>">
										<?php echo esc_html( $profile_cfg['label'] ); ?>
									</span>
								</td>
								<td>
									<div class="boulk-progress-bar" data-percent="<?php echo esc_attr( $job->get_progress_percent() ); ?>">
										<div class="boulk-progress-fill" style="width:<?php echo esc_attr( $job->get_progress_percent() ); ?>%"></div>
									</div>
									<span class="boulk-progress-text">
										<?php
										printf(
											/* translators: 1: processed count, 2: total count */
											esc_html__( '%1$d / %2$d rows', 'boulk-update-products' ),
											(int) $job->get( 'processed', 0 ),
											(int) $job->get( 'total_rows', 0 )
										);
										?>
									</span>
								</td>
								<td class="boulk-updated-cell"><?php echo esc_html( (string) $job->get( 'updated', 0 ) ); ?></td>
								<td class="boulk-skipped-cell"><?php echo esc_html( (string) $job->get( 'skipped', 0 ) ); ?></td>
								<td class="boulk-errors-cell"><?php echo esc_html( (string) $job->get( 'errors', 0 ) ); ?></td>
								<td><?php echo esc_html( $job->get( 'created_at' ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=history&job_id=' . $job->get_id() ) ); ?>">
										<?php esc_html_e( 'View', 'boulk-update-products' ); ?>
									</a>
									<?php if ( $job->get_log_file_path() ) : ?>
										| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_download_log&job_id=' . $job->get_id() ), 'boulk_up_download_log' ) ); ?>">
											<?php esc_html_e( 'Log', 'boulk-update-products' ); ?>
										</a>
									<?php endif; ?>
									<?php if ( ! $job->is_finished() ) : ?>
										| <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_cancel_job&job_id=' . $job->get_id() ), 'boulk_up_cancel_job' ) ); ?>" class="boulk-cancel-link" onclick="return confirm('<?php echo esc_js( __( 'Cancel this import?', 'boulk-update-products' ) ); ?>');">
											<?php esc_html_e( 'Cancel', 'boulk-update-products' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( $selected_job ) : ?>
			<?php $this->render_job_progress( $selected_job ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render documentation tab.
	 */
	private function render_docs_tab() {
		?>
		<div class="boulk-up-panel">
			<h2><?php esc_html_e( 'CSV Column Reference', 'boulk-update-products' ); ?></h2>
			<p><?php esc_html_e( 'The sku column is required. All other columns are optional. Leave a cell empty to skip updating that field.', 'boulk-update-products' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Column', 'boulk-update-products' ); ?></th>
						<th><?php esc_html_e( 'Aliases', 'boulk-update-products' ); ?></th>
						<th><?php esc_html_e( 'Description', 'boulk-update-products' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->get_column_docs() as $doc ) : ?>
						<tr>
							<td><code><?php echo esc_html( $doc['column'] ); ?></code></td>
							<td><?php echo esc_html( $doc['aliases'] ); ?></td>
							<td><?php echo esc_html( $doc['description'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_download_sample' ), 'boulk_up_download_sample' ) ); ?>">
					<?php esc_html_e( 'Download Sample CSV', 'boulk-update-products' ); ?>
				</a>
			</p>

			<h3><?php esc_html_e( 'Tips for large imports (10k–50k products)', 'boulk-update-products' ); ?></h3>
			<ul class="boulk-up-tips">
				<li><?php esc_html_e( 'For ~10,000 rows per run, choose Import size: Large (default).', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'For 50,000+ rows, choose Maximum and run during low-traffic hours.', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'Run imports during low-traffic hours.', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'Use a dry run first to validate SKUs and catch missing products.', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'Categories: pipe-separated, e.g. Electronics|Cables|USB. Existing categories only.', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'Cross-sells: comma-separated SKUs.', 'boulk-update-products' ); ?></li>
				<li><?php esc_html_e( 'Check WooCommerce → Status → Scheduled Actions if imports stall.', 'boulk-update-products' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Column documentation rows.
	 *
	 * @return array<int, array{column: string, aliases: string, description: string}>
	 */
	private function get_column_docs() {
		return array(
			array( 'column' => 'sku', 'aliases' => '—', 'description' => __( 'Required. Product SKU used to find the product.', 'boulk-update-products' ) ),
			array( 'column' => 'title', 'aliases' => '—', 'description' => __( 'Product title.', 'boulk-update-products' ) ),
			array( 'column' => 'short_description', 'aliases' => '—', 'description' => __( 'Product short description.', 'boulk-update-products' ) ),
			array( 'column' => 'description', 'aliases' => 'full_description', 'description' => __( 'Full product description.', 'boulk-update-products' ) ),
			array( 'column' => 'slug', 'aliases' => '—', 'description' => __( 'Product URL slug.', 'boulk-update-products' ) ),
			array( 'column' => 'regular_price', 'aliases' => 'price', 'description' => __( 'Regular price.', 'boulk-update-products' ) ),
			array( 'column' => 'sale_price', 'aliases' => '—', 'description' => __( 'Sale price.', 'boulk-update-products' ) ),
			array( 'column' => 'seo_title', 'aliases' => 'meta_title', 'description' => __( 'Yoast SEO title.', 'boulk-update-products' ) ),
			array( 'column' => 'meta_description', 'aliases' => '—', 'description' => __( 'Yoast meta description.', 'boulk-update-products' ) ),
			array( 'column' => 'focus_keyphrase', 'aliases' => 'focus_keyword', 'description' => __( 'Yoast focus keyphrase.', 'boulk-update-products' ) ),
			array( 'column' => 'meta_keywords', 'aliases' => 'keyword', 'description' => __( 'Yoast meta keywords.', 'boulk-update-products' ) ),
			array( 'column' => 'product_tax', 'aliases' => 'tax_class', 'description' => __( 'WooCommerce tax class slug.', 'boulk-update-products' ) ),
			array( 'column' => 'cross_sells', 'aliases' => 'cross_reference, cross_sell', 'description' => __( 'Comma-separated cross-sell SKUs.', 'boulk-update-products' ) ),
			array( 'column' => 'categories', 'aliases' => 'product_category', 'description' => __( 'Pipe-separated category names or slugs.', 'boulk-update-products' ) ),
			array( 'column' => 'alt_text', 'aliases' => 'image_alt', 'description' => __( 'Featured image alt text.', 'boulk-update-products' ) ),
		);
	}

	/**
	 * Render job progress block.
	 *
	 * @param Boulk_UP_Import_Job $job Job.
	 */
	private function render_job_progress( $job ) {
		$finished = $job->is_finished();
		?>
		<div class="boulk-up-panel boulk-up-job-detail" id="boulk-job-progress" data-job-id="<?php echo esc_attr( $job->get_id() ); ?>" data-finished="<?php echo $finished ? '1' : '0'; ?>">
			<h2><?php esc_html_e( 'Import Progress', 'boulk-update-products' ); ?></h2>
			<p><strong><?php esc_html_e( 'Job:', 'boulk-update-products' ); ?></strong> <code><?php echo esc_html( $job->get_id() ); ?></code></p>

			<div class="boulk-progress-bar boulk-progress-large" data-percent="<?php echo esc_attr( $job->get_progress_percent() ); ?>">
				<div class="boulk-progress-fill" style="width:<?php echo esc_attr( $job->get_progress_percent() ); ?>%"></div>
			</div>
			<p class="boulk-progress-summary">
				<span class="boulk-status boulk-status-<?php echo esc_attr( $job->get( 'status' ) ); ?>"><?php echo esc_html( $this->status_label( $job->get( 'status' ) ) ); ?></span>
				—
				<span class="boulk-progress-text">
					<?php
					printf(
						esc_html__( '%1$d / %2$d rows (%3$s%%)', 'boulk-update-products' ),
						(int) $job->get( 'processed', 0 ),
						(int) $job->get( 'total_rows', 0 ),
						esc_html( (string) $job->get_progress_percent() )
					);
					?>
				</span>
			</p>

			<ul class="boulk-stats">
				<li><strong><?php esc_html_e( 'Updated:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-updated"><?php echo esc_html( (string) $job->get( 'updated', 0 ) ); ?></span></li>
				<li><strong><?php esc_html_e( 'Skipped:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-skipped"><?php echo esc_html( (string) $job->get( 'skipped', 0 ) ); ?></span></li>
				<li><strong><?php esc_html_e( 'Errors:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-errors"><?php echo esc_html( (string) $job->get( 'errors', 0 ) ); ?></span></li>
			</ul>

			<?php if ( $job->get_log_file_path() ) : ?>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_download_log&job_id=' . $job->get_id() ), 'boulk_up_download_log' ) ); ?>">
						<?php esc_html_e( 'Download Full Log (CSV)', 'boulk-update-products' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php
			$recent = $job->get( 'log_entries', array() );
			if ( ! empty( $recent ) ) :
				?>
				<h3><?php esc_html_e( 'Recent Log Entries', 'boulk-update-products' ); ?></h3>
				<table class="widefat striped boulk-log-preview">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Row', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Status', 'boulk-update-products' ); ?></th>
							<th><?php esc_html_e( 'Message', 'boulk-update-products' ); ?></th>
						</tr>
					</thead>
					<tbody class="boulk-log-entries">
						<?php foreach ( array_reverse( array_slice( $recent, -20 ) ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $entry['row'] ); ?></td>
								<td><?php echo esc_html( $entry['sku'] ); ?></td>
								<td><?php echo esc_html( $entry['status'] ); ?></td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Human-readable status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			Boulk_UP_Import_Job::STATUS_QUEUED    => __( 'Queued', 'boulk-update-products' ),
			Boulk_UP_Import_Job::STATUS_RUNNING     => __( 'Running', 'boulk-update-products' ),
			Boulk_UP_Import_Job::STATUS_COMPLETE    => __( 'Complete', 'boulk-update-products' ),
			Boulk_UP_Import_Job::STATUS_FAILED      => __( 'Failed', 'boulk-update-products' ),
			Boulk_UP_Import_Job::STATUS_CANCELLED   => __( 'Cancelled', 'boulk-update-products' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Handle import start.
	 */
	public function handle_start_import() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'boulk-update-products' ) );
		}

		check_admin_referer( 'boulk_up_start_import' );

		if ( empty( $_FILES['boulk_csv_file']['tmp_name'] ) ) {
			$this->redirect_with_notice( 'error', __( 'Please select a CSV file.', 'boulk-update-products' ) );
		}

		$file = $_FILES['boulk_csv_file'];
		$max  = Boulk_UP_Import_Config::get_max_upload_size();

		if ( $file['size'] > $max ) {
			$this->redirect_with_notice( 'error', __( 'File exceeds maximum upload size.', 'boulk-update-products' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			$this->redirect_with_notice( 'error', __( 'Only CSV files are allowed.', 'boulk-update-products' ) );
		}

		$dry_run = ! empty( $_POST['boulk_dry_run'] );
		$profile = isset( $_POST['boulk_import_profile'] )
			? Boulk_UP_Import_Config::sanitize_profile( sanitize_key( wp_unslash( $_POST['boulk_import_profile'] ) ) )
			: Boulk_UP_Import_Config::DEFAULT_PROFILE;

		$job = Boulk_UP_Import_Job::create( $file['tmp_name'], $dry_run, $profile );
		if ( is_wp_error( $job ) ) {
			$this->redirect_with_notice( 'error', $job->get_error_message() );
		}

		Boulk_UP_Batch_Processor::instance()->schedule_job( $job->get_id() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::MENU_SLUG,
					'tab'          => 'import',
					'job_id'       => $job->get_id(),
					'boulk_notice' => 'success',
					'boulk_message' => $dry_run
						? __( 'Dry run started. No changes will be saved.', 'boulk-update-products' )
						: __( 'Import started. Processing in background.', 'boulk-update-products' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle job cancel.
	 */
	public function handle_cancel_job() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'boulk-update-products' ) );
		}

		check_admin_referer( 'boulk_up_cancel_job' );

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job    = Boulk_UP_Import_Job::load( $job_id );

		if ( $job && ! $job->is_finished() ) {
			Boulk_UP_Batch_Processor::instance()->cancel_job( $job_id );
			$job->mark_cancelled();
		}

		$this->redirect_with_notice( 'success', __( 'Import cancelled.', 'boulk-update-products' ), 'history' );
	}

	/**
	 * Download sample CSV.
	 */
	public function handle_download_sample() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'boulk-update-products' ) );
		}

		check_admin_referer( 'boulk_up_download_sample' );

		$headers = Boulk_UP_CSV_Parser::get_sample_headers();
		$example = array(
			'SKU-001',
			'Example Product',
			'Short desc here',
			'Full description here',
			'example-product',
			'29.99',
			'24.99',
			'SEO Title | Store',
			'Meta description for search engines.',
			'example keyword',
			'keyword1, keyword2',
			'',
			'REL-SKU-001,REL-SKU-002',
			'Category|Subcategory',
			'Alt text for image',
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=boulk-sample-import.csv' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, $headers );
		fputcsv( $out, $example );
		fclose( $out );
		exit;
	}

	/**
	 * Download job log CSV.
	 */
	public function handle_download_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'boulk-update-products' ) );
		}

		check_admin_referer( 'boulk_up_download_log' );

		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$job    = Boulk_UP_Import_Job::load( $job_id );

		if ( ! $job ) {
			wp_die( esc_html__( 'Job not found.', 'boulk-update-products' ) );
		}

		$path = $job->get_log_file_path();
		if ( ! $path ) {
			wp_die( esc_html__( 'Log file not found.', 'boulk-update-products' ) );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $job_id . '-log.csv' );
		readfile( $path );
		exit;
	}

	/**
	 * AJAX job status endpoint.
	 */
	public function ajax_job_status() {
		check_ajax_referer( 'boulk_up_job_status', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = Boulk_UP_Import_Job::load( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'Job not found' ), 404 );
		}

		wp_send_json_success(
			array(
				'id'        => $job->get_id(),
				'status'    => $job->get( 'status' ),
				'statusLabel' => $this->status_label( $job->get( 'status' ) ),
				'processed' => (int) $job->get( 'processed', 0 ),
				'total'     => (int) $job->get( 'total_rows', 0 ),
				'updated'   => (int) $job->get( 'updated', 0 ),
				'skipped'   => (int) $job->get( 'skipped', 0 ),
				'errors'    => (int) $job->get( 'errors', 0 ),
				'percent'   => $job->get_progress_percent(),
				'finished'  => $job->is_finished(),
				'logEntries' => array_reverse( array_slice( $job->get( 'log_entries', array() ), -20 ) ),
			)
		);
	}

	/**
	 * Redirect with admin notice params.
	 *
	 * @param string $type    notice type.
	 * @param string $message Message.
	 * @param string $tab     Tab slug.
	 */
	private function redirect_with_notice( $type, $message, $tab = 'import' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::MENU_SLUG,
					'tab'           => $tab,
					'boulk_notice'  => $type,
					'boulk_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
