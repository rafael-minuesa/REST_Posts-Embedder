<?php
/**
 * Admin functions for REST Posts Embedder.
 *
 * @package restpostsembedder
 * @since 1.0.0
 */

namespace RestPostsEmbedder\Admin;

/**
 * Register the settings page in WordPress admin menu.
 *
 * @since 1.0.0
 * @return void
 */
function embed_posts_settings_page() {
    add_options_page(
        __('REST Posts Embedder Settings', 'restpostsembedder'),
        __('REST Posts Embedder', 'restpostsembedder'),
        'manage_options',
        'embed-posts-settings',
        'RestPostsEmbedder\\Admin\\embed_posts_settings_page_html'
    );
}
add_action('admin_menu', 'RestPostsEmbedder\\Admin\\embed_posts_settings_page');

/**
 * Handle cache clearing action.
 *
 * @since 2.9.0
 * @return void
 */
function handle_cache_clear() {
    if (!isset($_POST['clear_cache_nonce']) || !wp_verify_nonce($_POST['clear_cache_nonce'], 'clear_rest_embedder_cache')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_rest_posts_embedder_') . '%',
            $wpdb->esc_like('_transient_timeout_rest_posts_embedder_') . '%'
        )
    );

    add_settings_error(
        'rest_posts_embedder_messages',
        'cache_cleared',
        sprintf(__('Cache cleared successfully! %d items removed.', 'restpostsembedder'), $deleted),
        'success'
    );
}

/**
 * Render the settings page HTML.
 *
 * @since 1.0.0
 * @return void
 */
function embed_posts_settings_page_html() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle cache clear request
    if (isset($_POST['clear_cache'])) {
        handle_cache_clear();
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php settings_errors('rest_posts_embedder_messages'); ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('embed_posts_settings');
            do_settings_sections('embed_posts_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_endpoint"><?php esc_html_e('REST API Endpoint', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="embed_posts_endpoint"
                               name="embed_posts_endpoint"
                               value="<?php echo esc_attr(get_option('embed_posts_endpoint')); ?>"
                               class="regular-text"
                               placeholder="https://example.com/wp-json/wp/v2/posts?_embed"
                        />
                        <p class="description">
                            <?php esc_html_e('Enter the full REST API endpoint URL to fetch posts from.', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_count"><?php esc_html_e('Number of Posts', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="embed_posts_count"
                               name="embed_posts_count"
                               value="<?php echo esc_attr(get_option('embed_posts_count', 5)); ?>"
                               min="1"
                               max="20"
                               class="small-text"
                        />
                        <p class="description">
                            <?php esc_html_e('How many posts to display (1-20).', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Save Settings', 'restpostsembedder')); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Cache Management', 'restpostsembedder'); ?></h2>
        <p><?php esc_html_e('Clear all cached REST API responses. This will force the plugin to fetch fresh data from the API on the next request.', 'restpostsembedder'); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field('clear_rest_embedder_cache', 'clear_cache_nonce'); ?>
            <input type="submit" name="clear_cache" class="button button-secondary" value="<?php esc_attr_e('Clear Cache', 'restpostsembedder'); ?>">
        </form>
    </div>
    <?php
}

/**
 * Register plugin settings and their sanitization callbacks.
 *
 * @since 1.0.0
 * @return void
 */
function embed_posts_settings_init() {
    register_setting('embed_posts_settings', 'embed_posts_endpoint', array(
        'type' => 'string',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_endpoint_url',
        'default' => REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT
    ));

    register_setting('embed_posts_settings', 'embed_posts_count', array(
        'type' => 'number',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_posts_count',
        'default' => REST_POSTS_EMBEDDER_DEFAULT_COUNT
    ));
}
add_action('admin_init', 'RestPostsEmbedder\\Admin\\embed_posts_settings_init');

/**
 * Sanitize and validate the REST API endpoint URL.
 *
 * @since 2.8.1
 * @param string $value The endpoint URL to sanitize.
 * @return string The sanitized URL or default value if invalid.
 */
function sanitize_endpoint_url($value) {
    $url = esc_url_raw($value);

    if (empty($url)) {
        add_settings_error(
            'embed_posts_endpoint',
            'invalid_url',
            __('Error: Please enter a valid URL for the REST API endpoint.', 'restpostsembedder'),
            'error'
        );
        return get_option('embed_posts_endpoint', REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT);
    }

    // Check if URL contains wp-json (WordPress REST API indicator)
    if (strpos($url, 'wp-json') === false) {
        add_settings_error(
            'embed_posts_endpoint',
            'not_rest_api',
            __('Warning: The URL does not appear to be a WordPress REST API endpoint. Make sure it contains "wp-json" and returns valid JSON data.', 'restpostsembedder'),
            'warning'
        );
    }

    return $url;
}

/**
 * Sanitize and validate the post count setting.
 *
 * @since 2.8.0
 * @param int $value The post count to validate.
 * @return int The validated post count or default value if invalid.
 */
function sanitize_posts_count($value) {
    $count = absint($value);
    if ($count < REST_POSTS_EMBEDDER_MIN_COUNT || $count > REST_POSTS_EMBEDDER_MAX_COUNT) {
        add_settings_error(
            'embed_posts_count',
            'invalid_count',
            sprintf(
                __('Error: Number of posts must be between %d and %d. Value has been reset to default.', 'restpostsembedder'),
                REST_POSTS_EMBEDDER_MIN_COUNT,
                REST_POSTS_EMBEDDER_MAX_COUNT
            ),
            'error'
        );
        return get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT);
    }
    return $count;
}
