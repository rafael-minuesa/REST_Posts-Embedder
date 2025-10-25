<?php

namespace RestPostsEmbedder\Shortcodes;

function rest_posts_embedder($atts = array()) {
    // Allow overriding of settings via shortcode attributes
    $atts = shortcode_atts(array(
        'endpoint' => get_option('embed_posts_endpoint'),
        'count' => get_option('embed_posts_count', 5)
    ), $atts, 'posts_embedder');

    // Validate inputs
    $endpoint = esc_url_raw($atts['endpoint']);
    $count = absint($atts['count']);
    $count = ($count > 0 && $count <= 20) ? $count : 5;

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
        error_log('REST Posts Embedder Error: ' . $response->get_error_message());
        return '<p>' . __('Unable to fetch posts. Please try again later.', 'restpostsembedder') . '</p>';
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        error_log('REST Posts Embedder HTTP Error: ' . wp_remote_retrieve_response_code($response));
        return '<p>' . __('Unable to fetch posts. Server returned an error.', 'restpostsembedder') . '</p>';
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
        $fordate = isset($remote_post->modified) ? date_i18n('jS \of F Y', strtotime($remote_post->modified)) : '';
        
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
        $allposts .= '<div class="wrapper">
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
                                        ' . ($thumb_url ? '<img src="' . $thumb_url . '" alt="' . $title . '" />' : '') . '
                                    </a>
                                    ' . $excerpt . '
                                    <a href="' . $link . '" target="_blank" rel="noopener noreferrer" class="read-more">
                                        <b>' . __('Read more...', 'restpostsembedder') . '</b>
                                    </a>
                                </p>
                            </article>
                        </div>
                    </div>';
    }

    // Cache the result for 1 hour
    set_transient($cache_key, $allposts, HOUR_IN_SECONDS);

    return $allposts;
}

function display_posts_enqueue_styles() {
    // Define the path to the CSS file
    $css_path = plugin_dir_url( dirname(__FILE__) ) . 'assets/css/custom.css';

    // Enqueue the CSS file with dynamic version
    wp_enqueue_style( 'display-posts-style', $css_path, array(), '2.8.0', 'all' );
}
add_action( 'wp_enqueue_scripts', 'RestPostsEmbedder\\Shortcodes\\display_posts_enqueue_styles' );
