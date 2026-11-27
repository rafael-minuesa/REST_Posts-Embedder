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
 * caller is responsible for escaping). Multibyte-safe with or without the
 * mbstring extension.
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

    // WordPress provides mb_strlen() and mb_substr() on hosts without the
    // mbstring extension, so both are always safe to call. It provides no
    // mb_strrpos(), so nothing below may depend on it.
    if (mb_strlen($plain, 'UTF-8') <= $length) {
        return $plain;
    }

    $cut = mb_substr($plain, 0, $length, 'UTF-8');

    // Back off to the last whole word, unless that would leave nothing. A
    // space is a single byte that never occurs inside a multibyte UTF-8
    // character, so a byte offset paired with substr() cuts cleanly.
    $last_space = strrpos($cut, ' ');
    if (false !== $last_space && $last_space > 0) {
        $cut = substr($cut, 0, $last_space);
    }

    return rtrim($cut) . '…';
}

/**
 * Keep an excerpt word count within 0 (not used) and 200.
 *
 * @since 3.10.0
 * @param mixed $value Raw value.
 * @return int
 */
function sanitize_excerpt_words($value) {
    return is_scalar($value) ? min(200, absint($value)) : 0;
}

/**
 * Whether a feed shows its Load More button. Empty (the default) means yes;
 * "no", "false", "off" and "0" turn it off.
 *
 * @since 3.10.0
 * @param mixed $value The load_more attribute.
 * @return bool
 */
function load_more_enabled($value) {
    if (!is_scalar($value)) {
        return true;
    }
    return !in_array(strtolower(trim((string) $value)), array('no', 'false', 'off', '0'), true);
}

/**
 * Shorten an excerpt for a card.
 *
 * A word count wins over a character count. With neither, the excerpt is
 * kept whole, with its safe HTML.
 *
 * @since 3.10.0
 * @param string $html           Rendered excerpt HTML.
 * @param int    $excerpt_length Maximum characters (0 = no limit).
 * @param int    $excerpt_words  Maximum words (0 = not used).
 * @return string Excerpt HTML, or '' when there is none.
 */
function format_excerpt($html, $excerpt_length, $excerpt_words = 0) {
    if ('' === trim(wp_strip_all_tags($html))) {
        return '';
    }
    if ($excerpt_words > 0) {
        $plain = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8');
        // wp_trim_words() counts characters instead in languages written
        // without spaces, as its translators decide.
        $trimmed = wp_trim_words($plain, $excerpt_words, '…');
    } elseif ($excerpt_length > 0) {
        $trimmed = truncate_excerpt($html, $excerpt_length);
    } else {
        return wp_kses_post($html);
    }
    return '' !== $trimmed ? '<p>' . esc_html($trimmed) . '</p>' : '';
}

/**
 * Find the stored ID of a feed source from an ID as it was requested.
 *
 * Sources are stored under the ID generated when they were created. Up to
 * 3.8.0 that ID could contain percent-encoded bytes (a source named "東京" got
 * "%e6%9d%b1%e4%ba%ac_1700000000"), which sanitize_key() strips, so shortcode,
 * edit and delete never found it. Those IDs stay as they are, because they are
 * written in shortcodes across the site; this lookup accepts every form they
 * arrive in: as stored, with the percent signs stripped, or URL-decoded.
 *
 * @since 3.9.0
 * @param mixed $sources   Stored sources, keyed by ID.
 * @param mixed $requested ID from a shortcode attribute or a request.
 * @return string The stored ID, or '' when no source matches.
 */
function resolve_source_id($sources, $requested) {
    if (!is_array($sources) || !is_string($requested) || '' === $requested) {
        return '';
    }
    if (isset($sources[$requested])) {
        return $requested;
    }
    // How IDs were looked up until 3.8.0; every ID that worked keeps working.
    $key = sanitize_key($requested);
    if ('' !== $key && isset($sources[$key])) {
        return $key;
    }

    $canonical = function ($id) {
        return sanitize_key(sanitize_title((string) $id));
    };
    $wanted = $canonical($requested);
    if ('' === $wanted) {
        return '';
    }
    foreach (array_keys($sources) as $stored) {
        if ($canonical($stored) === $wanted) {
            return (string) $stored;
        }
    }
    return '';
}

/**
 * Whether an endpoint is the bundled ProWoos demo feed.
 *
 * Matches the current demo endpoint and the legacy one (all languages) that
 * existing installs still have stored, ignoring the _embed parameter, which
 * every request forces anyway.
 *
 * @since 3.8.0
 * @param mixed $endpoint Endpoint URL.
 * @return bool
 */
function is_demo_endpoint($endpoint) {
    if (!is_string($endpoint) || '' === $endpoint) {
        return false;
    }
    $normalize = function ($url) {
        return untrailingslashit(strtolower(remove_query_arg('_embed', $url)));
    };
    $demo_endpoints = array_map($normalize, array(
        REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT,
        REST_POSTS_EMBEDDER_LEGACY_DEMO_ENDPOINT,
    ));
    return in_array($normalize($endpoint), $demo_endpoints, true);
}

/**
 * Notice shown above the demo feed, to administrators only.
 *
 * Returned separately from the feed HTML so it never ends up in the cache,
 * which is shared by every visitor.
 *
 * @since 3.8.0
 * @return string Notice HTML, or an empty string for everyone else.
 */
function render_demo_notice() {
    if (!current_user_can('manage_options')) {
        return '';
    }

    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=embed-posts-settings')) . '">'
        . esc_html__('Settings → REST Posts Embedder', 'restpostsembedder')
        . '</a>';

    return '<div class="embed-posts-demo-notice" role="note">'
        . '<p><strong>' . esc_html__('Demo feed.', 'restpostsembedder') . '</strong> '
        . esc_html__('These sample posts come from ProWoos and are shown for demonstration only.', 'restpostsembedder')
        . '</p>'
        . '<p>' . sprintf(
            /* translators: %s: link to the plugin settings page. */
            esc_html__('To show your own posts, add a feed source in %s and use the shortcode shown for it.', 'restpostsembedder'),
            $settings_link
        ) . '</p>'
        . '<p class="embed-posts-demo-notice-meta">' . esc_html__('Only administrators see this notice.', 'restpostsembedder') . '</p>'
        . '</div>';
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
        'endpoint' => \RestPostsEmbedder\get_default_endpoint(),
        'count' => get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT),
        // Per-feed excerpt length in characters. Empty string means "fall back
        // to the source value, then the global default".
        'excerpt_length' => '',
        // Excerpt length in words (since 3.10.0). Wins over excerpt_length.
        'excerpt_words' => '',
        // Card layout ID. Empty means the source's layout, then the site's.
        'layout' => '',
        // Columns on desktop and on tablets. Empty means the Styling settings.
        'columns' => '',
        'columns_tablet' => '',
        // Query of a source="this-site" feed; ignored by remote feeds.
        'category' => '',
        'tag' => '',
        'orderby' => '',
        'order' => '',
        'offset' => '',
        // "no" hides the Load More button. Empty means shown.
        'load_more' => '',
    ), $atts, 'posts_embedder');

    // A feed of this site's own posts (source="this-site").
    $is_local = false;

    // Layout requested by the source, when the feed comes from one.
    $source_layout = '';

    // Source this feed is bound to, for its Load More button ('' = none).
    $bound_source   = '';
    $bound_revision = '';

    // Multi-source support
    if (!empty($atts['source'])) {
        $sources = \RestPostsEmbedder\get_sources();
        $source_id = resolve_source_id($sources, $atts['source']);

        if ('' !== $source_id) {
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
            $bound_source   = $source_id;
            $bound_revision = source_revision($source);
            // Only inherit the source excerpt length when not overridden inline.
            if ('' === $atts['excerpt_length'] && isset($source['excerpt_length'])) {
                $atts['excerpt_length'] = $source['excerpt_length'];
            }
            $source_layout = isset($source['layout']) ? $source['layout'] : '';
        } elseif (is_local_source($atts['source'])) {
            $is_local = true;
        } else {
            return '<p>' . sprintf(
                __('Feed source "%s" not found. Please check your source ID in the plugin settings.', 'restpostsembedder'),
                esc_html(sanitize_key($atts['source']))
            ) . '</p>';
        }
    }

    // Normalize inputs. Restrict to http/https to limit the SSRF surface from a
    // shortcode-supplied endpoint. Whether WordPress considers the URL safe to
    // request is checked further down, only when a request is about to be made:
    // that check resolves the hostname, and a DNS failure must not hide a feed
    // that is already cached.
    $endpoint = esc_url_raw($atts['endpoint'], array('http', 'https'));
    // Feeds still stored with the legacy demo endpoint (all languages) get the
    // current demo endpoint (English only).
    if (is_demo_endpoint($endpoint)) {
        $endpoint = REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT;
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

    $excerpt_words = sanitize_excerpt_words($atts['excerpt_words']);
    $layout = resolve_layout($atts['layout'], $source_layout);
    $columns_style = columns_style($atts['columns'], $atts['columns_tablet']);
    $load_more = load_more_enabled($atts['load_more']);

    /**
     * Filter the sanitized endpoint URL before making the API request.
     *
     * @since 2.9.0
     * @param string $endpoint The sanitized endpoint URL (http or https).
     * @param array  $atts     The shortcode attributes.
     */
    if (!$is_local) {
        $endpoint = apply_filters('rest_posts_embedder_endpoint', $endpoint, $atts);
    }

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

    if ($is_local) {
        return render_local_feed(sanitize_local_query($atts), $count, $excerpt_length, $layout, $columns_style, $atts, $excerpt_words, $load_more);
    }

    // Check if endpoint is valid
    if (empty($endpoint)) {
        return '<p>' . __('Invalid endpoint URL.', 'restpostsembedder') . '</p>';
    }

    // Administrators get a notice above the demo feed. Prepended to every
    // return below, never cached.
    $demo_notice = is_demo_endpoint($endpoint) ? render_demo_notice() : '';

    // Create a unique cache key. Includes excerpt length so changing it busts
    // the cache, a schema version so older cached HTML (without the Load More
    // button) is not served, and the cache generation so "Clear All Cache Now"
    // invalidates it on any cache backend. The cached HTML holds translated
    // text ("Read more...", the date), so the display locale is part of the key
    // too: one visitor's language must not be served to the next.
    // The HTML also holds the Load More button, whose token is tied to the
    // source the feed came from, so that is part of the key as well: a named
    // source and a plain endpoint must not share each other's button. So is
    // the layout, which decides all of the markup.
    $locale    = determine_locale();
    $cache_key = 'rest_posts_embedder_' . md5('v2_' . \RestPostsEmbedder\Admin\get_cache_generation() . '_' . $endpoint . '_' . $count . '_' . $excerpt_length . '_' . $locale . '_' . $bound_source . '_' . $bound_revision . '_' . $layout . ('' !== $columns_style ? '_' . $columns_style : '') . ($excerpt_words > 0 ? '_w' . $excerpt_words : '') . ($load_more ? '' : '_nolm'));

    // Try to get cached posts
    $cached_posts = get_transient($cache_key);
    if (false !== $cached_posts) {
        return $demo_notice . $cached_posts;
    }

    // Nothing cached, so a request is needed. Reject URLs WordPress considers
    // unsafe (blocked hosts, internal addresses, hostnames that do not resolve).
    // wp_safe_remote_get() applies the same check to the request and to every
    // redirect; doing it here as well gives the visitor a clear message.
    if (!wp_http_validate_url($endpoint)) {
        return $demo_notice . '<p>' . __('Invalid endpoint URL.', 'restpostsembedder') . '</p>';
    }

    // Fetch the first page.
    $fetch = fetch_posts_page($endpoint, $count, 1);
    if (!empty($fetch['error'])) {
        return $demo_notice . '<p>' . $fetch['error'] . '</p>';
    }

    remember_page_bound($endpoint, $count, $fetch['total_pages'], \RestPostsEmbedder\Admin\get_cache_expiration());

    $remote_posts = $fetch['posts'];
    if (empty($remote_posts) || !is_array($remote_posts)) {
        return $demo_notice . '<p>' . __('No posts found.', 'restpostsembedder') . '</p>';
    }

    // Render the grid.
    $allposts  = '<div class="embed-posts-container embed-posts-layout-' . esc_attr($layout) . '">';
    $allposts .= '<div class="embed-posts-wrapper"' . ('' !== $columns_style ? ' style="' . esc_attr($columns_style) . '"' : '') . '>';
    $allposts .= render_articles($remote_posts, $excerpt_length, $layout, $excerpt_words);
    $allposts .= '</div>'; // Close embed-posts-wrapper

    // Append the Load More button when more pages are available. A server-side
    // token maps back to the endpoint/count/excerpt so the AJAX handler never
    // receives a client-supplied URL.
    if ($load_more && $fetch['total_pages'] > 1) {
        $allposts .= render_load_more_button($endpoint, $count, $excerpt_length, $fetch['total_pages'], $locale, $bound_source, $bound_revision, $layout, $excerpt_words);
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

    return $demo_notice . $allposts;
}

/**
 * Number of grid columns from a shortcode attribute.
 *
 * @since 3.10.0
 * @param mixed $value Attribute value.
 * @return int Columns between 1 and REST_POSTS_EMBEDDER_MAX_COLUMNS, or 0
 *             when the attribute is empty or not a number.
 */
function sanitize_columns($value) {
    if (!is_scalar($value) || !is_numeric(trim((string) $value))) {
        return 0;
    }
    // (int), not absint(): a negative count is too small, not its opposite.
    return max(1, min(REST_POSTS_EMBEDDER_MAX_COLUMNS, (int) $value));
}

/**
 * Inline style that overrides the column counts of one feed.
 *
 * The Styling settings set --rpe-cols, --rpe-cols-tablet and
 * --rpe-cols-mobile for every feed (see build_inline_css()); a feed's own
 * attributes override them on its wrapper. Without columns_tablet, a feed
 * with its own columns gets min(columns, 2) on tablets.
 *
 * @since 3.10.0
 * @param mixed $columns        Desktop columns attribute.
 * @param mixed $columns_tablet Tablet columns attribute.
 * @return string CSS declarations, or '' when neither attribute is set.
 */
function columns_style($columns, $columns_tablet) {
    $style   = '';
    $desktop = sanitize_columns($columns);
    $tablet  = sanitize_columns($columns_tablet);
    // Tablets never get more columns than desktops by default.
    if (0 === $tablet && $desktop > 0) {
        $tablet = min($desktop, 2);
    }
    if ($desktop > 0) {
        $style .= '--rpe-cols: ' . $desktop . ';';
    }
    if ($tablet > 0) {
        $style .= ('' !== $style ? ' ' : '') . '--rpe-cols-tablet: ' . $tablet . ';';
    }
    return $style;
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
 *     @type int         $status      HTTP status of the response (0 when the request failed).
 * }
 */
function fetch_posts_page($endpoint, $count, $page) {
    $result = array('posts' => array(), 'total_pages' => 0, 'error' => null, 'status' => 0);

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

    // wp_safe_remote_get() validates the URL and every redirect it follows
    // with wp_http_validate_url(). Checking only the configured endpoint is
    // not enough: an allowed URL can redirect to an internal address.
    $response = wp_safe_remote_get($url, $args);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log('REST Posts Embedder Error: ' . $error_message . ' | Endpoint: ' . redact_url($endpoint));
        $result['error'] = sprintf(
            __('Unable to fetch posts. Error: %s. Please check your endpoint URL in the plugin settings.', 'restpostsembedder'),
            esc_html($error_message)
        );
        return $result;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $result['status'] = (int) $response_code;
    if ($response_code !== 200) {
        // A request past the last page returns 400 with code
        // rest_post_invalid_page_number. That is the normal end of a feed that
        // shrank, not a fault, and anyone can trigger it, so it is not logged.
        if (!(400 === $result['status'] && $page > 1)) {
            error_log('REST Posts Embedder HTTP Error: ' . $response_code . ' | Endpoint: ' . redact_url($endpoint));
        }
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

    // A list of posts is a JSON array of objects. Drop anything else (an API
    // error object, nulls, strings) so one bad item cannot break the page.
    $result['posts'] = is_array($remote_posts) ? array_values(array_filter($remote_posts, 'is_object')) : array();
    $result['total_pages'] = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');

    return $result;
}

/**
 * Reduce a URL to scheme, host and path for the error log.
 *
 * A feed URL can carry credentials in its query string (an API key, a signed
 * URL) or in a user:password@ prefix. Those must not be copied into the PHP
 * error log, which is often readable by more people than the settings are.
 *
 * @since 3.9.0
 * @param mixed $url URL to redact.
 * @return string Redacted URL, or a placeholder when it cannot be parsed.
 */
function redact_url($url) {
    $parts = is_string($url) ? wp_parse_url($url) : false;
    if (!is_array($parts) || empty($parts['host'])) {
        return '[unparseable URL]';
    }
    $redacted = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') . $parts['host'];
    if (isset($parts['port'])) {
        $redacted .= ':' . $parts['port'];
    }
    if (isset($parts['path'])) {
        $redacted .= $parts['path'];
    }
    return $redacted . (isset($parts['query']) ? '?[redacted]' : '');
}

/**
 * Revision of a feed source's fetch configuration.
 *
 * Changes when the endpoint or the batch size changes, the two settings that
 * decide what a Load More button fetches. Renaming a source or changing its
 * excerpt length keeps the revision, so buttons on cached pages keep working.
 *
 * @since 3.9.0
 * @param mixed $source Stored source.
 * @return string Revision hash.
 */
function source_revision($source) {
    $endpoint = (is_array($source) && isset($source['endpoint']) && is_string($source['endpoint'])) ? $source['endpoint'] : '';
    $count    = (is_array($source) && isset($source['count'])) ? absint($source['count']) : 0;
    return md5($endpoint . '|' . $count);
}

/**
 * Transient name of the known number of pages for a feed.
 *
 * @since 3.9.0
 * @param string $endpoint Endpoint URL.
 * @param int    $count    Posts per page.
 * @return string
 */
function page_bound_key($endpoint, $count) {
    return 'rest_posts_embedder_tp_' . md5(\RestPostsEmbedder\Admin\get_cache_generation() . '|' . $endpoint . '|' . $count);
}

/**
 * Remember how many pages a feed has, so the public Load More handler can
 * answer requests past the end without contacting the remote site.
 *
 * @since 3.9.0
 * @param string $endpoint    Endpoint URL.
 * @param int    $count       Posts per page.
 * @param int    $total_pages Number of pages (ignored when not positive).
 * @param int    $ttl         Seconds to remember it for.
 * @return void
 */
function remember_page_bound($endpoint, $count, $total_pages, $ttl) {
    $total_pages = (int) $total_pages;
    if ($total_pages > 0) {
        set_transient(page_bound_key($endpoint, $count), $total_pages, max(MINUTE_IN_SECONDS, (int) $ttl));
    }
}

/**
 * Ask the remote for a feed's number of pages and remember it.
 *
 * Fetches the first page, whose X-WP-TotalPages header gives the count. A
 * failure is remembered for a minute, so requests arriving while the remote
 * is down do not each wait for it. A remote that sends no count is treated as
 * a single page, which is also when no Load More button is rendered.
 *
 * @since 3.9.0
 * @param string $endpoint Endpoint URL.
 * @param int    $count    Posts per page.
 * @return int|false Number of pages, or false when it could not be fetched.
 */
function learn_page_bound($endpoint, $count) {
    $failed_key = page_bound_key($endpoint, $count) . '_f';
    if (false !== get_transient($failed_key)) {
        return false;
    }
    $fetch = fetch_posts_page($endpoint, $count, 1);
    if (!empty($fetch['error'])) {
        set_transient($failed_key, 1, MINUTE_IN_SECONDS);
        return false;
    }
    $total_pages = max(1, (int) $fetch['total_pages']);
    remember_page_bound($endpoint, $count, $total_pages, \RestPostsEmbedder\Admin\get_cache_expiration());
    return $total_pages;
}

/**
 * Whether a stored Load More context may still be used.
 *
 * A button on a cached page keeps its token for as long as that page is
 * cached, but the feed behind it can be edited, disabled or deleted in the
 * meantime. A context bound to a source is valid only while that source
 * exists, is enabled and still fetches the same endpoint and batch size.
 *
 * Contexts written by 3.8.0 and earlier do not record their source. They are
 * accepted only while they match a feed that is configured now: an enabled
 * source with the same endpoint and batch size, or the default endpoint. Any
 * other one expires at once. That includes buttons of shortcodes with an
 * inline endpoint on pages cached before the update, which cannot be told
 * apart from a retired source; they work again once the page is rebuilt.
 *
 * @since 3.9.0
 * @param array $context Stored context.
 * @return bool
 */
function is_load_more_context_current($context) {
    // The same sources the shortcode rendered from, including the defaults of
    // a site that has none stored (see \RestPostsEmbedder\get_sources()).
    $sources = \RestPostsEmbedder\get_sources();

    if (array_key_exists('source', $context)) {
        if ('' === $context['source']) {
            return true; // Not bound to a source.
        }
        $source = isset($sources[$context['source']]) ? $sources[$context['source']] : null;
        return is_array($source)
            && !empty($source['enabled'])
            && isset($context['revision'])
            && source_revision($source) === $context['revision'];
    }

    // Context from 3.8.0 or earlier.
    $normalize = function ($endpoint) {
        $endpoint = esc_url_raw((string) $endpoint, array('http', 'https'));
        return is_demo_endpoint($endpoint) ? REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT : $endpoint;
    };
    $endpoint = $normalize($context['endpoint']);
    $count    = isset($context['count']) ? absint($context['count']) : 0;

    foreach ($sources as $source) {
        if (
            is_array($source) && !empty($source['enabled']) && isset($source['endpoint'], $source['count'])
            && $normalize($source['endpoint']) === $endpoint && absint($source['count']) === $count
        ) {
            return true;
        }
    }
    return $normalize(\RestPostsEmbedder\get_default_endpoint()) === $endpoint;
}

/**
 * Render the HTML for a set of remote posts (articles only, no wrapper).
 *
 * Shared by the shortcode and the Load More AJAX handler so the markup is
 * identical between the initial render and appended pages.
 *
 * @since 3.7.0
 * @param array  $remote_posts   Decoded remote post objects.
 * @param int    $excerpt_length Excerpt length in characters (0 = unlimited).
 * @param string $layout         Layout ID (since 3.10.0).
 * @param int    $excerpt_words  Excerpt length in words, 0 = not used (since 3.10.0).
 * @return string Concatenated <article> HTML.
 */
function render_articles($remote_posts, $excerpt_length, $layout = DEFAULT_LAYOUT, $excerpt_words = 0) {
    $html = '';
    foreach ($remote_posts as $remote_post) {
        // A feed item that is not a post object cannot be rendered; skip it
        // so the rest of the page stays usable.
        if (!is_object($remote_post)) {
            continue;
        }
        $html .= render_single_post($remote_post, $excerpt_length, $layout, $excerpt_words);
    }
    return $html;
}

/**
 * Read a text field from a remote post.
 *
 * A successful JSON decode says nothing about the type of each field. The
 * renderer passes these values to functions that require strings, and an
 * array or object there is a fatal error on PHP 8. Anything that is not a
 * string counts as missing.
 *
 * @since 3.9.0
 * @param mixed $value Decoded JSON value.
 * @return string The value, or '' when it is not a string.
 */
function feed_string($value) {
    return is_string($value) ? $value : '';
}

/**
 * Convert a remote post's GMT date to a Unix timestamp.
 *
 * The REST API returns dates without a timezone, as "2026-07-01T22:30:00".
 * The *_gmt fields are UTC by definition, so the string is parsed as UTC
 * explicitly instead of in whatever timezone PHP defaults to.
 *
 * @since 3.9.0
 * @param mixed $gmt_date Value of a *_gmt field.
 * @return int|false Timestamp, or false when the value is not a valid date.
 */
function feed_gmt_timestamp($gmt_date) {
    if (!is_string($gmt_date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $gmt_date)) {
        return false;
    }
    $date = date_create_immutable($gmt_date, new \DateTimeZone('UTC'));
    if (!$date) {
        return false;
    }
    // Reject dates PHP silently rolls over, such as 2026-02-31.
    if ($date->format('Y-m-d\TH:i:s') !== $gmt_date) {
        return false;
    }
    return $date->getTimestamp();
}

/**
 * Render a single embedded post with a layout.
 *
 * @since 3.7.0
 * @param object $remote_post    Decoded remote post object.
 * @param int    $excerpt_length Excerpt length in characters (0 = unlimited).
 * @param string $layout         Layout ID (since 3.10.0).
 * @param int    $excerpt_words  Excerpt length in words, 0 = not used (since 3.10.0).
 * @return string The post HTML.
 */
function render_single_post($remote_post, $excerpt_length, $layout = DEFAULT_LAYOUT, $excerpt_words = 0) {
    $card = $remote_post instanceof \WP_Post
        ? prepare_local_card($remote_post, $excerpt_length, $excerpt_words)
        : prepare_card($remote_post, $excerpt_length, $excerpt_words);
    $post_html = render_card($card, $layout);

    /**
     * Filter the HTML for individual post.
     *
     * @since 2.9.0
     * @since 3.10.0 The $layout parameter.
     * @param string $post_html   The post HTML.
     * @param object $remote_post The remote post object, or a WP_Post for a
     *                            "This site" feed.
     * @param string $layout      Layout ID the card was rendered with.
     */
    return apply_filters('rest_posts_embedder_post_html', $post_html, $remote_post, $layout);
}

/**
 * Card data of one remote post, ready for a layout template.
 *
 * Every string is already escaped for the context its key names, so a
 * template prints them as they are:
 *
 * - title        Post title, escaped HTML ("Untitled" when empty).
 * - link         Post URL, escaped for an attribute.
 * - link_target  Attributes that open the post in a new tab, with a leading
 *                space, or '' for links that stay in the same tab.
 * - date         Date the post was last modified, formatted for display and
 *                escaped, or '' when the feed gives no valid date.
 * - timestamp    That date as a Unix timestamp, or false.
 * - date_iso     That date in ISO 8601 for a datetime attribute, or ''.
 * - author       Author name, escaped HTML.
 * - author_url   Author archive URL, escaped, or ''.
 * - author_html  Author name, linked when author_url is set.
 * - byline       "date, by author", or "by author" without a date.
 * - image_src    Featured image URL, escaped, or '' when there is none.
 * - image_srcset srcset attribute value, escaped, or ''.
 * - image_sizes  sizes attribute value, escaped.
 * - image_alt    Image alt text, escaped for an attribute.
 * - excerpt      Excerpt HTML, or ''.
 * - read_more    "Read more..." label, escaped.
 * - post         The remote post object as decoded, NOT escaped.
 *
 * @since 3.10.0
 * @param object $remote_post    Decoded remote post object.
 * @param int    $excerpt_length Excerpt length in characters (0 = unlimited).
 * @param int    $excerpt_words  Excerpt length in words (0 = not used).
 * @return array
 */
function prepare_card($remote_post, $excerpt_length, $excerpt_words = 0) {
    // Safely extract post data
    $title = feed_string(isset($remote_post->title->rendered) ? $remote_post->title->rendered : '');
    $title = '' !== $title ? esc_html($title) : __('Untitled', 'restpostsembedder');
    $link = esc_url(feed_string(isset($remote_post->link) ? $remote_post->link : ''));
    // Translatable date format so locales can drop the English ordinal/"of".
    // English: "8th of June 2026"; Spanish (es_ES catalog): "8 de junio 2026".
    $date_format = _x('jS \of F Y', 'embedded post date format', 'restpostsembedder');
    // Use the GMT field: "modified" is in the remote site's own timezone, which
    // this site does not know. wp_date() then formats once, in this site's
    // timezone. Without a valid date the byline shows the author alone.
    $timestamp = feed_gmt_timestamp(isset($remote_post->modified_gmt) ? $remote_post->modified_gmt : null);
    $fordate = false !== $timestamp ? (string) wp_date($date_format, $timestamp) : '';

    // Featured image. Collect every available size and emit a responsive
    // srcset so the browser picks a sharp source for the rendered slot. The
    // old code always used the 300px "medium" size, which the grid upscaled
    // (e.g. 300px shown at ~600px) and blurred.
    $thumb_url    = '';
    $thumb_srcset = '';
    $media = null;
    if (
        !empty($remote_post->featured_media)
        && isset($remote_post->_embedded->{'wp:featuredmedia'})
        && is_array($remote_post->_embedded->{'wp:featuredmedia'})
        && isset($remote_post->_embedded->{'wp:featuredmedia'}[0])
        && is_object($remote_post->_embedded->{'wp:featuredmedia'}[0])
    ) {
        $media = $remote_post->_embedded->{'wp:featuredmedia'}[0];
    }
    if ($media) {
        $media_url = feed_string(isset($media->source_url) ? $media->source_url : '');

        $candidates = array(); // width (int) => source_url
        $sizes = isset($media->media_details->sizes) ? $media->media_details->sizes : null;
        if (is_object($sizes) || is_array($sizes)) {
            foreach ($sizes as $size_name => $size) {
                // Skip the square "thumbnail" crop — its aspect ratio differs
                // from the others and would distort a width-based srcset.
                if ('thumbnail' === $size_name || !is_object($size)) {
                    continue;
                }
                $size_url = feed_string(isset($size->source_url) ? $size->source_url : '');
                if ('' !== $size_url && isset($size->width) && is_numeric($size->width) && $size->width > 0) {
                    $candidates[(int) $size->width] = $size_url;
                }
            }
        }
        // Include the original image as the top candidate when its width is known.
        if ('' !== $media_url && isset($media->media_details->width) && is_numeric($media->media_details->width) && $media->media_details->width > 0) {
            $candidates[(int) $media->media_details->width] = $media_url;
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
        } elseif ('' !== $media_url) {
            $thumb_url = esc_url($media_url);
        }
    }

    // sizes hint: ~full width on mobile, otherwise one column of the grid.
    $columns_desktop = max(1, absint(get_option('embed_posts_columns_desktop', 2)));
    $thumb_sizes = '(max-width: 768px) 100vw, ' . round(100 / $columns_desktop) . 'vw';

    // Author information
    $author_name = esc_html__('Unknown Author', 'restpostsembedder');
    $author_name_url = '';
    if (
        !empty($remote_post->author)
        && isset($remote_post->_embedded->author)
        && is_array($remote_post->_embedded->author)
        && isset($remote_post->_embedded->author[0])
        && is_object($remote_post->_embedded->author[0])
    ) {
        $author = $remote_post->_embedded->author[0];
        $remote_author_name = feed_string(isset($author->name) ? $author->name : '');
        if ('' !== $remote_author_name) {
            $author_name = esc_html($remote_author_name);
        }
        // Author archive URL lives in ->link; ->source_url only exists on media
        // objects (that, plus the missing _embed, caused the empty author link).
        $author_name_url = esc_url(feed_string(isset($author->link) ? $author->link : ''));
    }

    // Excerpt, shortened to the feed's length.
    $excerpt = format_excerpt(feed_string(isset($remote_post->excerpt->rendered) ? $remote_post->excerpt->rendered : ''), $excerpt_length, $excerpt_words);

    // Link the author name only when an archive URL is available.
    $author_html = $author_name_url
        ? '<a href="' . $author_name_url . '" target="_blank" rel="noopener noreferrer">' . $author_name . '</a>'
        : $author_name;

    if ('' !== $fordate) {
        /* translators: 1: date the post was last modified, 2: author name. */
        $byline = sprintf(__('%1$s, by %2$s', 'restpostsembedder'), esc_html($fordate), $author_html);
    } else {
        /* translators: %s: author name. */
        $byline = sprintf(__('by %s', 'restpostsembedder'), $author_html);
    }

    return array(
        'title'        => $title,
        'link'         => $link,
        'link_target'  => ' target="_blank" rel="noopener noreferrer"',
        'date'         => esc_html($fordate),
        'timestamp'    => $timestamp,
        'date_iso'     => false !== $timestamp ? esc_attr((string) wp_date('c', $timestamp)) : '',
        'author'       => $author_name,
        'author_url'   => $author_name_url,
        'author_html'  => $author_html,
        'byline'       => $byline,
        'image_src'    => (string) $thumb_url,
        'image_srcset' => (string) $thumb_srcset,
        'image_sizes'  => esc_attr($thumb_sizes),
        'image_alt'    => esc_attr($title),
        'excerpt'      => $excerpt,
        'read_more'    => esc_html__('Read more...', 'restpostsembedder'),
        'post'         => $remote_post,
    );
}

/**
 * Build the Load More button markup and register its token.
 *
 * The token is a hash that the AJAX handler resolves back to the endpoint,
 * count and excerpt length, so the browser never supplies the remote URL
 * directly.
 *
 * @since 3.7.0
 * @param string $endpoint       Validated endpoint URL.
 * @param int    $count          Posts per page (batch size).
 * @param int    $excerpt_length Excerpt length in characters.
 * @param int    $total_pages    Total pages available.
 * @param string $locale         Locale the page was rendered in. Sent back by
 *                               the button so appended posts match the page.
 * @param string $source         ID of the source the feed comes from ('' for
 *                               a feed that is not a named source).
 * @param string $revision       source_revision() of that source.
 * @param string $layout         Layout ID the feed is rendered with (since 3.10.0).
 * @param int    $excerpt_words  Excerpt length in words, 0 = not used (since 3.10.0).
 * @return string Button HTML.
 */
function render_load_more_button($endpoint, $count, $excerpt_length, $total_pages, $locale = '', $source = '', $revision = '', $layout = DEFAULT_LAYOUT, $excerpt_words = 0) {
    // Classic feeds keep the token and context of 3.9.0, so buttons on pages
    // cached before the update keep their stored context.
    $classic = DEFAULT_LAYOUT === $layout;
    $token   = md5('v3_' . $source . '|' . $revision . '|' . $endpoint . '|' . $count . '|' . $excerpt_length . ($classic ? '' : '|' . $layout) . ($excerpt_words > 0 ? '|w' . $excerpt_words : ''));

    // Persist the token -> context mapping as a non-autoloaded option rather
    // than a transient. Page caches can serve this button for days or weeks,
    // and a transient (or an object-cache eviction) would break it long before
    // the cached page is refreshed. The token is deterministic, so each feed
    // configuration writes its row once. The source and its revision let the
    // AJAX handler refuse the button once that source is edited, disabled or
    // deleted.
    $context = array(
        'endpoint'       => $endpoint,
        'count'          => $count,
        'excerpt_length' => $excerpt_length,
        'source'         => $source,
        'revision'       => $revision,
    );
    if (!$classic) {
        $context['layout'] = $layout;
    }
    if ($excerpt_words > 0) {
        $context['excerpt_words'] = $excerpt_words;
    }
    $option = 'rest_posts_embedder_lm_' . $token;
    if (get_option($option) !== $context) {
        update_option($option, $context, false);
    }

    return load_more_button_html($token, $total_pages, $locale);
}

/**
 * Markup of a Load More button.
 *
 * @since 3.10.0
 * @param string $token       Token of the stored context.
 * @param int    $total_pages Total pages available.
 * @param string $locale      Locale the page was rendered in ('' to omit).
 * @return string
 */
function load_more_button_html($token, $total_pages, $locale) {
    $label = __('Load More', 'restpostsembedder');

    return '<div class="embed-posts-load-more-wrap">'
        . '<button type="button" class="embed-posts-load-more"'
        . ' data-token="' . esc_attr($token) . '"'
        . ' data-page="1"'
        . ' data-total-pages="' . esc_attr($total_pages) . '"'
        . ('' !== $locale ? ' data-locale="' . esc_attr($locale) . '"' : '')
        . '>'
        . esc_html($label)
        . '</button>'
        . '</div>';
}

/**
 * Resolve the locale a Load More request should be rendered in.
 *
 * The button reports the locale of the page it sits on. An AJAX request has
 * no page URL, so on a multilingual site its own locale can differ from the
 * page's. The reported value comes from the browser: it is accepted only when
 * it is a language installed on this site, which also bounds the number of
 * cache entries a visitor can create.
 *
 * @since 3.9.0
 * @param string $requested Locale reported by the button ('' when absent).
 * @return string Locale to render in.
 */
function resolve_load_more_locale($requested) {
    $current = determine_locale();
    if ('' === $requested || $requested === $current) {
        return $current;
    }
    $installed = array_merge(array('en_US'), get_available_languages());
    return in_array($requested, $installed, true) ? $requested : $current;
}

/**
 * Run a callback with WordPress switched to a locale, then switch back.
 *
 * @since 3.9.0
 * @param string   $locale   Locale to render in.
 * @param callable $callback Callback to run.
 * @return mixed The callback's return value.
 */
function with_locale($locale, $callback) {
    $switched = ($locale !== determine_locale()) && switch_to_locale($locale);
    try {
        return $callback();
    } finally {
        if ($switched) {
            restore_previous_locale();
        }
    }
}

/**
 * AJAX handler for the Load More button.
 *
 * @since 3.7.0
 * @return void Outputs a JSON response and exits.
 */
function rest_posts_embedder_load_more() {
    // No nonce check. This is a public, read-only endpoint, and a nonce baked
    // into page-cached HTML expires within a day and silently breaks the
    // button. Requests are limited to feeds the server itself rendered, via the
    // token below.
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $page  = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 0;
    $locale = resolve_load_more_locale(isset($_POST['locale']) ? sanitize_text_field(wp_unslash($_POST['locale'])) : '');
    // phpcs:enable WordPress.Security.NonceVerification.Missing

    // Token is always a 32-char md5 hex string; reject anything else.
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || $page < 2) {
        wp_send_json_error(array(
            'message' => __('Invalid request.', 'restpostsembedder'),
        ));
    }

    $option  = 'rest_posts_embedder_lm_' . $token;
    $context = get_option($option);
    if (!is_array($context)) {
        // Buttons rendered by 3.7.0 stored their context in a transient.
        // Carry it over so pages cached before the update keep working.
        $context = get_transient('rpe_lm_' . $token);
        if (is_array($context) && !empty($context['endpoint'])) {
            update_option($option, $context, false);
        }
    }
    // Feeds of this site's own posts store their query instead of an endpoint.
    if (is_array($context) && isset($context['local']) && is_array($context['local'])) {
        wp_send_json_success(load_more_local($context, $page, $locale));
    }

    $expired = !is_array($context) || empty($context['endpoint']) || !is_string($context['endpoint']);
    if (!$expired && !is_load_more_context_current($context)) {
        // The feed behind this button was changed or retired. The context is
        // kept: the check runs on every request, and if the change is undone
        // the same button, still in cached HTML, must work again.
        $expired = true;
    }
    if ($expired) {
        wp_send_json_error(array(
            'message' => __('This feed has expired. Please refresh the page.', 'restpostsembedder'),
            'expired' => true,
        ));
    }

    $endpoint       = $context['endpoint'];
    $count          = absint($context['count']);
    $count          = ($count > 0 && $count <= REST_POSTS_EMBEDDER_MAX_COUNT) ? $count : REST_POSTS_EMBEDDER_DEFAULT_COUNT;
    $excerpt_length = isset($context['excerpt_length']) ? sanitize_excerpt_length($context['excerpt_length']) : 0;
    $excerpt_words  = isset($context['excerpt_words']) ? sanitize_excerpt_words($context['excerpt_words']) : 0;
    // Contexts without a layout were rendered as Classic. One whose layout was
    // unregistered since gets the site's default.
    $layout         = resolve_layout(isset($context['layout']) ? $context['layout'] : DEFAULT_LAYOUT);

    // A page past the known end of the feed needs no request. Without this,
    // anyone could make the site fetch (and log) one failing request per page
    // number. The button is removed when has_more is false.
    $end_of_feed = array('html' => '', 'has_more' => false);
    $page_bound  = get_transient(page_bound_key($endpoint, $count));
    if (false === $page_bound) {
        // Not known (expired with the feed's cache, cleared, or evicted).
        // Learn it from the remote's own page count before fetching any
        // page, so a request can never set it.
        $page_bound = learn_page_bound($endpoint, $count);
        if (false === $page_bound) {
            wp_send_json_error(array('message' => __('Could not load more posts. Please try again.', 'restpostsembedder')));
        }
    }
    if ($page > (int) $page_bound) {
        wp_send_json_success($end_of_feed);
    }

    // Cache each rendered page so repeated clicks don't re-hit the remote. The
    // HTML holds translated text, so the locale is part of the key.
    $page_cache_key = 'rest_posts_embedder_p_' . md5('v2_' . \RestPostsEmbedder\Admin\get_cache_generation() . '|' . $endpoint . '|' . $count . '|' . $excerpt_length . '|' . $page . '|' . $locale . '|' . $layout . '|' . $excerpt_words);
    $cached = get_transient($page_cache_key);
    if (false !== $cached) {
        wp_send_json_success($cached);
    }

    // Fetch and render in the page's locale, so error messages, dates and
    // "Read more..." match the posts already on the page.
    $result = with_locale($locale, function () use ($endpoint, $count, $page, $excerpt_length, $layout, $excerpt_words) {
        $fetch = fetch_posts_page($endpoint, $count, $page);
        // The feed shrank since its page count was stored: the remote answers
        // 400 (rest_post_invalid_page_number) or an empty list. That is the end
        // of the list, not an error. Store the remote's new count; never derive
        // it from the requested page number.
        if (400 === $fetch['status'] || (empty($fetch['error']) && empty($fetch['posts']))) {
            delete_transient(page_bound_key($endpoint, $count));
            learn_page_bound($endpoint, $count);
            // Cached briefly: a remote whose count disagrees with its pages
            // must not cost a request per click on this page.
            return array('payload' => array('html' => '', 'has_more' => false), 'cache' => 5 * MINUTE_IN_SECONDS);
        }
        if (!empty($fetch['error'])) {
            return array('error' => $fetch['error']);
        }
        remember_page_bound($endpoint, $count, $fetch['total_pages'], \RestPostsEmbedder\Admin\get_cache_expiration());
        return array(
            'payload' => array(
                'html'     => render_articles($fetch['posts'], $excerpt_length, $layout, $excerpt_words),
                'has_more' => ($fetch['total_pages'] > 0 && $page < $fetch['total_pages']),
            ),
            'cache'   => \RestPostsEmbedder\Admin\get_cache_expiration(),
        );
    });

    if (isset($result['error'])) {
        wp_send_json_error(array('message' => $result['error']));
    }

    set_transient($page_cache_key, $result['payload'], $result['cache']);

    wp_send_json_success($result['payload']);
}

/**
 * Build the CSS generated from the styling settings: grid columns, image
 * visibility per viewport and the site's custom CSS.
 *
 * @since 3.9.0
 * @return string CSS.
 */
function build_inline_css() {
    // Get settings
    $columns_desktop = absint(get_option('embed_posts_columns_desktop', 2));
    $columns_mobile = absint(get_option('embed_posts_columns_mobile', 1));
    $show_images_desktop = absint(get_option('embed_posts_show_images_desktop', 1));
    $show_images_mobile = absint(get_option('embed_posts_show_images_mobile', 1));

    // Layouts read these; a feed's columns attributes override them inline.
    $columns_desktop = max(1, $columns_desktop);
    $columns_mobile  = max(1, $columns_mobile);
    $columns_tablet  = min($columns_desktop, 2);

    $inline_css = "
    /* Column configuration for desktop */
    .embed-posts-wrapper {
        --rpe-cols: {$columns_desktop};
        --rpe-cols-tablet: {$columns_tablet};
        --rpe-cols-mobile: {$columns_mobile};
        grid-template-columns: repeat(var(--rpe-cols), 1fr);
    }

    /* Hide images on desktop if disabled. Scoped to desktop widths: an
       unqualified rule would also hide them on mobile. */
    " . (!$show_images_desktop ? "@media only screen and (min-width: 769px) { .embed-posts img, .embed-posts-card-image { display: none; } }" : "") . "

    /* Mobile styles */
    @media only screen and (max-width: 768px) {
        .embed-posts-wrapper {
            grid-template-columns: repeat(var(--rpe-cols-mobile), 1fr);
        }

        /* Hide images on mobile if disabled */
        " . (!$show_images_mobile ? ".embed-posts img, .embed-posts-card-image { display: none !important; }" : "") . "
    }
    ";

    // Add custom CSS if provided
    $custom_css = get_option('embed_posts_custom_css', '');
    if (!empty($custom_css)) {
        $inline_css .= "\n\n/* Custom CSS */\n" . $custom_css;
    }

    return $inline_css;
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
        'loadingText' => __('Loading…', 'restpostsembedder'),
        'errorText'   => __('Could not load more posts. Please try again.', 'restpostsembedder'),
    ) );

    wp_add_inline_style('display-posts-style', build_inline_css());
}
add_action( 'wp_enqueue_scripts', 'RestPostsEmbedder\\Shortcodes\\display_posts_enqueue_styles' );
