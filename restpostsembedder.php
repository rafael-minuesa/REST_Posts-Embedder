<?php
/**
 * Plugin Name: REST Posts Embedder
 * Description: Embed posts from a specified REST API endpoint with enhanced features.
 * Plugin URI:  https://prowoos.com/
 * Author:      Rafael Minuesa
 * Author URI:  https://www.linkedin.com/in/rafaelminuesa/
 * Version:     2.7
 * Text Domain: restpostsembedder
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package restpostsembedder
 */

// Define the namespace
namespace RestPostsEmbedder;

// Disable direct file access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load text domain for internationalization
function rest_posts_embedder_load_textdomain() {
    load_plugin_textdomain('restpostsembedder', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'rest_posts_embedder_load_textdomain');

// Activation hook
register_activation_hook(__FILE__, 'rest_posts_embedder_activate');

function rest_posts_embedder_activate() {
    // Perform any necessary setup tasks
    add_option('embed_posts_endpoint', 'https://prowoos.com/wp-json/wp/v2/posts?_embed');
    add_option('embed_posts_count', 5);
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'rest_posts_embedder_deactivate');

function rest_posts_embedder_deactivate() {
    // Perform cleanup tasks
    delete_transient('rest_posts_embedder_cache');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'rest_posts_embedder_uninstall');

function rest_posts_embedder_uninstall() {
    // Remove all plugin-related data
    delete_option('embed_posts_endpoint');
    delete_option('embed_posts_count');
}

// Add settings link on plugin page
function rest_posts_embedder_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=embed-posts-settings') . '">' . __('Settings', 'restpostsembedder') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'rest_posts_embedder_settings_link');

// Include admin functions
require plugin_dir_path( __FILE__ ) . 'admin/functions.php';

// Include shortcode functions
require plugin_dir_path( __FILE__ ) . 'shortcodes/functions.php';

// Register shortcode
add_shortcode( 'posts_embedder', 'rest_posts_embedder' );
