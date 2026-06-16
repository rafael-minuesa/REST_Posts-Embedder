<?php
/**
 * Shortcode functions for REST Posts Embedder.
 *
 * @package restpostsembedder
 * @since 1.0.0
 */

namespace RestPostsEmbedder\Shortcodes;

/**
 * Resolve the effective excerpt length for a feed.
 *
 * 0 means "no truncation" (render the remote excerpt as-is). Any positive
 * value is a character count.
 *
 * @since 3.7.0
 * @param mixed $value Raw excerpt length (option, source field or attribute).
 * @return int Sanitized excerpt length (0 = unlimited).
 */
function sanitize_excerpt_length($value) {
    $length = absint($value);
    if ($length > REST_POSTS_EMBEDDER_MAX_EXCERPT_LENGTH) {
        $length = REST_POSTS_EMBEDDER_MAX_EXCERPT_LENGTH;
    }
    return $length;
}

/**
 * Truncate a rendered excerpt to a maximum number of characters.
 *
 * Strips tags, collapses whitespace, then cuts at the last word boundary that
 * fits within the limit and appends an ellipsis. Returns plain text (the
 * caller is responsible for escaping). Multibyte-safe when mbstring is loaded.
 *
 * @since 3.7.0
 * @param string $html   The remote excerpt HTML.
 * @param int    $length Maximum length in characters (> 0).
 * @return string Truncated plain-text excerpt.
 */
function truncate_excerpt($html, $length) {
    $plain = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
    $plain = trim(preg_replace('/\s+/', ' ', $plain));

    if ('' === $plain) {
        return '';
    }

    $mb = function_exists('mb_strlen');

    if (($mb ? mb_strlen($plain) : strlen($plain)) <= $length) {
        return $plain;
    }

    $cut = $mb ? mb_substr($plain, 0, $length) : substr($plain, 0, $length);

    // Back off to the last whole word, unless that would leave nothing.
    $last_space = $mb ? mb_strrpos($cut, ' ') : strrpos($cut, ' ');
    if (false !== $last_space && $last_space > 0) {
        $cut = $mb ? mb_substr($cut, 0, $last_space) : substr($cut, 0, $last_space);
    }

    return rtrim($cut) . '…';
}

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
        'source' => '',  // Source ID for multi-source support
        'endpoint' => get_option('embed_posts_endpoint'),
        'count' => get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT),
        // Per-feed excerpt length in characters. Empty string means "fall back
        // to the source value, then the global default".
        'excerpt_length' => '',
    ), $atts, 'posts_embedder');

    // Multi-source support
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
            // Only inherit the source excerpt length when not overridden inline.
            if ('' === $atts['excerpt_length'] && isset($source['excerpt_length'])) {
                $atts['excerpt_length'] = $source['excerpt_length'];
            }
        } else {
            return '<p>' . sprintf(
                __('Feed source "%s" not found. Please check your source ID in the plugin settings.', 'restpostsembedder'),
                esc_html($source_id)
            ) . '</p>';
        }
    }

    // Validate inputs. Restrict to http/https to limit the SSRF surface from a
    // shortcode-supplied endpoint, and reject URLs WordPress considers unsafe
    // (blocked hosts / internal addresses, per WP's own HTTP request rules).
    $endpoint = esc_url_raw($atts['endpoint'], array('http', 'https'));
    if (!empty($endpoint) && !wp_http_validate_url($endpoint)) {
        $endpoint = '';
    }
    $count = absint($atts['count']);
    $count = ($count > 0 && $count <= REST_POSTS_EMBEDDER_MAX_COUNT) ? $count : REST_POSTS_EMBEDDER_DEFAULT_COUNT;

    // Resolve excerpt length: explicit value wins, otherwise the global default.
    if ('' === $atts['excerpt_length']) {
        $excerpt_length = sanitize_excerpt_length(
            get_option('embed_posts_excerpt_length', REST_POSTS_EMBEDDER_DEFAULT_EXCERPT_LENGTH)
        );
    } else {
        $excerpt_length = sanitize_excerpt_length($atts['excerpt_length']);
    }

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

    /**
     * Filter the resolved excerpt length (characters; 0 = unlimited).
     *
     * @since 3.7.0
     * @param int   $excerpt_length The resolved excerpt length.
     * @param array $atts           The shortcode attributes.
     */
    $excerpt_length = sanitize_excerpt_length(
        apply_filters('rest_posts_embedder_excerpt_length', $excerpt_length, $atts)
    );

    // Check if endpoint is valid
    if (empty($endpoint)) {
        return '<p>' . __('Invalid endpoint URL.', 'restpostsembedder') . '</p>';
    }

    // Create a unique cache key. Includes excerpt length so changing it busts
    // the cache, and a schema version so older cached HTML (without the Load
    // More button) is not served.
    $cache_key = 'rest_posts_embedder_' . md5('v2_' . $endpoint . '_' . $count . '_' . $excerpt_length);

    // Try to get cached posts
    $cached_posts = get_transient($cache_key);
    if (false !== $cached_posts) {
        return $cached_posts;
    }

    // Fetch the first page.
    $fetch = fetch_posts_page($endpoint, $count, 1);
    if (!empty($fetch['error'])) {
        return '<p>' . $fetch['error'] . '</p>';
    }

    $remote_posts = $fetch['posts'];
    if (empty($remote_posts) || !is_array($remote_posts)) {
        return '<p>' . __('No posts found.', 'restpostsembedder') . '</p>';
    }

    // Render the grid.
    $allposts  = '<div class="embed-posts-container">';
    $allposts .= '<div class="embed-posts-wrapper">';
    $allposts .= render_articles($remote_posts, $excerpt_length);
    $allposts .= '</div>'; // Close embed-posts-wrapper

    // Append the Load More button when more pages are available. A server-side
    // token maps back to the endpoint/count/excerpt so the AJAX handler never
    // receives a client-supplied URL.
    if ($fetch['total_pages'] > 1) {
        $allposts .= render_load_more_button($endpoint, $count, $excerpt_length, $fetch['total_pages']);
    }

    $allposts .= '</div>'; // Close embed-posts-container

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
 * Fetch a single page of posts from a REST endpoint.
 *
 * @since 3.7.0
 * @param string $endpoint Validated REST endpoint URL.
 * @param int    $count    Posts per page.
 * @param int    $page     Page number (1-based).
 * @return array {
 *     @type array       $posts       Decoded post objects (empty on error).
 *     @type int         $total_pages Total available pages (0 if unknown).
 *     @type string|null $error       Translated, escaped error message or null.
 * }
 */
function fetch_posts_page($endpoint, $count, $page) {
    $result = array('posts' => array(), 'total_pages' => 0, 'error' => null);

    $args = array(
        'timeout'   => 10,
        'sslverify' => true,
    );

    // Force _embed so author and featured media are returned inside _embedded,
    // regardless of whether the configured endpoint already includes ?_embed.
    $url = add_query_arg(
        array(
            'per_page' => $count,
            'page'     => max(1, absint($page)),
            '_embed'   => 1,
        ),
        $endpoint
    );

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('REST Posts Embedder Error: ' . $error_message . ' | Endpoint: ' . $endpoint);
        $result['error'] = sprintf(
            __('Unable to fetch posts. Error: %s. Please check your endpoint URL in the plugin settings.', 'restpostsembedder'),
            esc_html($error_message)
        );
        return $result;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        // A request past the last page returns 400 with code rest_post_invalid_page_number.
        error_log('REST Posts Embedder HTTP Error: ' . $response_code . ' | Endpoint: ' . $endpoint);
        $result['error'] = sprintf(
            __('Unable to fetch posts. Server returned HTTP error %d. Please verify the endpoint URL is correct.', 'restpostsembedder'),
            (int) $response_code
        );
        return $result;
    }

    $remote_posts = json_decode(wp_remote_retrieve_body($response));

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('REST Posts Embedder JSON Error: ' . json_last_error_msg());
        $result['error'] = __('Unable to parse response from API. Invalid JSON received.', 'restpostsembedder');
        return $result;
    }

    $result['posts'] = is_array($remote_posts) ? $remote_posts : array();
    $result['total_pages'] = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');

    return $result;
}

/**
 * Render the HTML for a set of remote posts (articles only, no wrapper).
 *
 * Shared by the shortcode and the Load More AJAX handler so the markup is
 * identical between the initial render and appended pages.
 *
 * @since 3.7.0
 * @param array $remote_posts   Decoded remote post objects.
 * @param int   $excerpt_length Excerpt length in characters (0 = unlimited).
 * @return string Concatenated <article> HTML.
 */
function render_articles($remote_posts, $excerpt_length) {
    $html = '';
    foreach ($remote_posts as $remote_post) {
        $html .= render_single_post($remote_post, $excerpt_length);
    }
    return $html;
}

/**
 * Render a single embedded post as an <article> element.
 *
 * @since 3.7.0
 * @param object $remote_post    Decoded remote post object.
 * @param int    $excerpt_length Excerpt length in characters (0 = unlimited).
 * @return string The post HTML.
 */
function render_single_post($remote_post, $excerpt_length) {
    // Safely extract post data
    $title = isset($remote_post->title->rendered) ? esc_html($remote_post->title->rendered) : __('Untitled', 'restpostsembedder');
    $link = isset($remote_post->link) ? esc_url($remote_post->link) : '';
    // Translatable date format so locales can drop the English ordinal/"of".
    // English: "8th of June 2026"; Spanish (es_ES catalog): "8 de junio 2026".
    $date_format = _x('jS \of F Y', 'embedded post date format', 'restpostsembedder');
    $fordate = isset($remote_post->modified) ? wp_date($date_format, strtotime($remote_post->modified)) : '';

    // Featured image. Collect every available size and emit a responsive
    // srcset so the browser picks a sharp source for the rendered slot. The
    // old code always used the 300px "medium" size, which the grid upscaled
    // (e.g. 300px shown at ~600px) and blurred.
    $thumb_url    = '';
    $thumb_srcset = '';
    if (!empty($remote_post->featured_media) && isset($remote_post->_embedded->{'wp:featuredmedia'}[0])) {
        $media = $remote_post->_embedded->{'wp:featuredmedia'}[0];

        $candidates = array(); // width (int) => source_url
        if (isset($media->media_details->sizes)) {
            foreach ($media->media_details->sizes as $size_name => $size) {
                // Skip the square "thumbnail" crop — its aspect ratio differs
                // from the others and would distort a width-based srcset.
                if ('thumbnail' === $size_name) {
                    continue;
                }
                if (!empty($size->source_url) && !empty($size->width)) {
                    $candidates[(int) $size->width] = $size->source_url;
                }
            }
        }
        // Include the original image as the top candidate when its width is known.
        if (!empty($media->source_url) && !empty($media->media_details->width)) {
            $candidates[(int) $media->media_details->width] = $media->source_url;
        }

        if (!empty($candidates)) {
            ksort($candidates);
            // Default src = largest available (sharp fallback for browsers
            // that ignore srcset).
            $thumb_url = esc_url(end($candidates));

            $srcset_parts = array();
            foreach ($candidates as $w => $url) {
                $srcset_parts[] = esc_url($url) . ' ' . $w . 'w';
            }
            if (count($srcset_parts) > 1) {
                $thumb_srcset = implode(', ', $srcset_parts);
            }
        } elseif (!empty($media->source_url)) {
            $thumb_url = esc_url($media->source_url);
        }
    }

    // sizes hint: ~full width on mobile, otherwise one column of the grid.
    $columns_desktop = max(1, absint(get_option('embed_posts_columns_desktop', 2)));
    $thumb_sizes = '(max-width: 768px) 100vw, ' . round(100 / $columns_desktop) . 'vw';

    // Author information
    $author_name = __('Unknown Author', 'restpostsembedder');
    $author_name_url = '';
    if (!empty($remote_post->author) && isset($remote_post->_embedded->author[0])) {
        $author = $remote_post->_embedded->author[0];
        if (!empty($author->name)) {
            $author_name = esc_html($author->name);
        }
        // Author archive URL lives in ->link; ->source_url only exists on media
        // objects (that, plus the missing _embed, caused the empty author link).
        if (!empty($author->link)) {
            $author_name_url = esc_url($author->link);
        }
    }

    // Excerpt. When an excerpt length is set, truncate the remote excerpt to
    // that many characters on a word boundary. 0 = render as-is.
    $excerpt = '';
    if (isset($remote_post->excerpt->rendered)) {
        if ($excerpt_length > 0) {
            $trimmed = truncate_excerpt($remote_post->excerpt->rendered, $excerpt_length);
            if ('' !== $trimmed) {
                $excerpt = '<p>' . esc_html($trimmed) . '</p>';
            }
        } else {
            $excerpt = wp_kses_post($remote_post->excerpt->rendered);
        }
    }

    // Link the author name only when an archive URL is available.
    $author_html = $author_name_url
        ? '<a href="' . $author_name_url . '" target="_blank" rel="noopener noreferrer">' . $author_name . '</a>'
        : $author_name;

    // Build individual post HTML (article only, no wrappers)
    $post_html = '<article class="embed-posts">
                    <a href="' . $link . '" target="_blank" rel="noopener noreferrer">
                        <h3>' . $title . '</h3>
                    </a>
                    <small>' . sprintf(__('%1$s, by %2$s', 'restpostsembedder'),
                        $fordate,
                        $author_html) .
                    '</small>
                    <div class="embed-post-content">
                        ' . ($thumb_url ? '<a href="' . $link . '" target="_blank" rel="noopener noreferrer"><img src="' . $thumb_url . '"' . ($thumb_srcset ? ' srcset="' . $thumb_srcset . '" sizes="' . esc_attr($thumb_sizes) . '"' : '') . ' alt="' . esc_attr($title) . '" loading="lazy" /></a>' : '') . '
                        ' . $excerpt . '
                        <a href="' . $link . '" target="_blank" rel="noopener noreferrer" class="read-more">
                            <b>' . __('Read more...', 'restpostsembedder') . '</b>
                        </a>
                    </div>
                </article>';

    /**
     * Filter the HTML for individual post.
     *
     * @since 2.9.0
     * @param string $post_html   The post HTML.
     * @param object $remote_post The remote post object.
     */
    $post_html = apply_filters('rest_posts_embedder_post_html', $post_html, $remote_post);

    return $post_html;
}

/**
 * Build the Load More button markup and register its token.
 *
 * The token is a hash that the AJAX handler resolves back to the endpoint,
 * count and excerpt length via a transient, so the browser never supplies the
 * remote URL directly.
 *
 * @since 3.7.0
 * @param string $endpoint       Validated endpoint URL.
 * @param int    $count          Posts per page (batch size).
 * @param int    $excerpt_length Excerpt length in characters.
 * @param int    $total_pages    Total pages available.
 * @return string Button HTML.
 */
function render_load_more_button($endpoint, $count, $excerpt_length, $total_pages) {
    $token = md5('v2_' . $endpoint . '|' . $count . '|' . $excerpt_length);

    // Persist the token -> context mapping a little longer than the rendered
    // HTML cache so the button keeps working for as long as the page is served
    // from cache.
    $context = array(
        'endpoint'       => $endpoint,
        'count'          => $count,
        'excerpt_length' => $excerpt_length,
    );
    set_transient(
        'rpe_lm_' . $token,
        $context,
        \RestPostsEmbedder\Admin\get_cache_expiration() + DAY_IN_SECONDS
    );

    $label = __('Load More', 'restpostsembedder');

    return '<div class="embed-posts-load-more-wrap">'
        . '<button type="button" class="embed-posts-load-more"'
        . ' data-token="' . esc_attr($token) . '"'
        . ' data-page="1"'
        . ' data-total-pages="' . esc_attr($total_pages) . '">'
        . esc_html($label)
        . '</button>'
        . '</div>';
}

/**
 * AJAX handler for the Load More button.
 *
 * @since 3.7.0
 * @return void Outputs a JSON response and exits.
 */
function rest_posts_embedder_load_more() {
    check_ajax_referer('rest_posts_embedder_load_more', 'nonce');

    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $page  = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 0;

    // Token is always a 32-char md5 hex string; reject anything else.
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || $page < 2) {
        wp_send_json_error(array(
            'message' => __('Invalid request.', 'restpostsembedder'),
        ));
    }

    $context = get_transient('rpe_lm_' . $token);
    if (!is_array($context) || empty($context['endpoint'])) {
        wp_send_json_error(array(
            'message' => __('This feed has expired. Please refresh the page.', 'restpostsembedder'),
            'expired' => true,
        ));
    }

    $endpoint       = $context['endpoint'];
    $count          = absint($context['count']);
    $count          = ($count > 0 && $count <= REST_POSTS_EMBEDDER_MAX_COUNT) ? $count : REST_POSTS_EMBEDDER_DEFAULT_COUNT;
    $excerpt_length = isset($context['excerpt_length']) ? sanitize_excerpt_length($context['excerpt_length']) : 0;

    // Cache each rendered page so repeated clicks don't re-hit the remote.
    $page_cache_key = 'rest_posts_embedder_p_' . md5('v2_' . $endpoint . '|' . $count . '|' . $excerpt_length . '|' . $page);
    $cached = get_transient($page_cache_key);
    if (false !== $cached) {
        wp_send_json_success($cached);
    }

    $fetch = fetch_posts_page($endpoint, $count, $page);
    if (!empty($fetch['error'])) {
        wp_send_json_error(array('message' => $fetch['error']));
    }

    if (empty($fetch['posts'])) {
        wp_send_json_success(array('html' => '', 'has_more' => false));
    }

    $payload = array(
        'html'     => render_articles($fetch['posts'], $excerpt_length),
        'has_more' => ($fetch['total_pages'] > 0 && $page < $fetch['total_pages']),
    );

    set_transient($page_cache_key, $payload, \RestPostsEmbedder\Admin\get_cache_expiration());

    wp_send_json_success($payload);
}

/**
 * Enqueue the plugin's CSS and Load More JavaScript.
 *
 * @since 1.0.0
 * @return void
 */
function display_posts_enqueue_styles() {
    // Define the path to the CSS file
    $css_path = plugin_dir_url( dirname(__FILE__) ) . 'assets/css/custom.css';

    // Enqueue the CSS file with dynamic version
    wp_enqueue_style( 'display-posts-style', $css_path, array(), REST_POSTS_EMBEDDER_VERSION, 'all' );

    // Load More script. Lightweight and only acts when a button is present.
    $js_path = plugin_dir_url( dirname(__FILE__) ) . 'assets/js/load-more.js';
    wp_enqueue_script( 'rest-posts-embedder-load-more', $js_path, array(), REST_POSTS_EMBEDDER_VERSION, true );
    wp_localize_script( 'rest-posts-embedder-load-more', 'restPostsEmbedderLoadMore', array(
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('rest_posts_embedder_load_more'),
        'loadingText' => __('Loading…', 'restpostsembedder'),
        'errorText'   => __('Could not load more posts. Please try again.', 'restpostsembedder'),
    ) );

    // Get settings
    $columns_desktop = absint(get_option('embed_posts_columns_desktop', 2));
    $columns_mobile = absint(get_option('embed_posts_columns_mobile', 1));
    $show_images_desktop = absint(get_option('embed_posts_show_images_desktop', 1));
    $show_images_mobile = absint(get_option('embed_posts_show_images_mobile', 1));

    $inline_css = "
    /* Fix wrapper layout */
    .wrapper {
        width: 100%;
        max-width: 100%;
        float: none;
        padding: 0;
        margin: 0;
    }

    /* Column configuration for desktop */
    .embed-posts-wrapper {
        grid-template-columns: repeat({$columns_desktop}, 1fr);
    }

    /* Hide images on desktop if disabled */
    " . (!$show_images_desktop ? ".embed-posts img { display: none; }" : "") . "

    /* Mobile styles */
    @media only screen and (max-width: 768px) {
        .embed-posts-wrapper {
            grid-template-columns: repeat({$columns_mobile}, 1fr);
        }

        /* Hide images on mobile if disabled */
        " . (!$show_images_mobile ? ".embed-posts img { display: none !important; }" : "") . "
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
