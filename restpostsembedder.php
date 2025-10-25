<?php
/**
 * Plugin Name: REST Posts Embedder
 * Description: Embed posts from a specified REST API endpoint with enhanced features.
 * Plugin URI:  https://github.com/rafael-minuesa/REST_Posts-Embedder
 * Author:      Rafael Minuesa
 * Author URI:  https://www.linkedin.com/in/rafaelminuesa/
 * Version:     3.5.0
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

// Define plugin constants
if (!defined('REST_POSTS_EMBEDDER_VERSION')) {
    define('REST_POSTS_EMBEDDER_VERSION', '3.5.0');
}
if (!defined('REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT')) {
    define('REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT', 'https://prowoos.com/wp-json/wp/v2/posts?_embed');
}
if (!defined('REST_POSTS_EMBEDDER_DEFAULT_COUNT')) {
    define('REST_POSTS_EMBEDDER_DEFAULT_COUNT', 5);
}
if (!defined('REST_POSTS_EMBEDDER_MIN_COUNT')) {
    define('REST_POSTS_EMBEDDER_MIN_COUNT', 1);
}
if (!defined('REST_POSTS_EMBEDDER_MAX_COUNT')) {
    define('REST_POSTS_EMBEDDER_MAX_COUNT', 20);
}
if (!defined('REST_POSTS_EMBEDDER_CACHE_EXPIRATION')) {
    define('REST_POSTS_EMBEDDER_CACHE_EXPIRATION', HOUR_IN_SECONDS);
}

/**
 * Load plugin text domain for internationalization.
 *
 * @since 2.9.0
 * @return void
 */
function rest_posts_embedder_load_textdomain() {
    load_plugin_textdomain('restpostsembedder', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'RestPostsEmbedder\\rest_posts_embedder_load_textdomain');

/**
 * Plugin activation hook.
 * Sets up default options on activation and migrates old settings.
 *
 * @since 1.0.0
 * @return void
 */
function rest_posts_embedder_activate() {
    // Check if we need to migrate from old single-source format
    $sources = get_option('rest_posts_embedder_sources', array());

    if (empty($sources)) {
        // Check if old format exists
        $old_endpoint = get_option('embed_posts_endpoint', '');
        $old_count = get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT);

        if (!empty($old_endpoint)) {
            // Migrate old settings to new format
            $sources = array(
                'default' => array(
                    'id' => 'default',
                    'name' => __('Default Source', 'restpostsembedder'),
                    'endpoint' => $old_endpoint,
                    'count' => $old_count,
                    'enabled' => true
                )
            );
        } else {
            // Create default source
            $sources = array(
                'default' => array(
                    'id' => 'default',
                    'name' => __('Default Source', 'restpostsembedder'),
                    'endpoint' => REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT,
                    'count' => REST_POSTS_EMBEDDER_DEFAULT_COUNT,
                    'enabled' => true
                )
            );
        }

        update_option('rest_posts_embedder_sources', $sources);
    }

    // Keep old options for backward compatibility
    if (!get_option('embed_posts_endpoint')) {
        add_option('embed_posts_endpoint', REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT);
    }
    if (!get_option('embed_posts_count')) {
        add_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT);
    }
}
register_activation_hook(__FILE__, 'RestPostsEmbedder\\rest_posts_embedder_activate');

/**
 * Plugin deactivation hook.
 * Cleans up all plugin transients on deactivation.
 *
 * @since 1.0.0
 * @return void
 */
function rest_posts_embedder_deactivate() {
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_rest_posts_embedder_') . '%',
            $wpdb->esc_like('_transient_timeout_rest_posts_embedder_') . '%'
        )
    );
}
register_deactivation_hook(__FILE__, 'RestPostsEmbedder\\rest_posts_embedder_deactivate');

/**
 * Plugin uninstall hook.
 * Removes all plugin-related data from database.
 *
 * @since 1.0.0
 * @return void
 */
function rest_posts_embedder_uninstall() {
    delete_option('embed_posts_endpoint');
    delete_option('embed_posts_count');
}
register_uninstall_hook(__FILE__, 'RestPostsEmbedder\\rest_posts_embedder_uninstall');

/**
 * Add settings link to plugin action links.
 *
 * @since 1.0.0
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function rest_posts_embedder_settings_link($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=embed-posts-settings') . '">' . __('Settings', 'restpostsembedder') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'RestPostsEmbedder\\rest_posts_embedder_settings_link');

// Include admin functions
require plugin_dir_path( __FILE__ ) . 'admin/functions.php';

// Include shortcode functions
require plugin_dir_path( __FILE__ ) . 'shortcodes/functions.php';

// Register shortcode
add_shortcode( 'posts_embedder', 'RestPostsEmbedder\\Shortcodes\\rest_posts_embedder' );
