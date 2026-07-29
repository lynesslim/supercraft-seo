<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supercraft_SEO
 * 
 * Main Plugin Orchestrator Singleton.
 */
class Supercraft_SEO {

	/**
	 * Instance reference
	 * 
	 * @var Supercraft_SEO
	 */
	private static $instance = null;

	/**
	 * Elementor Parser instance
	 * 
	 * @var Supercraft_SEO_Elementor_Parser
	 */
	public $elementor_parser;

	/**
	 * OpenAI Service instance
	 * 
	 * @var Supercraft_SEO_OpenAI
	 */
	public $openai_service;

	/**
	 * AIOSEO Bridge instance
	 * 
	 * @var Supercraft_SEO_AIOSEO_Bridge
	 */
	public $aioseo_bridge;

	/**
	 * SEO Auditor instance
	 * 
	 * @var Supercraft_SEO_Auditor
	 */
	public $seo_auditor;

	/**
	 * Admin Dashboard instance
	 * 
	 * @var Supercraft_SEO_Admin_Dashboard
	 */
	public $admin_dashboard;

	/**
	 * Get Singleton Instance
	 *
	 * @return Supercraft_SEO
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->elementor_parser = new Supercraft_SEO_Elementor_Parser();
		$this->openai_service   = new Supercraft_SEO_OpenAI();
		$this->aioseo_bridge    = new Supercraft_SEO_AIOSEO_Bridge();
		$this->seo_auditor      = new Supercraft_SEO_Auditor();
		$this->admin_dashboard  = new Supercraft_SEO_Admin_Dashboard( $this );
	}
}
