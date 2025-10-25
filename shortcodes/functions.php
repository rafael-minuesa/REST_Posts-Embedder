<?php
/**
 * Shortcode functions for REST Posts Embedder.
 *
 * @package restpostsembedder
 * @since 1.0.0
 */

namespace RestPostsEmbedder\Shortcodes;

/**
 * Main shortcode handler for displaying REST API posts.
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes.
 * @return string HTML output of the embedded posts.
 */
function rest_posts_embedder($atts = array()) {
    // Allow overriding of settings via shortcode attributes
    $atts = shortcode_atts(array(
        'source' => '',  // New: source ID for multi-source support
        'endpoint' => get_option('embed_posts_endpoint'),
        'count' => get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT)
    ), $atts, 'posts_embedder');

    // NEW: Multi-source support
    if (!empty($atts['source'])) {
        $sources = get_option('rest_posts_embedder_sources', array());
        $source_id = sanitize_key($atts['source']);

        if (isset($sources[$source_id])) {
            $source = $sources[$source_id];

            // Check if source is enabled
            if (!$source['enabled']) {
                return '<p>' . sprintf(
                    __('The requested feed source "%s" is currently disabled.', 'restpostsembedder'),
                    esc_html($source['name'])
                ) . '</p>';
            }

            // Use source configuration
            $atts['endpoint'] = $source['endpoint'];
            $atts['count'] = $source['count'];
        } else {
            return '<p>' . sprintf(
                __('Feed source "%s" not found. Please check your source ID in the plugin settings.', 'restpostsembedder'),
                esc_html($source_id)
            ) . '</p>';
        }
    }

    // Validate inputs
    $endpoint = esc_url_raw($atts['endpoint']);
    $count = absint($atts['count']);
    $count = ($count > 0 && $count <= REST_POSTS_EMBEDDER_MAX_COUNT) ? $count : REST_POSTS_EMBEDDER_DEFAULT_COUNT;

    /**
     * Filter the validated endpoint URL before making the API request.
     *
     * @since 2.9.0
     * @param string $endpoint The validated endpoint URL.
     * @param array  $atts     The shortcode attributes.
     */
    $endpoint = apply_filters('rest_posts_embedder_endpoint', $endpoint, $atts);

    /**
     * Filter the validated post count before making the API request.
     *
     * @since 2.9.0
     * @param int   $count The validated post count.
     * @param array $atts  The shortcode attributes.
     */
    $count = apply_filters('rest_posts_embedder_count', $count, $atts);

    // Check if endpoint is valid
    if (empty($endpoint)) {
        return '<p>' . __('Invalid endpoint URL.', 'restpostsembedder') . '</p>';
    }

    // Create a unique cache key
    $cache_key = 'rest_posts_embedder_' . md5($endpoint . '_' . $count);
    
    // Try to get cached posts
    $cached_posts = get_transient($cache_key);
    if (false !== $cached_posts) {
        return $cached_posts;
    }

    // Prepare API request
    $args = array(
        'timeout' => 10,
        'sslverify' => true
    );
    $response = wp_remote_get(add_query_arg(array('per_page' => $count), $endpoint), $args);

    // Error handling
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('REST Posts Embedder Error: ' . $error_message . ' | Endpoint: ' . $endpoint);
        return '<p>' . sprintf(
            __('Unable to fetch posts. Error: %s. Please check your endpoint URL in the plugin settings.', 'restpostsembedder'),
            esc_html($error_message)
        ) . '</p>';
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('REST Posts Embedder HTTP Error: ' . $response_code . ' | Endpoint: ' . $endpoint);
        return '<p>' . sprintf(
            __('Unable to fetch posts. Server returned HTTP error %d. Please verify the endpoint URL is correct.', 'restpostsembedder'),
            $response_code
        ) . '</p>';
    }

    // Parse response
    $remote_posts = json_decode(wp_remote_retrieve_body($response));

    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('REST Posts Embedder JSON Error: ' . json_last_error_msg());
        return '<p>' . __('Unable to parse response from API. Invalid JSON received.', 'restpostsembedder') . '</p>';
    }

    if (empty($remote_posts) || !is_array($remote_posts)) {
        return '<p>' . __('No posts found.', 'restpostsembedder') . '</p>';
    }

    // Generate post HTML
    $allposts = '';
    foreach ($remote_posts as $remote_post) {
        // Safely extract post data
        $title = isset($remote_post->title->rendered) ? esc_html($remote_post->title->rendered) : __('Untitled', 'restpostsembedder');
        $link = isset($remote_post->link) ? esc_url($remote_post->link) : '';
        $fordate = isset($remote_post->modified) ? wp_date('jS \of F Y', strtotime($remote_post->modified)) : '';
        
        // Featured image
        $thumb_url = '';
        if (!empty($remote_post->featured_media) && isset($remote_post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->medium->source_url)) {
            $thumb_url = esc_url($remote_post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->medium->source_url);
        }

        // Author information
        $author_name = __('Unknown Author', 'restpostsembedder');
        $author_name_url = '';
        if (!empty($remote_post->author) && isset($remote_post->_embedded->author[0])) {
            $author_name = esc_html($remote_post->_embedded->author[0]->name);
            $author_name_url = esc_url($remote_post->_embedded->author[0]->source_url);
        }

        // Excerpt
        $excerpt = isset($remote_post->excerpt->rendered) ? wp_kses_post($remote_post->excerpt->rendered) : '';

        // Build post HTML
        $post_html = '<div class="wrapper">
                        <div class="embed-posts-wrapper">
                            <article class="embed-posts">
                                <a href="' . $link . '" target="_blank" rel="noopener noreferrer">
                                    <h3>' . $title . '</h3>
                                </a>
                                <small>' . sprintf(__('%1$s, by %2$s', 'restpostsembedder'),
                                    $fordate,
                                    '<a href="' . $author_name_url . '" target="_blank" rel="noopener noreferrer">' . $author_name . '</a>') .
                                '</small>
                                <p class="embed-post-content">
                                    <a href="' . $link . '" target="_blank" rel="noopener noreferrer">
                                        ' . ($thumb_url ? '<img src="' . $thumb_url . '" alt="' . esc_attr($title) . '" loading="lazy" />' : '') . '
                                    </a>
                                    ' . $excerpt . '
                                    <a href="' . $link . '" target="_blank" rel="noopener noreferrer" class="read-more">
                                        <b>' . __('Read more...', 'restpostsembedder') . '</b>
                                    </a>
                                </p>
                            </article>
                        </div>
                    </div>';

        /**
         * Filter the HTML for individual post.
         *
         * @since 2.9.0
         * @param string $post_html   The post HTML.
         * @param object $remote_post The remote post object.
         */
        $post_html = apply_filters('rest_posts_embedder_post_html', $post_html, $remote_post);

        $allposts .= $post_html;
    }

    /**
     * Filter the complete HTML output before caching.
     *
     * @since 2.9.0
     * @param string $allposts The complete HTML output.
     * @param array  $atts     The shortcode attributes.
     */
    $allposts = apply_filters('rest_posts_embedder_output', $allposts, $atts);

    // Cache the result using configured expiration
    $cache_expiration = \RestPostsEmbedder\Admin\get_cache_expiration();
    set_transient($cache_key, $allposts, $cache_expiration);

    return $allposts;
}

/**
 * Enqueue the plugin's CSS styles.
 *
 * @since 1.0.0
 * @return void
 */
function display_posts_enqueue_styles() {
    // Define the path to the CSS file
    $css_path = plugin_dir_url( dirname(__FILE__) ) . 'assets/css/custom.css';

    // Enqueue the CSS file with dynamic version
    wp_enqueue_style( 'display-posts-style', $css_path, array(), REST_POSTS_EMBEDDER_VERSION, 'all' );

    // Add inline CSS for column configuration
    $columns_desktop = absint(get_option('embed_posts_columns_desktop', 2));
    $columns_mobile = absint(get_option('embed_posts_columns_mobile', 1));

    $inline_css = "
    /* Column configuration */
    .embed-posts-wrapper {
        grid-template-columns: repeat({$columns_desktop}, 1fr);
    }

    @media only screen and (max-width: 768px) {
        .embed-posts-wrapper {
            grid-template-columns: repeat({$columns_mobile}, 1fr);
        }
    }
    ";

    // Add custom CSS if provided
    $custom_css = get_option('embed_posts_custom_css', '');
    if (!empty($custom_css)) {
        $inline_css .= "\n\n/* Custom CSS */\n" . $custom_css;
    }

    wp_add_inline_style('display-posts-style', $inline_css);
}
add_action( 'wp_enqueue_scripts', 'RestPostsEmbedder\\Shortcodes\\display_posts_enqueue_styles' );
