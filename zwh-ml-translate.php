<?php
/**
 * Plugin Name: ZWH Multilingual Translate
 * Update URI:  https://github.com/ziv-was-here/zwh-ml-translate
 * Plugin URI:  https://github.com/ziv-was-here/zwh-ml-translate
 * Description: Manage multilingual content with AI/API auto-translation, manual editing, language switcher, and translated URL slugs. Supports DeepL, Google Translate, OpenAI, Claude, and Gemini.
 * Version:     1.0.3
 * Author:      Ziv Rozenberg
 * Author URI:  https://zivwashere.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zwh-ml-translate
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZWH_MLP_VERSION', '1.0.3' );
define( 'ZWH_MLP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ZWH_MLP_URL',     plugin_dir_url( __FILE__ ) );

// Post meta keys
define( 'ZWH_MLP_META_LANG',   '_zwh_mlp_lang' );   // ISO 639-1 language code (e.g. "fr")
define( 'ZWH_MLP_META_GROUP',  '_zwh_mlp_group' );  // UUID linking all translations of one piece of content
define( 'ZWH_MLP_META_SOURCE', '_zwh_mlp_source' ); // Post ID of the post this was translated from

require_once ZWH_MLP_DIR . 'includes/class-zwh-mlp-settings.php';
require_once ZWH_MLP_DIR . 'includes/class-zwh-mlp-api.php';
require_once ZWH_MLP_DIR . 'includes/class-zwh-mlp-admin.php';
require_once ZWH_MLP_DIR . 'includes/class-zwh-mlp-frontend.php';
require_once ZWH_MLP_DIR . 'includes/class-zwh-mlp-strings.php';

new ZWH_MLP_Settings();
new ZWH_MLP_Admin();
new ZWH_MLP_Frontend();
new ZWH_MLP_Strings();

register_activation_hook( __FILE__, 'zwh_mlp_activate' );
function zwh_mlp_activate() {
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'zwh_mlp_deactivate' );
function zwh_mlp_deactivate() {
	flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// GitHub update system
// ---------------------------------------------------------------------------
require_once plugin_dir_path( __FILE__ ) . 'includes/class-zwh-github-updater.php';
new ZWH_GitHub_Updater( __FILE__, 'ziv-was-here/zwh-ml-translate' );
