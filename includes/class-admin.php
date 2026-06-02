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
		add_action( 'admin_post_boulk_up_download_automann_sample', array( $this, 'handle_download_automann_sample' ) );
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
	 * Render field selection checkboxes and presets.
	 */
	private function render_field_selection() {
		$groups  = Boulk_UP_Update_Fields::get_groups();
		$presets = Boulk_UP_Update_Fields::get_presets();
		?>
		<p class="description"><?php esc_html_e( 'Choose which columns from your CSV should be applied. Other columns in the file are ignored even if they have data. SKU is always used to find the product.', 'boulk-update-products' ); ?></p>

		<div class="boulk-field-presets">
			<strong><?php esc_html_e( 'Quick select:', 'boulk-update-products' ); ?></strong>
			<?php foreach ( $presets as $preset_key => $preset ) : ?>
				<button type="button" class="button button-small boulk-preset-btn" data-preset="<?php echo esc_attr( $preset_key ); ?>">
					<?php echo esc_html( $preset['label'] ); ?>
				</button>
			<?php endforeach; ?>
			<button type="button" class="button button-small boulk-select-all"><?php esc_html_e( 'Select all', 'boulk-update-products' ); ?></button>
			<button type="button" class="button button-small boulk-select-none"><?php esc_html_e( 'Clear all', 'boulk-update-products' ); ?></button>
		</div>

		<div class="boulk-field-groups" data-presets="<?php echo esc_attr( wp_json_encode( wp_list_pluck( $presets, 'fields' ) ) ); ?>">
			<?php foreach ( $groups as $group_key => $group ) : ?>
				<fieldset class="boulk-field-group">
					<legend><?php echo esc_html( $group['label'] ); ?></legend>
					<div class="boulk-field-checkboxes">
						<?php foreach ( $group['fields'] as $field_key => $field_label ) : ?>
							<label class="boulk-field-label">
								<input type="checkbox" name="boulk_update_fields[]" value="<?php echo esc_attr( $field_key ); ?>" checked="checked" class="boulk-field-cb" />
								<?php echo esc_html( $field_label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>
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
						<th scope="row"><?php esc_html_e( 'Fields to update', 'boulk-update-products' ); ?></th>
						<td>
							<?php $this->render_field_selection(); ?>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'SKU rules', 'boulk-update-products' ); ?></th>
						<td>
							<ul class="boulk-sku-rules">
								<li><?php esc_html_e( 'Empty sku cell → error (row skipped, nothing created).', 'boulk-update-products' ); ?></li>
								<li><?php esc_html_e( 'sku exists in WooCommerce → update that product.', 'boulk-update-products' ); ?></li>
								<li><?php esc_html_e( 'sku in CSV but not in store → create a new simple product.', 'boulk-update-products' ); ?></li>
							</ul>
							<p class="description">
								<?php esc_html_e( 'Only the sku column is used to match products. Automann Part Number is optional meta, not a substitute for sku.', 'boulk-update-products' ); ?>
							</p>
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
							<th><?php esc_html_e( 'Created', 'boulk-update-products' ); ?></th>
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
									<span class="boulk-badge" title="<?php echo esc_attr( Boulk_UP_Update_Fields::format_selection_label( $job->get( 'update_fields', null ) ) ); ?>">
										<?php echo esc_html( Boulk_UP_Update_Fields::format_selection_label( $job->get( 'update_fields', null ) ) ); ?>
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
								<td class="boulk-created-cell"><?php echo esc_html( (string) $job->get( 'created', 0 ) ); ?></td>
								<td class="boulk-skipped-cell"><?php echo esc_html( (string) $job->get( 'skipped', 0 ) ); ?></td>
								<td class="boulk-errors-cell"><?php echo esc_html( (string) $job->get( 'errors', 0 ) ); ?></td>
								<td><?php echo esc_html( $job->get( 'created_at' ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=history&job_id=' . $job->get_id() ) ); ?>">
										<?php esc_html_e( 'View', 'boulk-update-products' ); ?>
									</a>
									<?php if ( (int) $job->get( 'errors', 0 ) > 0 ) : ?>
										| <a href="<?php echo esc_url( $this->get_log_download_url( $job->get_id(), 'error' ) ); ?>" class="boulk-log-errors">
											<?php
											printf(
												/* translators: %d: error count */
												esc_html__( 'Errors (%d)', 'boulk-update-products' ),
												(int) $job->get( 'errors', 0 )
											);
											?>
										</a>
									<?php endif; ?>
									<?php if ( (int) $job->get( 'skipped', 0 ) > 0 ) : ?>
										| <a href="<?php echo esc_url( $this->get_log_download_url( $job->get_id(), 'skipped' ) ); ?>" class="boulk-log-skipped">
											<?php
											printf(
												/* translators: %d: skipped count */
												esc_html__( 'Skipped (%d)', 'boulk-update-products' ),
												(int) $job->get( 'skipped', 0 )
											);
											?>
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
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_download_automann_sample' ), 'boulk_up_download_automann_sample' ) ); ?>">
					<?php esc_html_e( 'Download Automann CSV Template', 'boulk-update-products' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=boulk_up_download_sample' ), 'boulk_up_download_sample' ) ); ?>">
					<?php esc_html_e( 'Download Generic Sample CSV', 'boulk-update-products' ); ?>
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
			array( 'column' => 'sku', 'aliases' => '—', 'description' => __( 'Required on every row. Matches WooCommerce products; creates new product if not found.', 'boulk-update-products' ) ),
			array( 'column' => 'Automann Part Number', 'aliases' => '—', 'description' => __( 'Optional. Saved as product meta only (does not replace sku).', 'boulk-update-products' ) ),
			array( 'column' => 'Description', 'aliases' => '—', 'description' => __( 'Product description (and product name when creating new products).', 'boulk-update-products' ) ),
			array( 'column' => 'Updated Price', 'aliases' => '—', 'description' => __( 'Selling price (WooCommerce regular price).', 'boulk-update-products' ) ),
			array( 'column' => 'Dist. Net Price', 'aliases' => '—', 'description' => __( 'Maps to WooCommerce sale price.', 'boulk-update-products' ) ),
			array( 'column' => 'Dist. Price List', 'aliases' => '—', 'description' => __( 'Saved as product meta for reference.', 'boulk-update-products' ) ),
			array( 'column' => 'Stock status', 'aliases' => '—', 'description' => __( 'instock, outofstock, or onbackorder.', 'boulk-update-products' ) ),
			array( 'column' => 'Master Category', 'aliases' => '—', 'description' => __( 'WooCommerce category (must already exist).', 'boulk-update-products' ) ),
			array( 'column' => 'Brands', 'aliases' => '—', 'description' => __( 'Brand taxonomy term or saved as meta if no brand taxonomy exists.', 'boulk-update-products' ) ),
			array( 'column' => 'Weight / Width / Length / Height', 'aliases' => '—', 'description' => __( 'Shipping dimensions.', 'boulk-update-products' ) ),
			array( 'column' => 'Units, Pkg/Qty', 'aliases' => '—', 'description' => __( 'Saved as product meta.', 'boulk-update-products' ) ),
			array( 'column' => 'Product_Group_Id / Product_Group_Desc', 'aliases' => '—', 'description' => __( 'Saved as product meta.', 'boulk-update-products' ) ),
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
			<p><strong><?php esc_html_e( 'Updating:', 'boulk-update-products' ); ?></strong> <?php echo esc_html( Boulk_UP_Update_Fields::format_selection_label( $job->get( 'update_fields', null ) ) ); ?></p>

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
				<li><strong><?php esc_html_e( 'Created:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-created"><?php echo esc_html( (string) $job->get( 'created', 0 ) ); ?></span></li>
				<li><strong><?php esc_html_e( 'Skipped:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-skipped"><?php echo esc_html( (string) $job->get( 'skipped', 0 ) ); ?></span></li>
				<li><strong><?php esc_html_e( 'Errors:', 'boulk-update-products' ); ?></strong> <span class="boulk-stat-errors"><?php echo esc_html( (string) $job->get( 'errors', 0 ) ); ?></span></li>
			</ul>
			<p class="description"><?php esc_html_e( 'New products are created automatically when a sku is in the CSV but not in WooCommerce.', 'boulk-update-products' ); ?></p>

			<p class="boulk-log-downloads">
				<?php if ( (int) $job->get( 'errors', 0 ) > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( $this->get_log_download_url( $job->get_id(), 'error' ) ); ?>">
						<?php
						printf(
							/* translators: %d: number of errors */
							esc_html__( 'Download errors CSV (%d)', 'boulk-update-products' ),
							(int) $job->get( 'errors', 0 )
						);
						?>
					</a>
				<?php endif; ?>
				<?php if ( (int) $job->get( 'skipped', 0 ) > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( $this->get_log_download_url( $job->get_id(), 'skipped' ) ); ?>">
						<?php
						printf(
							/* translators: %d: number of skipped rows */
							esc_html__( 'Download skipped CSV (%d)', 'boulk-update-products' ),
							(int) $job->get( 'skipped', 0 )
						);
						?>
					</a>
				<?php endif; ?>
				<?php if ( $job->get_log_file_path() ) : ?>
					<a class="button" href="<?php echo esc_url( $this->get_log_download_url( $job->get_id(), 'all' ) ); ?>">
						<?php esc_html_e( 'Download full log CSV', 'boulk-update-products' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<?php $this->render_job_issues_tables( $job ); ?>
		</div>
		<?php
	}

	/**
	 * Render errors and skipped SKU tables.
	 *
	 * @param Boulk_UP_Import_Job $job Job.
	 */
	private function render_job_issues_tables( $job ) {
		$errors       = $job->read_status_log( 'error', 500 );
		$skipped      = $job->read_status_log( 'skipped', 500 );
		$error_total  = (int) $job->get( 'errors', 0 );
		$skipped_total = (int) $job->get( 'skipped', 0 );
		?>
		<div class="boulk-issues-report">
			<h3><?php esc_html_e( 'Problems by SKU', 'boulk-update-products' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Review which SKUs failed or were skipped and why. Download the CSV files above for the complete list on large imports.', 'boulk-update-products' ); ?>
			</p>

			<div class="boulk-issues-grid">
				<div class="boulk-issues-box boulk-issues-errors">
					<h4>
						<span class="boulk-issue-icon boulk-issue-icon-error"></span>
						<?php
						printf(
							/* translators: %d: error count */
							esc_html__( 'Errors (%d)', 'boulk-update-products' ),
							$error_total
						);
						?>
					</h4>
					<p class="boulk-issue-help"><?php esc_html_e( 'Invalid row, missing SKU, or the product could not be saved.', 'boulk-update-products' ); ?></p>
					<?php if ( empty( $errors ) ) : ?>
						<p class="boulk-no-issues"><?php esc_html_e( 'No errors recorded yet.', 'boulk-update-products' ); ?></p>
					<?php else : ?>
						<?php $this->render_issue_table( $errors, 'error' ); ?>
						<?php if ( $error_total > count( $errors ) ) : ?>
							<p class="boulk-truncated-note">
								<?php
								printf(
									/* translators: 1: shown count, 2: total count */
									esc_html__( 'Showing first %1$d of %2$d errors. Download the errors CSV for the full list.', 'boulk-update-products' ),
									count( $errors ),
									$error_total
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="boulk-issues-box boulk-issues-skipped">
					<h4>
						<span class="boulk-issue-icon boulk-issue-icon-skipped"></span>
						<?php
						printf(
							/* translators: %d: skipped count */
							esc_html__( 'Skipped (%d)', 'boulk-update-products' ),
							$skipped_total
						);
						?>
					</h4>
					<p class="boulk-issue-help"><?php esc_html_e( 'SKU not found in WooCommerce, or no data to update for the fields you selected.', 'boulk-update-products' ); ?></p>
					<?php if ( empty( $skipped ) ) : ?>
						<p class="boulk-no-issues"><?php esc_html_e( 'No skipped rows recorded yet.', 'boulk-update-products' ); ?></p>
					<?php else : ?>
						<?php $this->render_issue_table( $skipped, 'skipped' ); ?>
						<?php if ( $skipped_total > count( $skipped ) ) : ?>
							<p class="boulk-truncated-note">
								<?php
								printf(
									/* translators: 1: shown count, 2: total count */
									esc_html__( 'Showing first %1$d of %2$d skipped rows. Download the skipped CSV for the full list.', 'boulk-update-products' ),
									count( $skipped ),
									$skipped_total
								);
								?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single issues table.
	 *
	 * @param array<int, array<string, mixed>> $entries Issue rows.
	 * @param string                           $type    error|skipped.
	 */
	private function render_issue_table( $entries, $type ) {
		$tbody_class = 'error' === $type ? 'boulk-error-entries' : 'boulk-skipped-entries';
		?>
		<div class="boulk-issue-table-wrap">
			<table class="widefat striped boulk-issue-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'CSV row', 'boulk-update-products' ); ?></th>
						<th><?php esc_html_e( 'SKU', 'boulk-update-products' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'boulk-update-products' ); ?></th>
					</tr>
				</thead>
				<tbody class="<?php echo esc_attr( $tbody_class ); ?>">
					<?php foreach ( array_reverse( $entries ) as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $entry['row'] ); ?></td>
							<td><code><?php echo esc_html( $entry['sku'] ); ?></code></td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Build download URL for job logs.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $log_type all|error|skipped.
	 * @return string
	 */
	private function get_log_download_url( $job_id, $log_type = 'all' ) {
		return wp_nonce_url(
			admin_url(
				'admin-post.php?action=boulk_up_download_log&job_id=' . rawurlencode( $job_id ) . '&log_type=' . rawurlencode( $log_type )
			),
			'boulk_up_download_log'
		);
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

		$update_fields = isset( $_POST['boulk_update_fields'] ) && is_array( $_POST['boulk_update_fields'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['boulk_update_fields'] ) )
			: array();

		$job = Boulk_UP_Import_Job::create( $file['tmp_name'], $dry_run, $profile, $update_fields, true );
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
						: __( 'Import started. Small files run immediately; large files continue in the background.', 'boulk-update-products' ),
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
	 * Download Automann-format sample CSV.
	 */
	public function handle_download_automann_sample() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'boulk-update-products' ) );
		}

		check_admin_referer( 'boulk_up_download_automann_sample' );

		$headers = array(
			'Automann Part Number',
			'Description',
			'Dist. Price List',
			'Dist. Net Price',
			'Units',
			'Pkg/Qty',
			'Weight',
			'Width',
			'Length',
			'Height',
			'sku',
			'Product_Group_Id',
			'Product_Group_Desc',
			'Master Category',
			'Brands',
			'Updated Price',
			'Stock status',
		);

		$example = array(
			'AUT-12345',
			'Example brake pad set',
			'45.00',
			'38.50',
			'EA',
			'1',
			'2.5',
			'8',
			'10',
			'4',
			'AUT-12345',
			'GRP-01',
			'Brake Components',
			'Brakes',
			'Automann',
			'42.99',
			'instock',
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=boulk-automann-import-template.csv' );
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

		$job_id   = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';
		$log_type = isset( $_GET['log_type'] ) ? sanitize_key( wp_unslash( $_GET['log_type'] ) ) : 'all';
		$job      = Boulk_UP_Import_Job::load( $job_id );

		if ( ! $job ) {
			wp_die( esc_html__( 'Job not found.', 'boulk-update-products' ) );
		}

		if ( ! in_array( $log_type, array( 'all', 'error', 'skipped' ), true ) ) {
			$log_type = 'all';
		}

		$filename = $job_id;
		if ( 'error' === $log_type ) {
			$filename .= '-errors';
		} elseif ( 'skipped' === $log_type ) {
			$filename .= '-skipped';
		} else {
			$filename .= '-full-log';
		}
		$filename .= '.csv';

		$path = $job->get_status_log_path( $log_type );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		if ( $path ) {
			readfile( $path );
			exit;
		}

		if ( 'all' === $log_type ) {
			wp_die( esc_html__( 'Log file not found.', 'boulk-update-products' ) );
		}

		$entries = $job->read_status_log( $log_type, 100000 );
		if ( empty( $entries ) ) {
			wp_die( esc_html__( 'No rows found for this log type.', 'boulk-update-products' ) );
		}

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'row', 'sku', 'reason', 'time' ) );
		foreach ( $entries as $entry ) {
			fputcsv( $out, array( $entry['row'], $entry['sku'], $entry['message'], isset( $entry['time'] ) ? $entry['time'] : '' ) );
		}
		fclose( $out );
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
				'created'   => (int) $job->get( 'created', 0 ),
				'skipped'   => (int) $job->get( 'skipped', 0 ),
				'errors'    => (int) $job->get( 'errors', 0 ),
				'percent'   => $job->get_progress_percent(),
				'finished'  => $job->is_finished(),
				'logEntries'      => array_reverse( array_slice( $job->get( 'log_entries', array() ), -20 ) ),
				'errorEntries'    => array_reverse( $job->get_cached_issues( 'error', 100 ) ),
				'skippedEntries'  => array_reverse( $job->get_cached_issues( 'skipped', 100 ) ),
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
