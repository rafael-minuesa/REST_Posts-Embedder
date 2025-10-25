<?php

namespace RestPostsEmbedder\Admin;

// Add a settings page
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

// The HTML for the settings page
function embed_posts_settings_page_html() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
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
    </div>
    <?php
}

// Register and sanitize settings
function embed_posts_settings_init() {
    register_setting('embed_posts_settings', 'embed_posts_endpoint', array(
        'type' => 'string',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_endpoint_url',
        'default' => 'https://prowoos.com/wp-json/wp/v2/posts?_embed'
    ));

    register_setting('embed_posts_settings', 'embed_posts_count', array(
        'type' => 'number',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_posts_count',
        'default' => 5
    ));
}
add_action('admin_init', 'RestPostsEmbedder\\Admin\\embed_posts_settings_init');

// Server-side validation for endpoint URL
function sanitize_endpoint_url($value) {
    $url = esc_url_raw($value);

    if (empty($url)) {
        add_settings_error(
            'embed_posts_endpoint',
            'invalid_url',
            __('Please enter a valid URL.', 'restpostsembedder'),
            'error'
        );
        return get_option('embed_posts_endpoint', 'https://prowoos.com/wp-json/wp/v2/posts?_embed');
    }

    // Check if URL contains wp-json (WordPress REST API indicator)
    if (strpos($url, 'wp-json') === false) {
        add_settings_error(
            'embed_posts_endpoint',
            'not_rest_api',
            __('Warning: URL does not appear to be a WordPress REST API endpoint (missing "wp-json").', 'restpostsembedder'),
            'warning'
        );
    }

    return $url;
}

// Server-side validation for post count
function sanitize_posts_count($value) {
    $count = absint($value);
    if ($count < 1 || $count > 20) {
        add_settings_error(
            'embed_posts_count',
            'invalid_count',
            __('Number of posts must be between 1 and 20.', 'restpostsembedder'),
            'error'
        );
        return get_option('embed_posts_count', 5);
    }
    return $count;
}
