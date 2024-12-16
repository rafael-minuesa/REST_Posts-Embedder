<?php

namespace RestPostsEmbedder\Admin;

// Add a settings page
function embed_posts_settings_page() {
    add_options_page(
        'REST Posts Embedder Settings',
        'REST Posts Embedder',
        'manage_options',
        'embed-posts-settings',
        'embed_posts_settings_page_html'
    );
}
add_action('admin_menu', 'embed_posts_settings_page');

// The HTML for the settings page
function embed_posts_settings_page_html() {
    ?>
    <div class="wrap">
        <h1>REST Posts Embedder Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('embed_posts_settings');
            do_settings_sections('embed_posts_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">REST API Endpoint</th>
                    <td><input type="text" name="embed_posts_endpoint" value="<?php echo esc_attr(get_option('embed_posts_endpoint')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register the setting
function embed_posts_settings_init() {
    register_setting('embed_posts_settings', 'embed_posts_endpoint');
}
add_action('admin_init', 'embed_posts_settings_init');
