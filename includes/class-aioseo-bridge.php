<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_AIOSEO_Bridge
 * 
 * Synchronizes generated SEO metadata into All in One SEO (AIOSEO)
 * post meta fields and internal database tables (`wp_aioseo_posts`).
 */
class Supercraft_SEO_AIOSEO_Bridge {

	/**
	 * Check if All in One SEO (AIOSEO) plugin is active.
	 *
	 * @return bool True if AIOSEO is active.
	 */
	public function is_aioseo_active() {
		return defined( 'AIOSEO_DIR' ) || function_exists( 'aioseo' );
	}

	/**
	 * Write SEO metadata into AIOSEO post meta keys and custom DB table.
	 *
	 * @param int   $post_id Post ID to update.
	 * @param array $seo_data AI-generated SEO data structure.
	 * @return bool True on success.
	 */
	public function save_seo_metadata( $post_id, $seo_data ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || empty( $seo_data ) ) {
			return false;
		}

		$title           = isset( $seo_data['meta_title'] ) ? sanitize_text_field( $seo_data['meta_title'] ) : ( isset( $seo_data['title'] ) ? sanitize_text_field( $seo_data['title'] ) : '' );
		$description     = isset( $seo_data['meta_description'] ) ? sanitize_text_field( $seo_data['meta_description'] ) : ( isset( $seo_data['description'] ) ? sanitize_text_field( $seo_data['description'] ) : '' );

		// Smart clamp meta title and description to strict SERP character boundaries (<= 58 chars, <= 158 chars)
		$title       = $this->clamp_title_length( $title );
		$description = $this->clamp_desc_length( $description );

		$focus_keyword   = isset( $seo_data['focus_keyword'] ) ? sanitize_text_field( $seo_data['focus_keyword'] ) : ( isset( $seo_data['focus_keyphrase'] ) ? sanitize_text_field( $seo_data['focus_keyphrase'] ) : '' );
		$og_title        = isset( $seo_data['og_title'] ) ? $this->clamp_title_length( sanitize_text_field( $seo_data['og_title'] ) ) : $title;
		$og_description  = isset( $seo_data['og_description'] ) ? $this->clamp_desc_length( sanitize_text_field( $seo_data['og_description'] ) ) : $description;
		$secondary_kw    = isset( $seo_data['secondary_keywords'] ) && is_array( $seo_data['secondary_keywords'] ) ? array_map( 'sanitize_text_field', $seo_data['secondary_keywords'] ) : array();

		// 1. Update Standard WordPress Post Meta Keys
		update_post_meta( $post_id, '_aioseo_title', $title );
		update_post_meta( $post_id, '_aioseo_description', $description );
		update_post_meta( $post_id, '_aioseo_og_title', $og_title );
		update_post_meta( $post_id, '_aioseo_og_description', $og_description );
		update_post_meta( $post_id, '_aioseo_keywords', implode( ', ', array_merge( array( $focus_keyword ), $secondary_kw ) ) );
		update_post_meta( $post_id, '_aioseo_focus_keyphrase', $focus_keyword );

		// Fallback / Standard meta keys for maximum compatibility
		update_post_meta( $post_id, '_supercraft_seo_meta_title', $title );
		update_post_meta( $post_id, '_supercraft_seo_meta_description', $description );
		update_post_meta( $post_id, '_supercraft_seo_focus_keyword', $focus_keyword );
		update_post_meta( $post_id, '_supercraft_seo_last_updated', current_time( 'mysql' ) );

		// 2. Update AIOSEO Custom Database Table (`wp_aioseo_posts`) if it exists
		global $wpdb;
		$table_name = $wpdb->prefix . 'aioseo_posts';

		// Check if AIOSEO custom table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
			
			// Format AIOSEO JSON keyphrases column payload
			$keyphrases_payload = array(
				'focus' => array(
					'keyphrase' => $focus_keyword,
					'score'     => 100,
				),
				'additional' => array(),
			);

			foreach ( $secondary_kw as $kw ) {
				$keyphrases_payload['additional'][] = array(
					'keyphrase' => $kw,
					'score'     => 80,
				);
			}

			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d", $post_id ) );

			$data_row = array(
				'title'          => $title,
				'description'    => $description,
				'og_title'       => $og_title,
				'og_description' => $og_description,
				'keyphrases'     => wp_json_encode( $keyphrases_payload ),
				'updated'        => current_time( 'mysql' ),
			);

			if ( $existing_id ) {
				$wpdb->update(
					$table_name,
					$data_row,
					array( 'post_id' => $post_id ),
					array( '%s', '%s', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$data_row['post_id'] = $post_id;
				$data_row['created'] = current_time( 'mysql' );
				$wpdb->insert(
					$table_name,
					$data_row,
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
				);
			}
		}

		// 3. Clear AIOSEO Cache if helper exists
		if ( function_exists( 'aioseo' ) && isset( aioseo()->meta ) && method_exists( aioseo()->meta, 'clean_cache' ) ) {
			aioseo()->meta->clean_cache( $post_id );
		}

		return true;
	}

	/**
	 * Get current AIOSEO metadata for a given post.
	 * Returns normalized metadata array with meta_title, meta_description, focus_keyword keys.
	 *
	 * @param int $post_id Post ID.
	 * @return array Existing SEO meta values.
	 */
	public function get_existing_seo_metadata( $post_id ) {
		$title       = get_post_meta( $post_id, '_aioseo_title', true );
		$description = get_post_meta( $post_id, '_aioseo_description', true );
		$keyword     = get_post_meta( $post_id, '_aioseo_focus_keyphrase', true );

		if ( empty( $title ) ) {
			$title = get_post_meta( $post_id, '_supercraft_seo_meta_title', true );
		}
		if ( empty( $description ) ) {
			$description = get_post_meta( $post_id, '_supercraft_seo_meta_description', true );
		}
		if ( empty( $keyword ) ) {
			$keyword = get_post_meta( $post_id, '_supercraft_seo_focus_keyword', true );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'aioseo_posts';
		if ( ( empty( $title ) || empty( $description ) ) && $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT title, description, keyphrases FROM {$table_name} WHERE post_id = %d", $post_id ), ARRAY_A );
			if ( ! empty( $row ) ) {
				if ( empty( $title ) && ! empty( $row['title'] ) ) {
					$title = $row['title'];
				}
				if ( empty( $description ) && ! empty( $row['description'] ) ) {
					$description = $row['description'];
				}
			}
		}

		return array(
			'meta_title'       => $title,
			'title'            => $title,
			'meta_description' => $description,
			'description'      => $description,
			'focus_keyword'    => $keyword,
			'focus_keyphrase'  => $keyword,
			'og_title'         => get_post_meta( $post_id, '_aioseo_og_title', true ),
			'og_description'   => get_post_meta( $post_id, '_aioseo_og_description', true ),
		);
	}

	/**
	 * Smart clamp meta title length to <= 58 characters.
	 */
	public function clamp_title_length( $title ) {
		$title = trim( (string) $title );
		if ( empty( $title ) || mb_strlen( $title ) <= 58 ) {
			return $title;
		}

		if ( preg_match( '/^(.*?)\s*(\|\s*[^|]+)$/u', $title, $matches ) ) {
			$hook    = trim( $matches[1] );
			$suffix  = $matches[2];
			$max_len = 58 - mb_strlen( $suffix );
			if ( $max_len > 15 ) {
				$trimmed_hook = mb_substr( $hook, 0, $max_len );
				$last_space   = mb_strrpos( $trimmed_hook, ' ' );
				if ( false !== $last_space && $last_space > 15 ) {
					$trimmed_hook = mb_substr( $trimmed_hook, 0, $last_space );
				}
				return trim( $trimmed_hook ) . $suffix;
			}
		}

		$trimmed    = mb_substr( $title, 0, 57 );
		$last_space = mb_strrpos( $trimmed, ' ' );
		if ( false !== $last_space && $last_space > 20 ) {
			$trimmed = mb_substr( $trimmed, 0, $last_space );
		}
		return trim( $trimmed );
	}

	/**
	 * Smart clamp meta description length to <= 158 characters.
	 */
	public function clamp_desc_length( $desc ) {
		$desc = trim( (string) $desc );
		if ( empty( $desc ) || mb_strlen( $desc ) <= 158 ) {
			return $desc;
		}

		$sub155      = mb_substr( $desc, 0, 155 );
		$last_period = mb_strrpos( $sub155, '.' );

		if ( false !== $last_period && $last_period >= 110 ) {
			return trim( mb_substr( $sub155, 0, $last_period + 1 ) );
		}

		$last_space = mb_strrpos( $sub155, ' ' );
		$trimmed    = ( false !== $last_space && $last_space > 100 ) ? mb_substr( $sub155, 0, $last_space ) : $sub155;
		$trimmed    = preg_replace( '/[,;:-]$/u', '', trim( $trimmed ) );
		return $trimmed . '.';
	}
}
