<?php

namespace RestPostsEmbedder\Admin;

// Add a settings page
function embed_posts_settings_page() {
    add_options_page(
        __('REST Posts Embedder Settings', 'restpostsembedder'),
        __('REST Posts Embedder', 'restpostsembedder'),
        'manage_options',
        'embed-posts-settings',
        'embed_posts_settings_page_html'
    );
}
add_action('admin_menu', 'embed_posts_settings_page');

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
                        <label for="embed_posts_endpoint"><?php _e('REST API Endpoint', 'restpostsembedder'); ?></label>
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
                            <?php _e('Enter the full REST API endpoint URL to fetch posts from.', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_count"><?php _e('Number of Posts', 'restpostsembedder'); ?></label>
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
                            <?php _e('How many posts to display (1-20).', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'restpostsembedder')); ?>
        </form>
    </div>
    <?php
}

// Register and sanitize settings
function embed_posts_settings_init() {
    register_setting('embed_posts_settings', 'embed_posts_endpoint', array(
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => 'https://prowoos.com/wp-json/wp/v2/posts?_embed'
    ));

    register_setting('embed_posts_settings', 'embed_posts_count', array(
        'type' => 'number',
        'sanitize_callback' => 'absint',
        'default' => 5
    ));
}
add_action('admin_init', 'embed_posts_settings_init');
