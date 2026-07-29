<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Admin_Dashboard
 * 
 * Manages WP Admin Menu under Supercraft Parent, Settings UI, Assets enqueuing, and Background Queue AJAX.
 */
class Supercraft_SEO_Admin_Dashboard {

	/**
	 * Main controller reference
	 * 
	 * @var Supercraft_SEO
	 */
	private $main;

	/**
	 * Constructor
	 *
	 * @param Supercraft_SEO $main Main plugin instance.
	 */
	public function __construct( $main ) {
		$this->main = $main;

		// Delay menu registration to priority 20 so it runs AFTER Supercraft Master Plugin
		add_action( 'admin_menu', array( $this, 'register_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Standalone Admin Post Handlers
		add_action( 'admin_post_supercraft_seo_save_embed_code', array( $this, 'handle_save_embed_code' ) );
		add_action( 'admin_post_supercraft_seo_unlink', array( $this, 'handle_unlink_embed_code' ) );

		// Register Background Worker Ticker Hooks
		add_action( 'wp_ajax_supercraft_seo_bg_tick', array( $this, 'ajax_bg_tick' ) );
		add_action( 'wp_ajax_nopriv_supercraft_seo_bg_tick', array( $this, 'ajax_bg_tick' ) );

		// Register Dashboard AJAX Actions
		add_action( 'wp_ajax_supercraft_seo_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_supercraft_seo_run_preflight', array( $this, 'ajax_run_preflight' ) );
		add_action( 'wp_ajax_supercraft_seo_update_site_title', array( $this, 'ajax_update_site_title' ) );
		add_action( 'wp_ajax_supercraft_seo_update_blog_public', array( $this, 'ajax_update_blog_public' ) );
		
		// Server-Side Background Queue AJAX
		add_action( 'wp_ajax_supercraft_seo_start_bg_queue', array( $this, 'ajax_start_bg_queue' ) );
		add_action( 'wp_ajax_supercraft_seo_stop_bg_queue', array( $this, 'ajax_stop_bg_queue' ) );
		add_action( 'wp_ajax_supercraft_seo_get_queue_status', array( $this, 'ajax_get_queue_status' ) );
		
		// H1, Meta, & ALT Auto-Fix AJAX Actions
		add_action( 'wp_ajax_supercraft_seo_fix_h1', array( $this, 'ajax_fix_h1' ) );
		add_action( 'wp_ajax_supercraft_seo_fix_meta', array( $this, 'ajax_fix_meta' ) );
		add_action( 'wp_ajax_supercraft_seo_fix_image_alts', array( $this, 'ajax_fix_image_alts' ) );
	}

	/**
	 * Register Supercraft SEO page under Supercraft Master parent menu.
	 */
	public function register_menu_page() {
		global $menu;

		$supercraft_parent_slug = '';
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[0] ) && false !== strpos( $item[0], 'Supercraft' ) ) {
					$supercraft_parent_slug = isset( $item[2] ) ? $item[2] : '';
					break;
				}
			}
		}

		if ( $supercraft_parent_slug ) {
			add_submenu_page(
				$supercraft_parent_slug,
				__( 'Supercraft Technical SEO', 'supercraft-seo' ),
				__( 'Technical SEO', 'supercraft-seo' ),
				'manage_options',
				'supercraft-seo',
				array( $this, 'render_dashboard_page' )
			);
		} else {
			add_menu_page(
				__( 'Supercraft Technical SEO', 'supercraft-seo' ),
				__( 'Supercraft', 'supercraft-seo' ),
				'manage_options',
				'supercraft-seo',
				array( $this, 'render_dashboard_page' ),
				'dashicons-chart-bar',
				30
			);
			add_submenu_page(
				'supercraft-seo',
				__( 'Supercraft Technical SEO', 'supercraft-seo' ),
				__( 'Technical SEO', 'supercraft-seo' ),
				'manage_options',
				'supercraft-seo',
				array( $this, 'render_dashboard_page' )
			);
		}
	}

	/**
	 * Enqueue CSS and JS assets on plugin page.
	 *
	 * @param string $hook Admin page hook suffix.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'supercraft-seo' ) ) {
			return;
		}

		// Load Tailwind CSS CDN for modern utility class support
		wp_enqueue_script(
			'tailwindcss-cdn',
			'https://cdn.tailwindcss.com',
			array(),
			'3.4.1'
		);

		wp_enqueue_style(
			'supercraft-seo-admin-css',
			SUPERCRAFT_SEO_URL . 'assets/css/admin.css',
			array(),
			SUPERCRAFT_SEO_VERSION
		);

		wp_enqueue_script(
			'supercraft-seo-admin-js',
			SUPERCRAFT_SEO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SUPERCRAFT_SEO_VERSION,
			true
		);

		wp_localize_script( 'supercraft-seo-admin-js', 'supercraftSEO', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'supercraft_seo_nonce' ),
			'isValidated' => Supercraft_SEO_Validation::is_validated(),
			'siteTitle'   => get_bloginfo( 'name' ),
			'strings'     => array(
				'scanning'     => __( 'Scanning & Auto-Fixing...', 'supercraft-seo' ),
				'complete'     => __( 'Audit & AI Sync Complete!', 'supercraft-seo' ),
				'error'        => __( 'An error occurred during process.', 'supercraft-seo' ),
				'aioseoActive' => $this->main->aioseo_bridge->is_aioseo_active(),
			),
		) );
	}

	/**
	 * Render WP Admin Dashboard HTML page.
	 */
	public function render_dashboard_page() {
		$is_validated   = Supercraft_SEO_Validation::is_validated();
		$is_master      = Supercraft_SEO_Validation::is_master_plugin_active();
		$embed_code     = Supercraft_SEO_Validation::get_embed_code();
		$model          = get_option( 'supercraft_seo_openai_model', 'gpt-4o-mini' );
		$brand_voice    = get_option( 'supercraft_seo_brand_voice', 'Professional, authoritative, yet engaging' );

		// Fetch available pages & posts for manual page selection list
		$available_posts = get_posts( array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<div class="wrap supercraft-seo-wrap">
			<!-- Header Banner -->
			<div class="supercraft-header-card">
				<div class="supercraft-brand">
					<div class="supercraft-logo-badge">⚡ SUPERCRAFT</div>
					<h1>Technical SEO & AIOSEO AI Engine</h1>
					<p>One-click Technical SEO suite. Automatically populates AIOSEO meta tags via AI and performs comprehensive post-completion audits.</p>
				</div>
				<div class="supercraft-status-badge <?php echo $is_validated ? 'active' : 'inactive'; ?>">
					<span class="status-indicator"></span>
					<?php echo $is_validated ? __( 'License Validated', 'supercraft-seo' ) : __( 'License Required', 'supercraft-seo' ); ?>
				</div>
			</div>

			<!-- Master Plugin Integration Notice -->
			<?php if ( $is_master ) : ?>
				<div class="supercraft-notice">
					<p>🛡️ License validation is managed globally by the <strong>Supercraft Master Plugin</strong>.</p>
				</div>
			<?php endif; ?>

			<!-- Main Layout Grid -->
			<div class="supercraft-dashboard-grid">
				
				<!-- Left Column: Validation & Settings Panel -->
				<div class="supercraft-column-settings">
					
					<!-- Standalone Validation Box (Hidden if Master plugin active) -->
					<?php if ( ! $is_master ) : ?>
						<div class="supercraft-card">
							<h2>🔑 License Verification</h2>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'supercraft_seo_save_embed' ); ?>
								<input type="hidden" name="action" value="supercraft_seo_save_embed_code" />

								<div class="supercraft-field">
									<label for="supercraft_embed_code"><?php _e( 'Embed / License Code', 'supercraft-seo' ); ?></label>
									<input type="text" id="supercraft_embed_code" name="supercraft_embed_code" value="<?php echo esc_attr( $embed_code ); ?>" <?php echo $is_validated ? 'readonly' : ''; ?> placeholder="Enter embed code..." class="regular-text" />
								</div>

								<?php if ( ! $is_validated ) : ?>
									<button type="submit" class="button button-primary supercraft-btn-save">
										<?php _e( 'Validate & Activate', 'supercraft-seo' ); ?>
									</button>
								<?php else : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=supercraft_seo_unlink' ), 'supercraft_seo_unlink' ) ); ?>" class="button button-secondary" onclick="return confirm('Unlink license?');">
										<?php _e( 'Unlink License', 'supercraft-seo' ); ?>
									</a>
								<?php endif; ?>
							</form>
						</div>
					<?php endif; ?>

					<!-- AI & Brand Settings Card -->
					<div class="supercraft-card">
						<h2>⚙️ AI Generation Preferences</h2>
						<form id="supercraft-seo-settings-form">
							<div class="supercraft-field">
								<label for="openai_model"><?php _e( 'AI Model Target', 'supercraft-seo' ); ?></label>
								<select id="openai_model" name="openai_model">
									<option value="gpt-4o-mini" <?php selected( $model, 'gpt-4o-mini' ); ?>>GPT-4o Mini (Fast & Cost Effective - Recommended)</option>
									<option value="gpt-4o" <?php selected( $model, 'gpt-4o' ); ?>>GPT-4o (Maximum Quality)</option>
								</select>
							</div>

							<div class="supercraft-field">
								<label for="brand_voice"><?php _e( 'Brand Tone & Context', 'supercraft-seo' ); ?></label>
								<textarea id="brand_voice" name="brand_voice" rows="3" placeholder="e.g. Professional, authoritative, local business"><?php echo esc_textarea( $brand_voice ); ?></textarea>
							</div>

							<button type="submit" class="button button-primary supercraft-btn-save">
								<?php _e( 'Save Preferences', 'supercraft-seo' ); ?>
							</button>
							<span class="supercraft-save-msg" style="display:none;margin-left:10px;font-size:12px;font-weight:600;"></span>
						</form>
					</div>

					<div class="supercraft-card info-card">
						<h2 style="font-size:14px;margin-bottom:8px;border:none;">🌐 Endpoint Integration</h2>
						<p style="font-size:12px;color:#64748b;margin:0;">Connected to Supercraft endpoint:<br/><code style="font-size:11px;color:#4338ca;background:#eef2ff;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:4px;">https://superapp.supercraft.my</code></p>
					</div>
				</div>

				<!-- Right Column: One-Click Action & Audit Dashboard -->
				<div class="supercraft-column-action">
					
					<!-- Pre-Flight Health Check Card -->
					<div class="supercraft-card preflight-card">
						<div class="preflight-header">
							<h2>🩺 Pre-Flight Site Health Check</h2>
							<button id="supercraft-run-preflight-btn" class="button button-secondary button-small">
								<span class="dashicons dashicons-update"></span> Run Pre-Flight Scan
							</button>
						</div>
						<p class="description">Verifies site title quality, search indexing visibility, and permalink structures before launching AI meta generation.</p>
						
						<div id="supercraft-preflight-results" class="preflight-list">
							<div class="preflight-loading">Click "Run Pre-Flight Scan" to check site identity settings.</div>
						</div>
					</div>

					<!-- Hero Action & Target Selection Card -->
					<div class="supercraft-card hero-action-card">
						<div class="hero-action-header">
							<h2>⚡ Server Background Technical SEO Engine</h2>
							<p>Runs in the background on your server. You can freely navigate to other pages or close your browser while it works.</p>
						</div>

						<!-- Target Selection Radio Controls -->
						<div class="target-selection-box">
							<div class="target-selection-label">🎯 Target Selection Scope:</div>
							<div class="target-radio-group">
								<label class="target-radio-label">
									<input type="radio" name="target_mode" value="all" checked />
									<span><strong>All Pages & Posts</strong> (Entire Site)</span>
								</label>
								<label class="target-radio-label">
									<input type="radio" name="target_mode" value="selected" />
									<span><strong>Select Specific Pages / Posts</strong></span>
								</label>
							</div>
						</div>

						<!-- Page Picker Checklist Container -->
						<div id="page-picker-container" class="page-picker-wrapper" style="display: none;">
							<div class="page-picker-toolbar">
								<input type="text" id="page-search-input" placeholder="🔍 Filter pages by title..." class="page-search-field" />
								<div class="page-picker-actions">
									<button type="button" id="btn-select-all-pages" class="button button-small">Select All</button>
									<button type="button" id="btn-deselect-all-pages" class="button button-small">Deselect All</button>
								</div>
							</div>

							<div class="page-checklist-scroll">
								<?php if ( ! empty( $available_posts ) ) : ?>
									<?php foreach ( $available_posts as $post_obj ) : ?>
										<?php
										$is_elem = get_post_meta( $post_obj->ID, '_elementor_edit_mode', true );
										?>
										<label class="page-checkbox-item" data-title="<?php echo esc_attr( strtolower( $post_obj->post_title ) ); ?>">
											<input type="checkbox" name="selected_post_ids[]" value="<?php echo esc_attr( $post_obj->ID ); ?>" class="page-item-checkbox" />
											<span class="page-item-title"><?php echo esc_html( $post_obj->post_title ); ?></span>
											<span class="page-item-meta">
												<span class="page-type-tag"><?php echo esc_html( strtoupper( $post_obj->post_type ) ); ?></span>
												<?php if ( 'builder' === $is_elem ) : ?>
													<span class="elementor-tag">Elementor</span>
												<?php endif; ?>
											</span>
										</label>
									<?php endforeach; ?>
								<?php else : ?>
									<div style="padding:12px;color:#64748b;font-size:13px;">No pages or posts found.</div>
								<?php endif; ?>
							</div>
						</div>

						<div class="hero-action-buttons" style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:20px;">
							<?php if ( $is_validated ) : ?>
								<button id="supercraft-start-oneclick" class="supercraft-btn-launch">
									<span class="dashicons dashicons-lightning"></span> <span id="launch-btn-text">Run One-Click Technical SEO (All Pages)</span>
								</button>
								<button id="supercraft-stop-bg" class="supercraft-btn-stop" style="display:none;">
									<span class="dashicons dashicons-no-alt"></span> 🛑 Stop Background Process
								</button>
							<?php else : ?>
								<button class="supercraft-btn-launch" disabled style="opacity:0.5;cursor:not-allowed;">
									🔒 License Validation Required
								</button>
							<?php endif; ?>
						</div>

						<!-- Live Progress Bar -->
						<div id="supercraft-progress-container" class="supercraft-progress-wrapper" style="display: none;">
							<div class="supercraft-progress-header">
								<span id="supercraft-progress-text">Initializing background server queue...</span>
								<span id="supercraft-progress-percent">0%</span>
							</div>
							<div class="supercraft-progress-bar-track">
								<div id="supercraft-progress-bar-fill" class="supercraft-progress-bar-fill" style="width: 0%;"></div>
							</div>
						</div>
					</div>

					<!-- Filter & Results Container -->
					<div id="supercraft-results-card" class="supercraft-card" style="display: none;">
						<div class="results-header">
							<h3>📊 Technical SEO & Auto-Fix Audit Report</h3>
							<div class="results-filter-pills">
								<button class="pill-btn active" data-filter="all">All Pages (<span id="count-all">0</span>)</button>
								<button class="pill-btn pill-fixed" data-filter="fixed">🟢 Auto-Fixed (<span id="count-fixed">0</span>)</button>
								<button class="pill-btn pill-warning" data-filter="warning">🟡 Warnings (<span id="count-warning">0</span>)</button>
								<button class="pill-btn pill-critical" data-filter="critical">🔴 Critical (<span id="count-critical">0</span>)</button>
							</div>
						</div>

						<!-- Results Items Container -->
						<div id="supercraft-audit-results" class="results-list"></div>
					</div>
				</div>

			</div>
		</div>

		<!-- H1 Fix Scope Modal Dialog -->
		<div id="supercraft-h1-modal" class="supercraft-modal-overlay" style="display:none;">
			<div class="supercraft-modal-card">
				<h3 style="margin-top:0;color:#0f172a;font-size:18px;">🏷️ Fix Missing H1 Heading</h3>
				<p style="color:#475569;font-size:14px;line-height:1.5;">Would you like to promote the hero heading to <strong>H1</strong> for this page only, or auto-fix <strong>all pages site-wide</strong> missing H1 tags?</p>
				
				<div class="supercraft-modal-actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
					<button type="button" id="btn-h1-fix-single" class="button button-primary">Fix This Page Only</button>
					<button type="button" id="btn-h1-fix-all" class="button button-secondary">Fix All Pages Site-Wide</button>
					<button type="button" id="btn-h1-modal-cancel" class="button">Cancel</button>
				</div>
			</div>
		</div>

		<!-- Meta AI Fix Scope Modal Dialog -->
		<div id="supercraft-meta-modal" class="supercraft-modal-overlay" style="display:none;">
			<div class="supercraft-modal-card">
				<h3 style="margin-top:0;color:#0f172a;font-size:18px;">⚡ Fix Meta Title & Description via AI</h3>
				<p style="color:#475569;font-size:14px;line-height:1.5;">Re-generate optimized AI metadata (45-60 char titles, 145-158 char descriptions with CTAs) for <strong>this page only</strong> or <strong>all pages site-wide</strong> with meta warnings?</p>
				
				<div class="supercraft-modal-actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
					<button type="button" id="btn-meta-fix-single" class="button button-primary">Fix This Page Only</button>
					<button type="button" id="btn-meta-fix-all" class="button button-secondary">Fix All Pages Site-Wide</button>
					<button type="button" id="btn-meta-modal-cancel" class="button">Cancel</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Background Worker Ticker Webhook
	 */
	public function ajax_bg_tick() {
		$this->main->background_worker->process_next_item();
		wp_send_json_success( array( 'tick' => true ) );
	}

	/**
	 * AJAX: Start Background Queue
	 */
	public function ajax_start_bg_queue() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! Supercraft_SEO_Validation::is_validated() ) {
			wp_send_json_error( array( 'message' => __( 'License validation required.', 'supercraft-seo' ) ) );
		}

		$post_ids = array();
		if ( ! empty( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ) {
			$post_ids = array_values( array_map( 'absint', $_POST['post_ids'] ) );
		} else {
			$query_args = array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			);
			$post_ids = get_posts( $query_args );
		}

		if ( empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No pages or posts selected to process.', 'supercraft-seo' ) ) );
		}

		$state = $this->main->background_worker->start_queue( $post_ids );

		wp_send_json_success( array(
			'message' => __( 'Server background queue started!', 'supercraft-seo' ),
			'state'   => $state,
		) );
	}

	/**
	 * AJAX: Stop Background Queue
	 */
	public function ajax_stop_bg_queue() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		$this->main->background_worker->stop_queue();

		wp_send_json_success( array(
			'message' => __( 'Background process stopped successfully.', 'supercraft-seo' ),
		) );
	}

	/**
	 * AJAX: Get Server Queue Status
	 */
	public function ajax_get_queue_status() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		$status = $this->main->background_worker->get_queue_status();
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: 1-Click Fix H1 Headings
	 */
	public function ajax_fix_h1() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! Supercraft_SEO_Validation::is_validated() ) {
			wp_send_json_error( array( 'message' => __( 'License validation required.', 'supercraft-seo' ) ) );
		}

		$scope   = isset( $_POST['scope'] ) ? sanitize_text_field( $_POST['scope'] ) : 'single';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$fixed_count = 0;

		if ( 'all' === $scope ) {
			$all_ids = get_posts( array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			foreach ( $all_ids as $id ) {
				$page_data = $this->main->elementor_parser->get_page_content( $id );
				$h1_count  = count( isset( $page_data['headings']['h1'] ) ? $page_data['headings']['h1'] : array() );

				if ( 0 === $h1_count ) {
					if ( $this->main->elementor_parser->promote_first_heading_to_h1( $id ) ) {
						$fixed_count++;
					}
				}
			}

			wp_send_json_success( array(
				'message'     => sprintf( __( 'Successfully promoted primary hero heading to H1 on %d pages site-wide!', 'supercraft-seo' ), $fixed_count ),
				'fixed_count' => $fixed_count,
			) );
		} else {
			if ( ! $post_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'supercraft-seo' ) ) );
			}

			$res = $this->main->elementor_parser->promote_first_heading_to_h1( $post_id );
			if ( $res ) {
				wp_send_json_success( array(
					'message' => __( 'Primary hero heading successfully promoted to H1!', 'supercraft-seo' ),
					'post_id' => $post_id,
				) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Could not find heading widget to promote.', 'supercraft-seo' ) ) );
			}
		}
	}

	/**
	 * AJAX: 1-Click Fix Meta Title / Description via AI
	 */
	public function ajax_fix_meta() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! Supercraft_SEO_Validation::is_validated() ) {
			wp_send_json_error( array( 'message' => __( 'License validation required.', 'supercraft-seo' ) ) );
		}

		$scope   = isset( $_POST['scope'] ) ? sanitize_text_field( $_POST['scope'] ) : 'single';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$fixed_count = 0;

		if ( 'all' === $scope ) {
			$all_ids = get_posts( array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );

			foreach ( $all_ids as $id ) {
				$page_data = $this->main->elementor_parser->get_page_content( $id );
				$ai_res    = $this->main->openai_service->generate_seo_metadata( $id, $page_data );

				if ( ! is_wp_error( $ai_res ) && ! empty( $ai_res['meta_title'] ) ) {
					$this->main->aioseo_bridge->save_seo_metadata( $id, $ai_res );
					$fixed_count++;
				}
			}

			wp_send_json_success( array(
				'message'     => sprintf( __( 'Successfully re-generated optimized AI meta title & description for %d pages site-wide!', 'supercraft-seo' ), $fixed_count ),
				'fixed_count' => $fixed_count,
			) );
		} else {
			if ( ! $post_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'supercraft-seo' ) ) );
			}

			$page_data = $this->main->elementor_parser->get_page_content( $post_id );
			$ai_res    = $this->main->openai_service->generate_seo_metadata( $post_id, $page_data );

			if ( is_wp_error( $ai_res ) ) {
				wp_send_json_error( array( 'message' => $ai_res->get_error_message() ) );
			}

			$this->main->aioseo_bridge->save_seo_metadata( $post_id, $ai_res );

			wp_send_json_success( array(
				'message'  => __( 'AI meta title & description re-generated and synced with AIOSEO!', 'supercraft-seo' ),
				'post_id'  => $post_id,
				'seo_data' => $ai_res,
			) );
		}
	}

	/**
	 * AJAX: Run Pre-Flight Site Health Check
	 */
	public function ajax_run_preflight() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		$res = $this->main->seo_auditor->run_preflight_check();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Update Site Title
	 */
	public function ajax_update_site_title() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'supercraft-seo' ) ) );
		}

		$new_title = isset( $_POST['new_title'] ) ? sanitize_text_field( $_POST['new_title'] ) : '';
		if ( empty( $new_title ) ) {
			wp_send_json_error( array( 'message' => __( 'Site title cannot be empty.', 'supercraft-seo' ) ) );
		}

		update_option( 'blogname', $new_title );

		wp_send_json_success( array(
			'message'   => sprintf( __( 'Site title updated to "%s"!', 'supercraft-seo' ), esc_html( $new_title ) ),
			'new_title' => $new_title,
		) );
	}

	/**
	 * AJAX: Unblock Search Engine Indexing
	 */
	public function ajax_update_blog_public() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'supercraft-seo' ) ) );
		}

		update_option( 'blog_public', '1' );

		wp_send_json_success( array(
			'message' => __( 'Search engine indexing is now open to crawlers!', 'supercraft-seo' ),
		) );
	}

	/**
	 * Admin Post: Save Embed Code Standalone
	 */
	public function handle_save_embed_code() {
		check_admin_referer( 'supercraft_seo_save_embed' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$code = isset( $_POST['supercraft_embed_code'] ) ? sanitize_text_field( $_POST['supercraft_embed_code'] ) : '';
		update_option( 'supercraft_seo_embed_code', $code );

		if ( ! empty( $code ) ) {
			$valid = Supercraft_SEO_Validation::validate_embed_code_standalone( $code );
			update_option( 'supercraft_seo_validation_status', $valid ? 'valid' : 'invalid' );
		} else {
			update_option( 'supercraft_seo_validation_status', 'not_set' );
		}

		wp_redirect( add_query_arg( 'updated', 'true', wp_get_referer() ) );
		exit;
	}

	/**
	 * Admin Post: Unlink License Standalone
	 */
	public function handle_unlink_embed_code() {
		check_admin_referer( 'supercraft_seo_unlink' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied' );
		}

		$code = Supercraft_SEO_Validation::get_embed_code();
		if ( ! empty( $code ) ) {
			Supercraft_SEO_Validation::delete_registration( $code );
		}

		update_option( 'supercraft_seo_embed_code', '' );
		update_option( 'supercraft_seo_validation_status', 'not_set' );

		wp_redirect( add_query_arg( 'updated', 'true', wp_get_referer() ) );
		exit;
	}

	/**
	 * AJAX: Save Settings
	 */
	public function ajax_save_settings() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'supercraft-seo' ) ) );
		}

		$model       = isset( $_POST['openai_model'] ) ? sanitize_text_field( $_POST['openai_model'] ) : 'gpt-4o-mini';
		$brand_voice = isset( $_POST['brand_voice'] ) ? sanitize_textarea_field( $_POST['brand_voice'] ) : '';

		update_option( 'supercraft_seo_openai_model', $model );
		update_option( 'supercraft_seo_brand_voice', $brand_voice );

		wp_send_json_success( array( 'message' => __( 'Preferences saved successfully!', 'supercraft-seo' ) ) );
	}

	/**
	 * AJAX: Fix Image Alt Texts
	 */
	public function ajax_fix_image_alts() {
		check_ajax_referer( 'supercraft_seo_nonce', 'nonce' );

		if ( ! Supercraft_SEO_Validation::is_validated() ) {
			wp_send_json_error( array( 'message' => __( 'License validation required.', 'supercraft-seo' ) ) );
		}

		$alts = isset( $_POST['alts'] ) ? $_POST['alts'] : array();
		if ( empty( $alts ) || ! is_array( $alts ) ) {
			wp_send_json_error( array( 'message' => __( 'No image alt data provided.', 'supercraft-seo' ) ) );
		}

		$updated_count = 0;
		foreach ( $alts as $item ) {
			if ( ! empty( $item['url'] ) && ! empty( $item['alt_text'] ) ) {
				$attachment_id = attachment_url_to_postid( esc_url_raw( $item['url'] ) );
				if ( $attachment_id > 0 ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $item['alt_text'] ) );
					$updated_count++;
				}
			}
		}

		wp_send_json_success( array(
			'message' => sprintf( __( 'Successfully updated %d image ALT tags in media library!', 'supercraft-seo' ), $updated_count ),
		) );
	}
}
