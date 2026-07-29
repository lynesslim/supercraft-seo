<?php
/**
 * Plugin Name: Supercraft Technical SEO Engine
 * Plugin URI:  https://supercraft.io/plugins/seo
 * Description: One-click Technical SEO post-completion suite for Elementor and All in One SEO (AIOSEO). Uses OpenAI to auto-generate meta tags and runs comprehensive technical SEO audits.
 * Version:     1.3.0
 * Author:      Supercraft Team
 * Author URI:  https://supercraft.io
 * Text Domain: supercraft-seo
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define Plugin Constants
define( 'SUPERCRAFT_SEO_VERSION', '1.3.0' );
define( 'SUPERCRAFT_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'SUPERCRAFT_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'SUPERCRAFT_SEO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * GitHub Auto-Update Checker
 */
if ( file_exists( SUPERCRAFT_SEO_PATH . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once SUPERCRAFT_SEO_PATH . 'plugin-update-checker/plugin-update-checker.php';
	$supercraft_seo_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/lynesslim/supercraft-seo/',
		__FILE__,
		'supercraft-seo'
	);
}

/**
 * Autoload Required Classes
 */
require_once SUPERCRAFT_SEO_PATH . 'includes/class-validation.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-elementor-parser.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-openai-service.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-aioseo-bridge.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-seo-auditor.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-background-worker.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-admin-dashboard.php';
require_once SUPERCRAFT_SEO_PATH . 'includes/class-supercraft-seo.php';

/**
 * Global Validation Check Helper
 *
 * @return bool True if validated.
 */
function supercraft_seo_is_validated() {
	return Supercraft_SEO_Validation::is_validated();
}

/**
 * Initialize Supercraft SEO Plugin
 */
function supercraft_seo_init() {
	return Supercraft_SEO::get_instance();
}

// Kick off the plugin
add_action( 'plugins_loaded', 'supercraft_seo_init' );

/**
 * Plugin Activation Hook
 */
register_activation_hook( __FILE__, function() {
	if ( false === get_option( 'supercraft_seo_openai_model' ) ) {
		update_option( 'supercraft_seo_openai_model', 'gpt-4o-mini' );
	}
	if ( false === get_option( 'supercraft_seo_brand_voice' ) ) {
		update_option( 'supercraft_seo_brand_voice', 'Professional, authoritative, yet engaging' );
	}
} );
