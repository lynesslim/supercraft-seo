<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Background_Worker
 * 
 * Manages server-side asynchronous background processing for batch Technical SEO
 * audit and AI meta generation with pause/stop capability.
 * Preserves existing audit results and updates items in-place.
 */
class Supercraft_SEO_Background_Worker {

	const QUEUE_OPTION_KEY = 'supercraft_seo_bg_queue_state';

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
	}

	/**
	 * Start a new background batch process for a list of post IDs.
	 * Preserves existing audited result cards and updates items in-place.
	 *
	 * @param array $post_ids List of post IDs to process.
	 * @return array Queue initial status state.
	 */
	public function start_queue( $post_ids ) {
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return false;
		}

		$existing_state   = get_option( self::QUEUE_OPTION_KEY, array() );
		$existing_results = ! empty( $existing_state['results'] ) && is_array( $existing_state['results'] ) ? $existing_state['results'] : array();

		$state = array(
			'status'          => 'running',
			'total'           => count( $post_ids ),
			'processed_count' => 0,
			'pending_ids'     => array_values( array_map( 'absint', $post_ids ) ),
			'completed_ids'   => array(),
			'results'         => $existing_results,
			'started_at'      => current_time( 'mysql' ),
			'last_updated'    => current_time( 'mysql' ),
		);

		update_option( self::QUEUE_OPTION_KEY, $state, false );

		// Dispatch background ticker asynchronously
		$this->dispatch_ticker();

		return $state;
	}

	/**
	 * Stop/Abort running background process.
	 *
	 * @return bool Success status.
	 */
	public function stop_queue() {
		$state = get_option( self::QUEUE_OPTION_KEY, array() );
		if ( empty( $state ) ) {
			return true;
		}

		$state['status']       = 'stopped';
		$state['pending_ids']  = array();
		$state['last_updated'] = current_time( 'mysql' );

		update_option( self::QUEUE_OPTION_KEY, $state, false );
		return true;
	}

	/**
	 * Clear queue state.
	 */
	public function clear_queue() {
		delete_option( self::QUEUE_OPTION_KEY );
	}

	/**
	 * Get current queue status.
	 *
	 * @return array State payload.
	 */
	public function get_queue_status() {
		$state = get_option( self::QUEUE_OPTION_KEY, array() );

		if ( empty( $state ) ) {
			return array(
				'status'          => 'idle',
				'total'           => 0,
				'processed_count' => 0,
				'pending_ids'     => array(),
				'completed_ids'   => array(),
				'results'         => array(),
			);
		}

		return $state;
	}

	/**
	 * Process the next item in the background queue.
	 * Automatically promotes H1 headings if missing and generates AI metadata.
	 * Updates existing audit card entries in-place.
	 *
	 * @return bool True if item was processed, false if queue is empty or stopped.
	 */
	public function process_next_item() {
		$state = get_option( self::QUEUE_OPTION_KEY, array() );

		if ( empty( $state ) || 'running' !== $state['status'] || empty( $state['pending_ids'] ) ) {
			if ( ! empty( $state['status'] ) && 'running' === $state['status'] && empty( $state['pending_ids'] ) ) {
				$state['status']       = 'completed';
				$state['last_updated'] = current_time( 'mysql' );
				update_option( self::QUEUE_OPTION_KEY, $state, false );
			}
			return false;
		}

		// Pop next post ID from pending queue
		$post_id = array_shift( $state['pending_ids'] );

		// 1. Extract Elementor Copy
		$page_data = $this->main->elementor_parser->get_page_content( $post_id );

		// 2. Auto-Fix H1 Heading if missing
		$h1_count = count( isset( $page_data['headings']['h1'] ) ? $page_data['headings']['h1'] : array() );
		if ( 0 === $h1_count ) {
			$this->main->elementor_parser->promote_first_heading_to_h1( $post_id );
			$page_data = $this->main->elementor_parser->get_page_content( $post_id );
		}

		// 3. Generate AI Metadata
		$seo_generated = false;
		$seo_data      = array();
		$openai_error  = null;

		$ai_res = $this->main->openai_service->generate_seo_metadata( $post_id, $page_data );

		if ( is_wp_error( $ai_res ) ) {
			$openai_error = $ai_res->get_error_message();
			$seo_data     = $this->main->aioseo_bridge->get_existing_seo_metadata( $post_id );
		} else {
			$seo_data      = $ai_res;
			$seo_generated = true;
			// 4. Save into AIOSEO
			$this->main->aioseo_bridge->save_seo_metadata( $post_id, $seo_data );
		}

		// 5. Audit
		$audit_result = $this->main->seo_auditor->run_audit( $post_id, $page_data, $seo_data );

		$result_item = array(
			'post_id'       => $post_id,
			'title'         => get_the_title( $post_id ),
			'permalink'     => get_permalink( $post_id ),
			'is_elementor'  => $page_data['is_elementor'],
			'word_count'    => $page_data['word_count'],
			'seo_generated' => $seo_generated,
			'seo_data'      => $seo_data,
			'openai_error'  => $openai_error,
			'audit'         => $audit_result,
		);

		// Update or Append in-place
		$updated_existing = false;
		if ( ! empty( $state['results'] ) && is_array( $state['results'] ) ) {
			foreach ( $state['results'] as $idx => $existing_item ) {
				if ( isset( $existing_item['post_id'] ) && (int) $existing_item['post_id'] === (int) $post_id ) {
					$state['results'][ $idx ] = $result_item;
					$updated_existing = true;
					break;
				}
			}
		}

		if ( ! $updated_existing ) {
			$state['results'][] = $result_item;
		}

		// Update state
		$state['processed_count']++;
		$state['completed_ids'][] = $post_id;
		$state['last_updated']    = current_time( 'mysql' );

		if ( empty( $state['pending_ids'] ) ) {
			$state['status'] = 'completed';
		}

		update_option( self::QUEUE_OPTION_KEY, $state, false );

		// If more items remain and queue is still running, dispatch next ticker
		if ( 'running' === $state['status'] && ! empty( $state['pending_ids'] ) ) {
			$this->dispatch_ticker();
		}

		return true;
	}

	/**
	 * Dispatch an asynchronous non-blocking HTTP request to trigger the background worker ticker.
	 */
	public function dispatch_ticker() {
		$url = add_query_arg(
			array(
				'action' => 'supercraft_seo_bg_tick',
				'token'  => wp_create_nonce( 'supercraft_seo_bg_ticker' ),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_post( $url, array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
		) );
	}
}
