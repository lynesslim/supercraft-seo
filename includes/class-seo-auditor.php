<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Auditor
 * 
 * Performs automated Technical SEO checks and Pre-Flight site-wide health diagnostics.
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
		// Strip http/https
		$clean = preg_replace( '#^https?://#i', '', trim( $raw ) );
		// Strip domain extension suffix (.com.my, .com, .my, .io, .net, etc.)
		$clean = preg_replace( '/\.(com\.my|com|my|net|org|io|co|app)\b/i', '', $clean );
		// Capitalize words / replace dashes/dots with spaces
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

		$post_status = get_post_status( $post_id );
		$post_permalink = get_permalink( $post_id );

		// 1. Heading Hierarchy Audit
		$h1_count = count( isset( $page_data['headings']['h1'] ) ? $page_data['headings']['h1'] : array() );

		if ( 0 === $h1_count ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'missing_h1',
				'title'   => __( 'Missing H1 Heading', 'supercraft-seo' ),
				'message' => __( 'This page has no H1 tag. A clear H1 heading is vital for search engine indexing and accessibility.', 'supercraft-seo' ),
				'fixable' => false,
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
		$word_count = isset( $page_data['word_count'] ) ? $page_data['word_count'] : 0;
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
		if ( ! empty( $page_data['images'] ) ) {
			foreach ( $page_data['images'] as $img ) {
				if ( empty( $img['alt'] ) && ! empty( $img['url'] ) ) {
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

		// 4. Meta Title & Description Audit
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
			if ( $title_len > 60 ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_title_too_long',
					'title'   => __( 'Meta Title Too Long', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta title is %d characters (Recommended max: 60 characters).', 'supercraft-seo' ), $title_len ),
					'fixable' => true,
				);
				$score -= 5;
			} elseif ( $title_len < 30 ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_title_too_short',
					'title'   => __( 'Meta Title Too Short', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta title is %d characters (Recommended min: 30 characters).', 'supercraft-seo' ), $title_len ),
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
			if ( $desc_len > 160 ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_desc_too_long',
					'title'   => __( 'Meta Description Too Long', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta description is %d characters (Recommended max: 160 characters).', 'supercraft-seo' ), $desc_len ),
					'fixable' => true,
				);
				$score -= 5;
			} elseif ( $desc_len < 120 ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'meta_desc_too_short',
					'title'   => __( 'Meta Description Too Short', 'supercraft-seo' ),
					'message' => sprintf( __( 'Meta description is %d characters (Recommended min: 120 characters).', 'supercraft-seo' ), $desc_len ),
					'fixable' => true,
				);
				$score -= 5;
			} else {
				$passed[] = sprintf( __( 'Meta Description: Optimal length (%d characters).', 'supercraft-seo' ), $desc_len );
			}
		}

		// 5. Indexability & Robots Audit
		$noindex = get_post_meta( $post_id, '_aioseo_noindex', true );
		if ( 'publish' !== $post_status ) {
			$issues[] = array(
				'type'    => 'warning',
				'code'    => 'post_not_published',
				'title'   => __( 'Page Not Published', 'supercraft-seo' ),
				'message' => sprintf( __( 'Current status is "%s". Draft/Private pages are not indexed by Google.', 'supercraft-seo' ), $post_status ),
				'fixable' => false,
			);
			$score -= 10;
		} elseif ( '1' === $noindex || true === $noindex ) {
			$issues[] = array(
				'type'    => 'critical',
				'code'    => 'noindex_tag_active',
				'title'   => __( 'Noindex Meta Tag Enabled', 'supercraft-seo' ),
				'message' => __( 'This page is set to NOINDEX. Search engines will ignore this page.', 'supercraft-seo' ),
				'fixable' => true,
			);
			$score -= 30;
		} else {
			$passed[] = __( 'Indexability: Page is published and indexable.', 'supercraft-seo' );
		}

		// 6. HTTPS Mixed Content Audit
		if ( is_ssl() ) {
			$raw_text = isset( $page_data['raw_text'] ) ? $page_data['raw_text'] : '';
			if ( false !== strpos( $raw_text, 'http://' ) ) {
				$issues[] = array(
					'type'    => 'warning',
					'code'    => 'insecure_http_links',
					'title'   => __( 'Insecure HTTP Links Detected', 'supercraft-seo' ),
					'message' => __( 'Page contains non-HTTPS (http://) links on a secure HTTPS site, which can trigger mixed content warnings.', 'supercraft-seo' ),
					'fixable' => false,
				);
				$score -= 10;
			} else {
				$passed[] = __( 'Security & Protocol: All links use HTTPS.', 'supercraft-seo' );
			}
		}

		// Normalize final score
		$score = max( 0, min( 100, $score ) );

		return array(
			'post_id'   => $post_id,
			'title'     => get_the_title( $post_id ),
			'permalink' => $post_permalink,
			'score'     => $score,
			'issues'    => $issues,
			'passed'    => $passed,
		);
	}
}
