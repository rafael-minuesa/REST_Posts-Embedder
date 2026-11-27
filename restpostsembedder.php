<?php
/**
 * Plugin Name: REST Posts Embedder
 * Description: Embed posts from a specified REST API endpoint with enhanced features.
 * Plugin URI:  https://github.com/rafael-minuesa/REST_Posts-Embedder
 * Author:      Rafael Minuesa
 * Author URI:  https://www.linkedin.com/in/rafaelminuesa/
 * Version:     3.9.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Update URI:  https://prowoos.com/wp-content/uploads/rpe-updates/restpostsembedder.json
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
    define('REST_POSTS_EMBEDDER_VERSION', '3.9.0');
}
// Demo feed of English ProWoos posts (prowoos.com filters by language with
// ?lang=), used until the site configures its own source. Administrators see
// a notice above it on the front end.
if (!defined('REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT')) {
    define('REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT', 'https://prowoos.com/wp-json/wp/v2/posts?lang=en&_embed');
}
// Demo endpoint used before the feed was limited to English. Still stored in
// the options of existing installs; recognized as the demo and upgraded to
// REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT when rendered.
if (!defined('REST_POSTS_EMBEDDER_LEGACY_DEMO_ENDPOINT')) {
    define('REST_POSTS_EMBEDDER_LEGACY_DEMO_ENDPOINT', 'https://prowoos.com/wp-json/wp/v2/posts?_embed');
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
// Excerpt length is measured in characters. 0 means "no truncation" (use the
// full excerpt returned by the remote site). The default is a reasonable card
// length and can be overridden globally or per feed source.
if (!defined('REST_POSTS_EMBEDDER_DEFAULT_EXCERPT_LENGTH')) {
    define('REST_POSTS_EMBEDDER_DEFAULT_EXCERPT_LENGTH', 200);
}
if (!defined('REST_POSTS_EMBEDDER_MAX_COLUMNS')) {
    define('REST_POSTS_EMBEDDER_MAX_COLUMNS', 5);
}
if (!defined('REST_POSTS_EMBEDDER_MAX_EXCERPT_LENGTH')) {
    define('REST_POSTS_EMBEDDER_MAX_EXCERPT_LENGTH', 2000);
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
 * Feed sources a site starts with before it has saved any.
 *
 * A site that already has the single endpoint of versions before 3.0 gets it
 * as its "Default Source"; any other site gets the demo feed.
 *
 * @since 3.9.0
 * @return array Sources keyed by ID.
 */
function default_sources() {
    $old_endpoint = get_option('embed_posts_endpoint', '');
    $is_migration = is_string($old_endpoint) && '' !== $old_endpoint;

    return array(
        'default' => array(
            'id' => 'default',
            'name' => $is_migration ? __('Default Source', 'restpostsembedder') : __('ProWoos Demo Feed', 'restpostsembedder'),
            'endpoint' => $is_migration ? $old_endpoint : REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT,
            'count' => $is_migration ? get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT) : REST_POSTS_EMBEDDER_DEFAULT_COUNT,
            'excerpt_length' => REST_POSTS_EMBEDDER_DEFAULT_EXCERPT_LENGTH,
            'enabled' => true,
        ),
    );
}

/**
 * The current site's feed sources.
 *
 * Activation stores the defaults for the site it runs on only. On a
 * multisite network every other site, and every site created later, has no
 * stored sources, so they are worked out here when read instead: a site that
 * never saved its sources behaves exactly like a freshly activated one. A
 * site that deleted all its sources has an empty list stored and keeps it.
 *
 * @since 3.9.0
 * @return array Sources keyed by ID.
 */
function get_sources() {
    $sources = get_option('rest_posts_embedder_sources', null);
    if (null === $sources || false === $sources) {
        return default_sources();
    }
    return is_array($sources) ? $sources : array();
}

/**
 * The current site's default endpoint, for a shortcode without a source.
 *
 * Falls back to the demo feed on a site where the option was never stored
 * (see get_sources()).
 *
 * @since 3.9.0
 * @return string Endpoint URL.
 */
function get_default_endpoint() {
    $endpoint = get_option('embed_posts_endpoint', '');
    return (is_string($endpoint) && '' !== $endpoint) ? $endpoint : REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT;
}

/**
 * Plugin activation hook.
 * Stores the default options for the current site. Other sites of a network
 * need no setup: see get_sources() and get_default_endpoint().
 *
 * @since 1.0.0
 * @return void
 */
function rest_posts_embedder_activate() {
    if (empty(get_option('rest_posts_embedder_sources', array()))) {
        update_option('rest_posts_embedder_sources', default_sources());
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

// Uninstalling is handled by uninstall.php, which WordPress runs when the
// plugin is deleted.

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
require plugin_dir_path( __FILE__ ) . 'shortcodes/layouts.php';
require plugin_dir_path( __FILE__ ) . 'shortcodes/functions.php';

// Register shortcode
add_shortcode( 'posts_embedder', 'RestPostsEmbedder\\Shortcodes\\rest_posts_embedder' );

// AJAX handler for the "Load More" button (front-end, available to logged-out
// visitors too). It fetches the next page of posts using a server-issued token
// so an arbitrary endpoint URL can never be supplied by the client.
add_action( 'wp_ajax_rest_posts_embedder_load_more', 'RestPostsEmbedder\\Shortcodes\\rest_posts_embedder_load_more' );
add_action( 'wp_ajax_nopriv_rest_posts_embedder_load_more', 'RestPostsEmbedder\\Shortcodes\\rest_posts_embedder_load_more' );

// Self-hosted update checker. Polls a prowoos.com JSON manifest so new releases
// show up in wp-admin like any other plugin update. Loaded on every request:
// WordPress also rebuilds its plugin update list from WP-Cron (including cron
// run inside a front-end request with ALTERNATE_WP_CRON), WP-CLI and the REST
// API, and a rebuild without this filter drops the self-hosted release from
// the list, so the update notice disappears and auto-updates never see it.
// The checker only registers hooks; the manifest is fetched when WordPress
// runs an update check.
require plugin_dir_path( __FILE__ ) . 'includes/class-update-checker.php';
new \REST_Posts_Embedder_Update_Checker( plugin_basename( __FILE__ ) );
