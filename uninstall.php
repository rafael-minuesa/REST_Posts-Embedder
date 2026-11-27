<?php
/**
 * Removes everything the plugin stored, on every site of a network.
 *
 * Deleting the plugin deletes its feed sources and settings too. To keep them
 * across a reinstall, deactivate the plugin and replace its folder instead.
 *
 * @package restpostsembedder
 */

// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

if (!function_exists('rest_posts_embedder_uninstall_site')) :
/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function rest_posts_embedder_uninstall_site() {
    global $wpdb;

    $options = array(
        // Feed sources and the pre-3.0 single endpoint.
        'rest_posts_embedder_sources',
        'embed_posts_endpoint',
        'embed_posts_count',
        // Styling settings.
        'embed_posts_layout',
        'embed_posts_excerpt_length',
        'embed_posts_columns_desktop',
        'embed_posts_columns_mobile',
        'embed_posts_show_images_desktop',
        'embed_posts_show_images_mobile',
        'embed_posts_custom_css',
        // Cache settings.
        'embed_posts_cache_expiration',
        'embed_posts_cache_custom_hours',
        'rest_posts_embedder_cache_generation',
    );
    foreach ($options as $option) {
        delete_option($option);
    }

    // The update manifest has a fixed name, so it can be removed from a
    // persistent object cache as well.
    delete_transient('rest_posts_embedder_update_data');

    // Cached feeds and page counts (hashed names), and the Load More
    // transients of 3.7.0. With a persistent object cache these are not in
    // the options table and simply expire.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_rest_posts_embedder_') . '%',
            $wpdb->esc_like('_transient_timeout_rest_posts_embedder_') . '%',
            $wpdb->esc_like('_transient_rpe_lm_') . '%',
            $wpdb->esc_like('_transient_timeout_rpe_lm_') . '%'
        )
    );

    // Load More token contexts (one non-autoloaded option per feed)
    $lm_options = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('rest_posts_embedder_lm_') . '%'
        )
    );
    foreach ($lm_options as $lm_option) {
        delete_option($lm_option);
    }
}
endif;

if (is_multisite()) {
    $rest_posts_embedder_site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($rest_posts_embedder_site_ids as $rest_posts_embedder_site_id) {
        switch_to_blog($rest_posts_embedder_site_id);
        rest_posts_embedder_uninstall_site();
        restore_current_blog();
    }
} else {
    rest_posts_embedder_uninstall_site();
}
