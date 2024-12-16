<?php
/**
 * Plugin Name: REST Posts Embedder
 * Description: Embed posts from a specified REST API endpoint.
 * Plugin URI:  https://developer.wordpress.org/
 * Author:      Rafael Minuesa
 * Author URI:  https://www.linkedin.com/in/rafaelminuesa/
 * Version:     1.4
 * Text Domain: restpostsembedder
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package restpostsembedder
 */

// Disable direct file access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Activation hook
register_activation_hook(__FILE__, 'rest_posts_embedder_activate');

function rest_posts_embedder_activate() {
    // Perform any necessary setup tasks
    // For example, set default options
    add_option('embed_posts_endpoint', 'https://prowoos.com/wp-json/wp/v2/posts?_embed');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'rest_posts_embedder_deactivate');

function rest_posts_embedder_deactivate() {
    // Perform any cleanup tasks
    // For example, clear any transients or caches
    delete_transient('rest_posts_embedder_cache');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'rest_posts_embedder_uninstall');

function rest_posts_embedder_uninstall() {
    // Remove all plugin-related data
    delete_option('embed_posts_endpoint');
    // Remove any custom database tables if created
}

// Include admin functions
require plugin_dir_path( __FILE__ ) . 'admin/functions.php';

// Include shortcode functions
require plugin_dir_path( __FILE__ ) . 'shortcodes/functions.php';

// Register shortcode
add_shortcode( 'posts_embedder', 'rest_posts_embedder' );
