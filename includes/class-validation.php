<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Validation
 * 
 * Handles license validation via Supercraft Master Plugin filter or standalone embed code verification.
 */
class Supercraft_SEO_Validation {

	const PLUGIN_SLUG = 'supercraft-seo';
	const VALIDATION_ENDPOINT = 'https://superapp.supercraft.my/api/public/validate-embed';
	const UNLINK_ENDPOINT     = 'https://superapp.supercraft.my/api/public/validate-embed/delete-registration';

	/**
	 * Check if plugin is validated.
	 *
	 * @return bool True if valid.
	 */
	public static function is_validated() {
		if ( defined( 'SUPERCRAFT_SEO_ALLOW_UNVALIDATED' ) && SUPERCRAFT_SEO_ALLOW_UNVALIDATED ) {
			return true;
		}

		$local_status = get_option( 'supercraft_seo_validation_status', 'not_set' ) === 'valid';

		// Allow Supercraft Master Plugin to validate globally
		return (bool) apply_filters( 'supercraft_is_plugin_validated', $local_status, self::PLUGIN_SLUG );
	}

	/**
	 * Check if Supercraft Master Plugin is handling validation.
	 *
	 * @return bool True if Master plugin filter is active.
	 */
	public static function is_master_plugin_active() {
		return has_filter( 'supercraft_is_plugin_validated' );
	}

	/**
	 * Get stored embed code.
	 *
	 * @return string Embed code.
	 */
	public static function get_embed_code() {
		return get_option( 'supercraft_seo_embed_code', '' );
	}

	/**
	 * Validate embed code via standalone Supercraft API endpoint.
	 *
	 * @param string $embed_code Embed code to test.
	 * @return bool True if valid response returned.
	 */
	public static function validate_embed_code_standalone( $embed_code ) {
		if ( empty( $embed_code ) ) {
			return false;
		}

		$response = wp_remote_post( self::VALIDATION_ENDPOINT, array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'embed_code'  => $embed_code,
				'plugin_name' => self::PLUGIN_SLUG,
				'domain'      => get_site_url(),
			) ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 400 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['valid'] );
	}

	/**
	 * Unlink plugin registration from Superapp server.
	 *
	 * @param string $embed_code Embed code to remove.
	 * @return bool True on success.
	 */
	public static function delete_registration( $embed_code ) {
		if ( empty( $embed_code ) ) {
			return false;
		}

		$response = wp_remote_request( self::UNLINK_ENDPOINT, array(
			'method'  => 'DELETE',
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'embed_code'  => $embed_code,
				'plugin_name' => self::PLUGIN_SLUG,
			) ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return $status_code >= 200 && $status_code < 400;
	}
}
