<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_OpenAI
 * 
 * Routes AI metadata generation to Supercraft backend (`superapp.supercraft.my`)
 * using the validated Supercraft ecosystem connection.
 */
class Supercraft_SEO_OpenAI {

	/**
	 * Main Supercraft API Endpoint for AI Technical SEO Generation
	 */
	const SUPERAPP_SEO_ENDPOINT = 'https://superapp.supercraft.my/api/public/seo/generate';

	/**
	 * Direct OpenAI Endpoint (Fallback Mode)
	 */
	const DIRECT_API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Generate SEO Metadata for a given page content payload.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $page_data Parsed page content from Elementor parser.
	 * @return array|WP_Error Array of generated SEO fields or WP_Error on failure.
	 */
	public function generate_seo_metadata( $post_id, $page_data ) {
		// 1. Ensure plugin is validated
		if ( ! Supercraft_SEO_Validation::is_validated() ) {
			return new WP_Error(
				'unvalidated_plugin',
				__( 'Supercraft SEO is not validated. Please activate your license via Supercraft Master Plugin.', 'supercraft-seo' )
			);
		}

		$brand_voice = get_option( 'supercraft_seo_brand_voice', 'Professional, authoritative, yet engaging' );
		$model       = get_option( 'supercraft_seo_openai_model', 'gpt-4o-mini' );
		$site_name   = get_bloginfo( 'name' );
		$embed_code  = Supercraft_SEO_Validation::get_embed_code();

		// Prepare page text summary
		$page_title = $page_data['title'];
		$content    = wp_strip_all_tags( $page_data['raw_text'] );
		
		if ( strlen( $content ) > 8000 ) {
			$content = substr( $content, 0, 8000 ) . '... [content truncated]';
		}

		$missing_alts = array();
		if ( ! empty( $page_data['images'] ) ) {
			foreach ( $page_data['images'] as $img ) {
				if ( empty( $img['alt'] ) && ! empty( $img['url'] ) ) {
					$missing_alts[] = $img['url'];
				}
			}
		}

		// Prepare Supercraft Payload
		$payload = array(
			'plugin_name'  => Supercraft_SEO_Validation::PLUGIN_SLUG,
			'embed_code'   => $embed_code,
			'domain'       => get_site_url(),
			'post_id'      => $post_id,
			'site_name'    => $site_name,
			'page_title'   => $page_title,
			'content'      => $content,
			'missing_alts' => $missing_alts,
			'brand_voice'  => $brand_voice,
			'model'        => $model,
		);

		// Send to superapp.supercraft.my
		$response = wp_remote_post( self::SUPERAPP_SEO_ENDPOINT, array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			// Fallback: check if user configured direct OpenAI API key in local settings
			$direct_key = get_option( 'supercraft_seo_openai_api_key', '' );
			if ( ! empty( $direct_key ) ) {
				return $this->call_openai_direct( $payload, $direct_key, $model );
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			$json = json_decode( $raw, true );
			if ( isset( $json['seo_data'] ) ) {
				return $json['seo_data'];
			}
			if ( is_array( $json ) && isset( $json['meta_title'] ) ) {
				return $json;
			}
		}

		// Fallback: Check if direct OpenAI key exists if Superapp endpoint returns non-200
		$direct_key = get_option( 'supercraft_seo_openai_api_key', '' );
		if ( ! empty( $direct_key ) ) {
			return $this->call_openai_direct( $payload, $direct_key, $model );
		}

		return new WP_Error(
			'superapp_api_error',
			sprintf( __( 'Supercraft Server Response (%d): %s', 'supercraft-seo' ), $code, esc_html( $raw ) )
		);
	}

	/**
	 * Direct Fallback call to OpenAI if direct API key is set
	 */
	private function call_openai_direct( $payload, $api_key, $model ) {
		$system_prompt = "You are an elite Technical SEO Specialist. Write optimized SEO meta tags in JSON format matching keys: meta_title, meta_description, focus_keyword, secondary_keywords (array), og_title, og_description, suggested_image_alts (array of {url, alt_text}). Tone: {$payload['brand_voice']}.";

		$user_prompt = "Page Title: {$payload['page_title']}\nSite: {$payload['site_name']}\nContent:\n{$payload['content']}\nMissing ALTs: " . json_encode( $payload['missing_alts'] );

		$body = array(
			'model'           => $model,
			'messages'        => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			'response_format' => array( 'type' => 'json_object' ),
			'temperature'     => 0.4,
		);

		$response = wp_remote_post( self::DIRECT_API_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $api_key ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
			return json_decode( $decoded['choices'][0]['message']['content'], true );
		}

		return new WP_Error( 'openai_direct_error', __( 'Failed to retrieve completion from OpenAI.', 'supercraft-seo' ) );
	}
}
