<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO_Background_Worker
 * 
 * Manages server-side asynchronous background processing for batch Technical SEO
 * audit and AI meta generation with pause/stop capability.
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
	 *
	 * @param array $post_ids List of post IDs to process.
	 * @return array Queue initial status state.
	 */
	public function start_queue( $post_ids ) {
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return false;
		}

		$state = array(
			'status'          => 'running',
			'total'           => count( $post_ids ),
			'processed_count' => 0,
			'pending_ids'     => array_values( array_map( 'absint', $post_ids ) ),
			'completed_ids'   => array(),
			'results'         => array(),
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

		// 2. Generate AI Metadata
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
			// 3. Save into AIOSEO
			$this->main->aioseo_bridge->save_seo_metadata( $post_id, $seo_data );
		}

		// 4. Audit
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

		// Update state
		$state['processed_count']++;
		$state['completed_ids'][] = $post_id;
		$state['results'][]       = $result_item;
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
