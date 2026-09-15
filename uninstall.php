<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit();
}

// Remove plugin-specific options
delete_option('embed_posts_endpoint');
delete_option('embed_posts_count');

// Remove all plugin transients
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_rest_posts_embedder_') . '%',
        $wpdb->esc_like('_transient_timeout_rest_posts_embedder_') . '%'
    )
);

// Remove Load More token contexts (one non-autoloaded option per feed)
$lm_options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('rest_posts_embedder_lm_') . '%'
    )
);
foreach ($lm_options as $lm_option) {
    delete_option($lm_option);
}
