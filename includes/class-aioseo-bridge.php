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

		$title           = isset( $seo_data['meta_title'] ) ? sanitize_text_field( $seo_data['meta_title'] ) : '';
		$description     = isset( $seo_data['meta_description'] ) ? sanitize_text_field( $seo_data['meta_description'] ) : '';
		$focus_keyword   = isset( $seo_data['focus_keyword'] ) ? sanitize_text_field( $seo_data['focus_keyword'] ) : '';
		$og_title        = isset( $seo_data['og_title'] ) ? sanitize_text_field( $seo_data['og_title'] ) : $title;
		$og_description  = isset( $seo_data['og_description'] ) ? sanitize_text_field( $seo_data['og_description'] ) : $description;
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
	 *
	 * @param int $post_id Post ID.
	 * @return array Existing SEO meta values.
	 */
	public function get_existing_seo_metadata( $post_id ) {
		return array(
			'title'           => get_post_meta( $post_id, '_aioseo_title', true ),
			'description'     => get_post_meta( $post_id, '_aioseo_description', true ),
			'focus_keyphrase' => get_post_meta( $post_id, '_aioseo_focus_keyphrase', true ),
			'og_title'        => get_post_meta( $post_id, '_aioseo_og_title', true ),
			'og_description'  => get_post_meta( $post_id, '_aioseo_og_description', true ),
		);
	}
}
