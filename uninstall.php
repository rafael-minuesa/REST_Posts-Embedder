<?php
// If uninstall is not called from WordPress, exit
if (!defined('WP_UNINSTALL_HOOK')) {
    exit();
}

// Remove plugin-specific options
delete_option('embed_posts_endpoint');
delete_option('embed_posts_count');

// Remove any transients
delete_transient('rest_posts_embedder_cache');
