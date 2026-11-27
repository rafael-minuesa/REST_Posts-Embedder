<?php
/**
 * [prowoos_posts_grid], the shortcode of the ProWoos Posts Grid plugin.
 *
 * That plugin is merged into this one. Pages that still use its shortcode get
 * a "This site" feed in the Grid layout with the same settings. While ProWoos
 * Posts Grid itself is active, it keeps its shortcode.
 *
 * @package restpostsembedder
 * @since 3.10.0
 */

namespace RestPostsEmbedder\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render [prowoos_posts_grid] as a posts_embedder feed.
 *
 * Supported: posts, columns, columns_tablet, category, excerpt_length (words,
 * as in ProWoos Posts Grid), orderby, order and offset. Not supported, and
 * ignored: show_date, show_excerpt, show_readmore and readmore_text.
 *
 * @since 3.10.0
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function posts_grid_alias($atts = array()) {
    $atts = shortcode_atts(array(
        'posts'          => 6,
        'columns'        => 3,
        'columns_tablet' => 2,
        'category'       => '',
        'excerpt_length' => 20,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'offset'         => 0,
    ), $atts, 'prowoos_posts_grid');

    $posts = absint($atts['posts']);

    return rest_posts_embedder(array(
        'source'         => LOCAL_SOURCE_ID,
        'layout'         => 'grid',
        'count'          => max(REST_POSTS_EMBEDDER_MIN_COUNT, min(REST_POSTS_EMBEDDER_MAX_COUNT, $posts ? $posts : 6)),
        'columns'        => $atts['columns'],
        'columns_tablet' => $atts['columns_tablet'],
        'category'       => $atts['category'],
        // ProWoos Posts Grid counted words. 0 there meant an empty excerpt;
        // one word is the closest this plugin offers.
        'excerpt_words'  => max(1, absint($atts['excerpt_length'])),
        'orderby'        => $atts['orderby'],
        'order'          => $atts['order'],
        'offset'         => $atts['offset'],
    ));
}

/**
 * Register the alias unless another plugin (ProWoos Posts Grid itself)
 * already owns the shortcode.
 *
 * @since 3.10.0
 * @return void
 */
function register_posts_grid_alias() {
    if (!shortcode_exists('prowoos_posts_grid')) {
        add_shortcode('prowoos_posts_grid', __NAMESPACE__ . '\\posts_grid_alias');
    }
}
// After plugins register theirs on init (default priority).
add_action('init', __NAMESPACE__ . '\\register_posts_grid_alias', 20);
