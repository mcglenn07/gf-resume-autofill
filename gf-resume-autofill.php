<?php
/**
 * Plugin Name: Resume Autofill for Gravity Forms
 * Description: Autofill Gravity Forms fields from an uploaded resume via AI parsing.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires Plugins: gravityforms
 * License: GPL v2 or later
 * Text Domain: gf-resume-autofill
 */

defined( 'ABSPATH' ) || exit;

define( 'GFRA_VERSION', '0.1.0' );
define( 'GFRA_PLUGIN_FILE', __FILE__ );
define( 'GFRA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFRA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'gform_loaded', function() {
	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	if ( ! file_exists( GFRA_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Resume Autofill for Gravity Forms: run "composer install" in the plugin directory before activating.', 'gf-resume-autofill' ) .
				'</p></div>';
		} );
		return;
	}

	require_once GFRA_PLUGIN_DIR . 'vendor/autoload.php';

	GFForms::include_addon_framework();

	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-encryption.php';
	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-field-mapper.php';
	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-text-extractor.php';
	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-openai-client.php';
	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-rest-controller.php';
	require_once GFRA_PLUGIN_DIR . 'includes/class-gfra-addon.php';

	GFAddOn::register( 'GFRA_AddOn' );
}, 5 );
