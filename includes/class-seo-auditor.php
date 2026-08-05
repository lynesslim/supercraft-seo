<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Auditor
 * 
 * Performs automated Technical SEO checks and Pre-Flight site-wide health diagnostics.
 * Multibyte and CJK (Chinese, Japanese, Korean) aware.
 */
class Supercraft_SEO_Auditor {

	/**
	 * Run Pre-Flight Site-Wide Technical SEO Health Check.
	 * Evaluates brand name quality, search engine indexing settings, permalinks, and SSL.
	 *
	 * @return array Pre-flight diagnostic summary.
	 */
	public function run_preflight_check() {
		$checks = array();
		$can_proceed = true;

		// 1. Site Brand Name Quality Check
		$raw_site_name = get_bloginfo( 'name' );
		$suggested_brand = $this->clean_brand_name( $raw_site_name );
		$is_raw_domain   = preg_match( '/\.(com|com\.my|my|net|org|io|co|app)\b/i', $raw_site_name ) || 'Just another WordPress site' === $raw_site_name || strlen( trim( $raw_site_name ) ) < 2;

		if ( $is_raw_domain ) {
			$checks[] = array(
				'code'            => 'raw_brand_name',
				'type'            => 'warning',
				'title'           => __( 'Raw Domain or Default Site Title Detected', 'supercraft-seo' ),
				'message'         => sprintf( __( 'Your WordPress Site Title is set to "%s". We recommend changing it to a clean brand name like "%s" so AI meta tags look polished.', 'supercraft-seo' ), esc_html( $raw_site_name ), esc_html( $suggested_brand ) ),
				'fixable'         => true,
				'current_name'    => $raw_site_name,
				'suggested_name'  => $suggested_brand,
			);
		} else {
			$checks[] = array(
				'code'    => 'clean_brand_name',
				'type'    => 'passed',
				'title'   => __( 'Site Title & Brand Name', 'supercraft-seo' ),
				'message' => sprintf( __( 'Clean brand name configured: "%s"', 'supercraft-seo' ), esc_html( $raw_site_name ) ),
			);
		}

		// 2. Global Search Engine Indexing Visibility (blog_public)
		$blog_public = get_option( 'blog_public' );
		if ( '0' === (string) $blog_public ) {
			$checks[] = array(
				'code'    => 'search_indexing_blocked',
				'type'    => 'critical',
				'title'   => __( 'Search Engine Indexing is Blocked', 'supercraft-seo' ),
				'message' => __( 'WordPress setting "Discourage search engines from indexing this site" is enabled. Search engines will ignore your entire site.', 'supercraft-seo' ),
				'fixable' => true,
			);
			$can_proceed = false;
		} else {
			$checks[] = array(
				'code'    => 'search_indexing_open',
				'type'    => 'passed',
				'title'   => __( 'Global Indexing Status', 'supercraft-seo' ),
				'message' => __( 'Site is open to search engine indexing.', 'supercraft-seo' ),
			);
		}

		// 3. Permalinks Structure Check
		$permalink_structure = get_option( 'permalink_structure' );
		if ( empty( $permalink_structure ) ) {
			$checks[] = array(
				'code'    => 'plain_permalinks',
				'type'    => 'warning',
				'title'   => __( 'Plain Permalinks Detected (?p=123)', 'supercraft-seo' ),
				'message' => __( 'Your site uses plain URL permalinks. Switching to "Post Name" permalinks is highly recommended for SEO.', 'supercraft-seo' ),
				'fixable' => false,
			);
		} else {
			$checks[] = array(
				'code'    => 'pretty_permalinks',
				'type'    => 'passed',
				'title'   => __( 'Permalink Structure', 'supercraft-seo' ),
				'message' => __( 'SEO-friendly permalink structure is active.', 'supercraft-seo' ),
			);
		}

		// 4. AIOSEO Plugin Integration Status
		$aioseo_bridge = new Supercraft_SEO_AIOSEO_Bridge();
		if ( $aioseo_bridge->is_aioseo_active() ) {
			$checks[] = array(
				'code'    => 'aioseo_active',
				'type'    => 'passed',
				'title'   => __( 'AIOSEO Connection', 'supercraft-seo' ),
				'message' => __( 'All in One SEO (AIOSEO) engine connected.', 'supercraft-seo' ),
			);
		} else {
			$checks[] = array(
				'code'    => 'aioseo_inactive',
				'type'    => 'warning',
				'title'   => __( 'AIOSEO Engine Not Installed', 'supercraft-seo' ),
				'message' => __( 'AIOSEO plugin is not active. Supercraft will save metadata to standard post meta.', 'supercraft-seo' ),
				'fixable' => false,
			);
		}

		// 5. Site Icon / Favicon Check
		if ( ! has_site_icon() ) {
			$checks[] = array(
				'code'    => 'missing_favicon',
				'type'    => 'warning',
				'title'   => __( 'Site Icon (Favicon) Missing', 'supercraft-seo' ),
				'message' => __( 'No site icon (favicon) set. Upload a site icon in WordPress Settings / Customizer so your brand logo appears in Google search results.', 'supercraft-seo' ),
				'fixable' => false,
			);
		} else {
			$checks[] = array(
				'code'    => 'favicon_active',
				'type'    => 'passed',
				'title'   => __( 'Site Icon (Favicon)', 'supercraft-seo' ),
				'message' => __( 'Site icon is configured for search engine results pages.', 'supercraft-seo' ),
			);
		}

		// 6. Site Tagline Quality Check
		$raw_tagline = get_bloginfo( 'description' );
		if ( empty( trim( $raw_tagline ) ) || 'Just another WordPress site' === $raw_tagline ) {
			$checks[] = array(
				'code'    => 'raw_tagline',
				'type'    => 'warning',
				'title'   => __( 'Default or Missing Site Tagline', 'supercraft-seo' ),
				'message' => __( 'Site tagline is missing or default ("Just another WordPress site"). A clear tagline helps AI craft better brand context.', 'supercraft-seo' ),
				'fixable' => false,
			);
		} else {
			$checks[] = array(
				'code'    => 'clean_tagline',
				'type'    => 'passed',
				'title'   => __( 'Site Tagline', 'supercraft-seo' ),
				'message' => sprintf( __( 'Site tagline configured: "%s"', 'supercraft-seo' ), esc_html( $raw_tagline ) ),
			);
		}

		// 7. SSL / HTTPS Security Check
		$site_url  = get_option( 'siteurl' );
		$is_https  = ( 0 === strpos( $site_url, 'https://' ) ) || is_ssl();
		if ( ! $is_https ) {
			$checks[] = array(
				'code'    => 'http_unsecured',
				'type'    => 'warning',
				'title'   => __( 'HTTP Protocol (Unsecured)', 'supercraft-seo' ),
				'message' => __( 'Site URL does not use HTTPS. Google treats HTTPS as a positive search ranking factor.', 'supercraft-seo' ),
				'fixable' => false,
			);
		} else {
			$checks[] = array(
				'code'    => 'https_secured',
				'type'    => 'passed',
				'title'   => __( 'SSL / HTTPS Security', 'supercraft-seo' ),
				'message' => __( 'Site URL is serving securely over HTTPS.', 'supercraft-seo' ),
			);
		}

		// 8. Supercraft License & Validation Check
		if ( class_exists( 'Supercraft_SEO_Validation' ) && ! Supercraft_SEO_Validation::is_validated() ) {
			$checks[] = array(
				'code'    => 'unvalidated_license',
				'type'    => 'warning',
				'title'   => __( 'Supercraft Plugin License Unvalidated', 'supercraft-seo' ),
				'message' => __( 'Plugin is not validated. Enter your embed code or activate the Supercraft Master Plugin to enable full AI metadata capabilities.', 'supercraft-seo' ),
				'fixable' => false,
			);
		} else {
			$checks[] = array(
				'code'    => 'validated_license',
				'type'    => 'passed',
				'title'   => __( 'Supercraft License Status', 'supercraft-seo' ),
				'message' => __( 'Supercraft license active and validated.', 'supercraft-seo' ),
			);
		}

		return array(
			'can_proceed' => $can_proceed,
			'checks'      => $checks,
			'site_title'  => $raw_site_name,
		);
	}

	/**
	 * Convert a raw domain string like ylbrands.com.my into a clean Brand Name.
	 *
	 * @param string $raw Raw site title.
	 * @return string Cleaned brand name.
	 */
	public function clean_brand_name( $raw ) {
		$clean = preg_replace( '#^https?://#i', '', trim( $raw ) );
		$clean = preg_replace( '/\.(com\.my|com|my|net|org|io|co|app)\b/i', '', $clean );
		$clean = str_replace( array( '-', '.', '_' ), ' ', $clean );
		$clean = ucwords( strtolower( trim( $clean ) ) );

		return ! empty( $clean ) ? $clean : 'My Brand';
	}

	/**
	 * Run a technical SEO audit for a single post.
	 *
	 * @param int   $post_id Post ID to inspect.
	 * @param array $page_data Extracted page content payload from Elementor parser.
	 * @param array $seo_meta Existing or generated SEO metadata.
	 * @return array Audit result summary containing issues list, score, and pass/fail statuses.
	 */
	public function run_audit( $post_id, $page_data, $seo_meta = array() ) {
		$issues = array();
		$passed = array();
		$score  = 100;

		if ( ! is_array( $page_data ) ) {
			$page_data = array();
		}
		if ( ! is_array( $seo_meta ) ) {
			$seo_meta = array();
		}

		$post_status    = get_post_status( $post_id );
		$post_permalink = get_permalink( $post_id );

		// 1. Heading Hierarchy Audit (PHP 8 Countable safe)
		$h1_count = ( ! empty( $page_data['headings']['h1'] ) && is_array( $page_data['headings']['h1'] ) ) ? count( $page_data['headings']['h1'] ) : 0;

		if ( 0 === $h1_count ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'missing_h1',
				'title'   => __( 'Missing H1 Heading', 'supercraft-seo' ),
				'message' => __( 'This page has no H1 tag. A clear H1 heading is vital for search engine indexing and accessibility.', 'supercraft-seo' ),
				'fixable' => true,
			);
			$score -= 20;
		} elseif ( $h1_count > 1 ) {
			$issues[] = array(
				'type'    => 'warning',
				'code'    => 'multiple_h1',
				'title'   => __( 'Multiple H1 Headings Detected', 'supercraft-seo' ),
				'message' => sprintf( __( 'Found %d H1 tags. Best practice recommended is exactly 1 H1 heading per page.', 'supercraft-seo' ), $h1_count ),
				'fixable' => false,
			);
			$score -= 10;
		} else {
			$passed[] = __( 'Heading Structure: Exactly 1 H1 heading present.', 'supercraft-seo' );
		}

		// 2. Content Length Audit
		$word_count = isset( $page_data['word_count'] ) ? (int) $page_data['word_count'] : 0;
		if ( $word_count < 100 ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'extremely_thin_content',
				'title'   => __( 'Extremely Thin Content', 'supercraft-seo' ),
				'message' => sprintf( __( 'Page contains only %d words. Search engines prefer robust content (>300 words).', 'supercraft-seo' ), $word_count ),
				'fixable' => false,
			);
			$score -= 25;
		} elseif ( $word_count < 300 ) {
			$issues[] = array(
				'type'    => 'warning',
				'code'    => 'thin_content',
				'title'   => __( 'Thin Content Warning', 'supercraft-seo' ),
				'message' => sprintf( __( 'Page contains %d words. Consider adding more descriptive copy.', 'supercraft-seo' ), $word_count ),
				'fixable' => false,
			);
			$score -= 10;
		} else {
			$passed[] = sprintf( __( 'Content Depth: %d words (Sufficient length).', 'supercraft-seo' ), $word_count );
		}

		// 3. Image Alt Attribute Audit
		$missing_alt_images = array();
		if ( ! empty( $page_data['images'] ) && is_array( $page_data['images'] ) ) {
			foreach ( $page_data['images'] as $img ) {
				if ( is_array( $img ) && empty( $img['alt'] ) && ! empty( $img['url'] ) ) {
					$missing_alt_images[] = $img;
				}
			}
		}

		if ( ! empty( $missing_alt_images ) ) {
			$issues[] = array(
				'type'    => 'warning',
				'code'    => 'missing_image_alts',
				'title'   => __( 'Images Missing Alt Text', 'supercraft-seo' ),
				'message' => sprintf( __( '%d image(s) on this page are missing ALT tags.', 'supercraft-seo' ), count( $missing_alt_images ) ),
				'fixable' => true,
				'data'    => $missing_alt_images,
			);
			$score -= min( 15, count( $missing_alt_images ) * 5 );
		} else {
			$passed[] = __( 'Image Accessibility: All images have descriptive ALT text.', 'supercraft-seo' );
		}

		// 4. Meta Title & Description Audit (Standard SERP 120-160 Limit)
		$meta_title = ! empty( $seo_meta['meta_title'] ) ? $seo_meta['meta_title'] : ( isset( $seo_meta['title'] ) ? $seo_meta['title'] : '' );
		$meta_desc  = ! empty( $seo_meta['meta_description'] ) ? $seo_meta['meta_description'] : ( isset( $seo_meta['description'] ) ? $seo_meta['description'] : '' );

		if ( empty( $meta_title ) ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'missing_meta_title',
				'title'   => __( 'Missing Meta Title', 'supercraft-seo' ),
				'message' => __( 'Meta title is empty. Supercraft can auto-generate this using OpenAI.', 'supercraft-seo' ),
				'fixable' => true,
			);
			$score -= 20;
		} else {
			$title_len = mb_strlen( $meta_title );
			$min_title = 30;
			$max_title = 60;

			if ( $title_len > $max_title ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_title_too_long',
					'title'   => __( 'Meta Title Too Long', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta title is %d characters (Recommended max: %d characters).', 'supercraft-seo' ), $title_len, $max_title ),
					'fixable' => true,
				);
				$score -= 5;
			} elseif ( $title_len < $min_title ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_title_too_short',
					'title'   => __( 'Meta Title Too Short', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta title is %d characters (Recommended min: %d characters).', 'supercraft-seo' ), $title_len, $min_title ),
					'fixable' => true,
				);
				$score -= 5;
			} else {
				$passed[] = sprintf( __( 'Meta Title: Optimal length (%d characters).', 'supercraft-seo' ), $title_len );
			}
		}

		if ( empty( $meta_desc ) ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'missing_meta_desc',
				'title'   => __( 'Missing Meta Description', 'supercraft-seo' ),
				'message' => __( 'Meta description is empty. Supercraft can auto-generate this using OpenAI.', 'supercraft-seo' ),
				'fixable' => true,
			);
			$score -= 20;
		} else {
			$desc_len = mb_strlen( $meta_desc );
			$min_desc = 120;
			$max_desc = 160;

			if ( $desc_len > $max_desc ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_desc_too_long',
					'title'   => __( 'Meta Description Too Long', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta description is %d characters (Recommended max: %d characters).', 'supercraft-seo' ), $desc_len, $max_desc ),
					'fixable' => true,
				);
				$score -= 5;
			} elseif ( $desc_len < $min_desc ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_desc_too_short',
					'title'   => __( 'Meta Description Too Short', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta description is %d characters (Recommended min: %d characters).', 'supercraft-seo' ), $desc_len, $min_desc ),
					'fixable' => true,
				);
				$score -= 5;
			} else {
				$passed[] = sprintf( __( 'Meta Description: Optimal length (%d characters).', 'supercraft-seo' ), $desc_len );
			}
		}

		// Calculate final score floor at 0
		$final_score = max( 0, $score );

		return array(
			'score'       => $final_score,
			'issues'      => $issues,
			'passed'      => $passed,
			'post_status' => $post_status,
			'permalink'   => $post_permalink,
		);
	}
}
