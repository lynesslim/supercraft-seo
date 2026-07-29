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

		// Prepare page text summary safely
		$page_title = isset( $page_data['title'] ) ? $page_data['title'] : '';
		
		if ( ! empty( $page_data['raw_text'] ) ) {
			$content = wp_strip_all_tags( $page_data['raw_text'] );
		} else {
			$headings_flat = isset( $page_data['headings'] ) ? $this->flatten_headings( $page_data['headings'] ) : array();
			$paragraphs    = isset( $page_data['paragraphs'] ) ? $page_data['paragraphs'] : array();
			$content       = implode( "\n\n", array_merge( $headings_flat, $paragraphs ) );
		}
		
		if ( strlen( $content ) > 8000 ) {
			$content = substr( $content, 0, 8000 ) . '... [content truncated]';
		}

		$missing_alts = array();
		if ( ! empty( $page_data['images'] ) && is_array( $page_data['images'] ) ) {
			foreach ( $page_data['images'] as $img ) {
				if ( is_array( $img ) && empty( $img['alt'] ) && ! empty( $img['url'] ) ) {
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
			if ( isset( $json['seo_data'] ) && is_array( $json['seo_data'] ) ) {
				return $json['seo_data'];
			}
			if ( is_array( $json ) && ( isset( $json['meta_title'] ) || isset( $json['title'] ) ) ) {
				return $json;
			}
		}

		return new WP_Error(
			'openai_generation_failed',
			sprintf( __( 'AI Meta Generation Endpoint Error (HTTP %d): %s', 'supercraft-seo' ), $code, esc_html( substr( $raw, 0, 300 ) ) )
		);
	}

	/**
	 * Helper: Flatten all extracted heading strings into a single array.
	 *
	 * @param array $headings Headings tree.
	 * @return array Array of heading texts.
	 */
	private function flatten_headings( $headings ) {
		$flat = array();
		if ( is_array( $headings ) ) {
			foreach ( $headings as $tag => $items ) {
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( is_array( $item ) && ! empty( $item['text'] ) ) {
							$flat[] = $item['text'];
						}
					}
				}
			}
		}
		return $flat;
	}

	/**
	 * Direct OpenAI Fallback Call
	 *
	 * @param array  $payload Prepared payload.
	 * @param string $api_key Direct API key.
	 * @param string $model Model.
	 * @return array|WP_Error SEO Data or WP_Error.
	 */
	private function call_openai_direct( $payload, $api_key, $model ) {
		$system_prompt = 'You are an elite Technical SEO Specialist working for Supercraft. Generate AIOSEO compliant meta tags in JSON format.';
		$user_prompt   = wp_json_encode( $payload );

		$response = wp_remote_post( self::DIRECT_API_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . trim( $api_key ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'           => $model,
				'messages'        => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user', 'content' => $user_prompt ),
				),
				'response_format' => array( 'type' => 'json_object' ),
				'temperature'     => 0.2,
			) ),
			'timeout' => 45,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
			$seo_data = json_decode( $data['choices'][0]['message']['content'], true );
			if ( is_array( $seo_data ) ) {
				return $seo_data;
			}
		}

		return new WP_Error( 'direct_openai_failed', __( 'Direct OpenAI fallback failed.', 'supercraft-seo' ) );
	}
}
