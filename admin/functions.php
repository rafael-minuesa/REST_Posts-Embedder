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
 * Handle source management actions (add, edit, delete).
 *
 * @since 3.0.0
 * @return void
 */
function handle_source_actions() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $sources = get_option('rest_posts_embedder_sources', array());

    // Handle delete source
    if (isset($_POST['delete_source']) && isset($_POST['source_id'])) {
        if (!wp_verify_nonce($_POST['source_nonce'], 'manage_rest_embedder_source')) {
            return;
        }

        $source_id = sanitize_key($_POST['source_id']);
        if (isset($sources[$source_id])) {
            unset($sources[$source_id]);
            update_option('rest_posts_embedder_sources', $sources);
            add_settings_error(
                'rest_posts_embedder_messages',
                'source_deleted',
                __('Source deleted successfully!', 'restpostsembedder'),
                'success'
            );
        }
    }

    // Handle add/edit source
    if (isset($_POST['save_source'])) {
        if (!wp_verify_nonce($_POST['source_nonce'], 'manage_rest_embedder_source')) {
            return;
        }

        $source_id = isset($_POST['source_id']) ? sanitize_key($_POST['source_id']) : '';
        $source_name = isset($_POST['source_name']) ? sanitize_text_field($_POST['source_name']) : '';
        $source_endpoint = isset($_POST['source_endpoint']) ? esc_url_raw($_POST['source_endpoint']) : '';
        $source_count = isset($_POST['source_count']) ? absint($_POST['source_count']) : REST_POSTS_EMBEDDER_DEFAULT_COUNT;
        $source_enabled = isset($_POST['source_enabled']) ? true : false;

        // Validate
        if (empty($source_name) || empty($source_endpoint)) {
            add_settings_error(
                'rest_posts_embedder_messages',
                'invalid_source',
                __('Error: Source name and endpoint are required.', 'restpostsembedder'),
                'error'
            );
            return;
        }

        // Generate ID if new
        if (empty($source_id)) {
            $source_id = sanitize_title($source_name) . '_' . time();
        }

        $sources[$source_id] = array(
            'id' => $source_id,
            'name' => $source_name,
            'endpoint' => $source_endpoint,
            'count' => $source_count,
            'enabled' => $source_enabled
        );

        update_option('rest_posts_embedder_sources', $sources);
        add_settings_error(
            'rest_posts_embedder_messages',
            'source_saved',
            __('Source saved successfully!', 'restpostsembedder'),
            'success'
        );
    }
}

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

    // Handle actions
    handle_source_actions();
    if (isset($_POST['clear_cache'])) {
        handle_cache_clear();
    }

    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'sources';
    $edit_source = isset($_GET['edit']) ? sanitize_key($_GET['edit']) : '';

    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

        <?php settings_errors('rest_posts_embedder_messages'); ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=embed-posts-settings&tab=sources" class="nav-tab <?php echo $current_tab === 'sources' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Feed Sources', 'restpostsembedder'); ?>
            </a>
            <a href="?page=embed-posts-settings&tab=styling" class="nav-tab <?php echo $current_tab === 'styling' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Styling', 'restpostsembedder'); ?>
            </a>
            <a href="?page=embed-posts-settings&tab=cache" class="nav-tab <?php echo $current_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Cache Management', 'restpostsembedder'); ?>
            </a>
        </h2>

        <?php
        if ($current_tab === 'sources') {
            if ($edit_source === 'new' || !empty($edit_source)) {
                render_source_form($edit_source);
            } else {
                render_sources_list();
            }
        } elseif ($current_tab === 'styling') {
            render_styling_settings();
        } elseif ($current_tab === 'cache') {
            render_cache_management();
        }
        ?>
    </div>
    <?php
}

/**
 * Render the list of feed sources.
 *
 * @since 3.0.0
 * @return void
 */
function render_sources_list() {
    $sources = get_option('rest_posts_embedder_sources', array());
    ?>
    <div class="wrap">
        <h2><?php esc_html_e('Manage Feed Sources', 'restpostsembedder'); ?>
            <a href="?page=embed-posts-settings&tab=sources&edit=new" class="page-title-action">
                <?php esc_html_e('Add New Source', 'restpostsembedder'); ?>
            </a>
        </h2>

        <div class="notice notice-info" style="padding: 15px;">
            <h3><?php esc_html_e('How to Get Your REST API Endpoint URL', 'restpostsembedder'); ?></h3>
            <p><?php esc_html_e('To get posts from any WordPress site, use this format:', 'restpostsembedder'); ?></p>
            <p><strong><?php esc_html_e('Basic endpoint:', 'restpostsembedder'); ?></strong></p>
            <p><code>https://example.com/wp-json/wp/v2/posts?_embed</code></p>

            <p><strong><?php esc_html_e('Filter by categories:', 'restpostsembedder'); ?></strong></p>
            <p><code>https://example.com/wp-json/wp/v2/posts?categories=111&_embed</code></p>
            <p class="description"><?php esc_html_e('Replace "111" with your category ID. To find category IDs, go to Posts → Categories in the WordPress admin.', 'restpostsembedder'); ?></p>

            <p><strong><?php esc_html_e('Filter by tags:', 'restpostsembedder'); ?></strong></p>
            <p><code>https://example.com/wp-json/wp/v2/posts?tags=222&_embed</code></p>
            <p class="description"><?php esc_html_e('Replace "222" with your tag ID. To find tag IDs, go to Posts → Tags in the WordPress admin.', 'restpostsembedder'); ?></p>

            <p><strong><?php esc_html_e('Combine multiple filters:', 'restpostsembedder'); ?></strong></p>
            <p><code>https://example.com/wp-json/wp/v2/posts?categories=111&tags=222&_embed</code></p>
            <p class="description"><?php esc_html_e('You can combine categories, tags, and other WordPress REST API parameters.', 'restpostsembedder'); ?></p>

            <p><strong><?php esc_html_e('Note:', 'restpostsembedder'); ?></strong>
               <?php esc_html_e('The ?_embed parameter includes featured images and author information. Always include it for best results.', 'restpostsembedder'); ?></p>
        </div>

        <p><?php esc_html_e('Configure multiple REST API feed sources. Use the source ID in your shortcode to display posts from different sources.', 'restpostsembedder'); ?></p>
        <p><strong><?php esc_html_e('Example:', 'restpostsembedder'); ?></strong> <code>[posts_embedder source="source-id"]</code></p>

        <?php if (empty($sources)) : ?>
            <p><?php esc_html_e('No sources configured yet. Click "Add New Source" to create your first feed source.', 'restpostsembedder'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'restpostsembedder'); ?></th>
                        <th><?php esc_html_e('Source ID', 'restpostsembedder'); ?></th>
                        <th><?php esc_html_e('Endpoint', 'restpostsembedder'); ?></th>
                        <th><?php esc_html_e('Post Count', 'restpostsembedder'); ?></th>
                        <th><?php esc_html_e('Status', 'restpostsembedder'); ?></th>
                        <th><?php esc_html_e('Actions', 'restpostsembedder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sources as $source) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($source['name']); ?></strong></td>
                            <td><code><?php echo esc_html($source['id']); ?></code></td>
                            <td><?php echo esc_html(strlen($source['endpoint']) > 50 ? substr($source['endpoint'], 0, 50) . '...' : $source['endpoint']); ?></td>
                            <td><?php echo esc_html($source['count']); ?></td>
                            <td>
                                <?php if ($source['enabled']) : ?>
                                    <span style="color: green;">●</span> <?php esc_html_e('Enabled', 'restpostsembedder'); ?>
                                <?php else : ?>
                                    <span style="color: red;">●</span> <?php esc_html_e('Disabled', 'restpostsembedder'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?page=embed-posts-settings&tab=sources&edit=<?php echo esc_attr($source['id']); ?>" class="button button-small">
                                    <?php esc_html_e('Edit', 'restpostsembedder'); ?>
                                </a>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('manage_rest_embedder_source', 'source_nonce'); ?>
                                    <input type="hidden" name="source_id" value="<?php echo esc_attr($source['id']); ?>">
                                    <button type="submit" name="delete_source" class="button button-small button-link-delete"
                                            onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this source?', 'restpostsembedder'); ?>');">
                                        <?php esc_html_e('Delete', 'restpostsembedder'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render the source add/edit form.
 *
 * @since 3.0.0
 * @param string $source_id The source ID to edit, or 'new' for a new source.
 * @return void
 */
function render_source_form($source_id) {
    $sources = get_option('rest_posts_embedder_sources', array());
    $is_new = ($source_id === 'new');
    $source = $is_new ? array(
        'id' => '',
        'name' => '',
        'endpoint' => '',
        'count' => REST_POSTS_EMBEDDER_DEFAULT_COUNT,
        'enabled' => true
    ) : (isset($sources[$source_id]) ? $sources[$source_id] : null);

    if (!$source && !$is_new) {
        echo '<p>' . esc_html__('Source not found.', 'restpostsembedder') . '</p>';
        return;
    }
    ?>
    <div class="wrap">
        <h2>
            <a href="?page=embed-posts-settings&tab=sources">&larr; <?php esc_html_e('Back to Sources', 'restpostsembedder'); ?></a>
        </h2>
        <h2><?php echo $is_new ? esc_html__('Add New Source', 'restpostsembedder') : esc_html__('Edit Source', 'restpostsembedder'); ?></h2>

        <form method="post" action="?page=embed-posts-settings&tab=sources">
            <?php wp_nonce_field('manage_rest_embedder_source', 'source_nonce'); ?>
            <?php if (!$is_new) : ?>
                <input type="hidden" name="source_id" value="<?php echo esc_attr($source['id']); ?>">
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="source_name"><?php esc_html_e('Source Name', 'restpostsembedder'); ?> <span style="color: red;">*</span></label>
                    </th>
                    <td>
                        <input type="text" id="source_name" name="source_name" value="<?php echo esc_attr($source['name']); ?>"
                               class="regular-text" required>
                        <p class="description"><?php esc_html_e('A friendly name for this feed source.', 'restpostsembedder'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="source_endpoint"><?php esc_html_e('REST API Endpoint', 'restpostsembedder'); ?> <span style="color: red;">*</span></label>
                    </th>
                    <td>
                        <input type="url" id="source_endpoint" name="source_endpoint" value="<?php echo esc_attr($source['endpoint']); ?>"
                               class="large-text" required>
                        <p class="description"><?php esc_html_e('Full REST API endpoint URL (e.g., https://example.com/wp-json/wp/v2/posts?_embed)', 'restpostsembedder'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="source_count"><?php esc_html_e('Number of Posts', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="source_count" name="source_count" value="<?php echo esc_attr($source['count']); ?>"
                               min="<?php echo REST_POSTS_EMBEDDER_MIN_COUNT; ?>"
                               max="<?php echo REST_POSTS_EMBEDDER_MAX_COUNT; ?>"
                               class="small-text">
                        <p class="description">
                            <?php printf(
                                esc_html__('Default number of posts to display (%d-%d).', 'restpostsembedder'),
                                REST_POSTS_EMBEDDER_MIN_COUNT,
                                REST_POSTS_EMBEDDER_MAX_COUNT
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="source_enabled"><?php esc_html_e('Status', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="source_enabled" name="source_enabled" value="1"
                                   <?php checked($source['enabled'], true); ?>>
                            <?php esc_html_e('Enable this source', 'restpostsembedder'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Disabled sources cannot be used in shortcodes.', 'restpostsembedder'); ?></p>
                    </td>
                </tr>
            </table>

            <?php if (!$is_new) : ?>
                <p><strong><?php esc_html_e('Shortcode:', 'restpostsembedder'); ?></strong>
                   <code>[posts_embedder source="<?php echo esc_attr($source['id']); ?>"]</code></p>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" name="save_source" class="button button-primary">
                    <?php echo $is_new ? esc_html__('Add Source', 'restpostsembedder') : esc_html__('Update Source', 'restpostsembedder'); ?>
                </button>
                <a href="?page=embed-posts-settings&tab=sources" class="button">
                    <?php esc_html_e('Cancel', 'restpostsembedder'); ?>
                </a>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Render the styling settings.
 *
 * @since 3.0.1
 * @return void
 */
function render_styling_settings() {
    ?>
    <div class="wrap">
        <h2><?php esc_html_e('Styling Settings', 'restpostsembedder'); ?></h2>
        <p><?php esc_html_e('Configure the display and appearance of embedded posts.', 'restpostsembedder'); ?></p>

        <form method="post" action="options.php">
            <?php
            settings_fields('embed_posts_styling');
            do_settings_sections('embed_posts_styling');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_count"><?php esc_html_e('Number of Posts', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="embed_posts_count"
                               name="embed_posts_count"
                               value="<?php echo esc_attr(get_option('embed_posts_count', REST_POSTS_EMBEDDER_DEFAULT_COUNT)); ?>"
                               min="<?php echo REST_POSTS_EMBEDDER_MIN_COUNT; ?>"
                               max="<?php echo REST_POSTS_EMBEDDER_MAX_COUNT; ?>"
                               class="small-text"
                        />
                        <p class="description">
                            <?php printf(
                                esc_html__('Default number of posts to display (%d-%d). Can be overridden in shortcode.', 'restpostsembedder'),
                                REST_POSTS_EMBEDDER_MIN_COUNT,
                                REST_POSTS_EMBEDDER_MAX_COUNT
                            ); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_columns_desktop"><?php esc_html_e('Number of Columns (Desktop)', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="embed_posts_columns_desktop"
                               name="embed_posts_columns_desktop"
                               value="<?php echo esc_attr(get_option('embed_posts_columns_desktop', 2)); ?>"
                               min="1"
                               max="5"
                               class="small-text"
                        />
                        <p class="description">
                            <?php esc_html_e('Number of columns to display on desktop (1-5). Default: 2', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_columns_mobile"><?php esc_html_e('Number of Columns (Mobile)', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="embed_posts_columns_mobile"
                               name="embed_posts_columns_mobile"
                               value="<?php echo esc_attr(get_option('embed_posts_columns_mobile', 1)); ?>"
                               min="1"
                               max="3"
                               class="small-text"
                        />
                        <p class="description">
                            <?php esc_html_e('Number of columns to display on mobile devices (1-3). Default: 1', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_show_images"><?php esc_html_e('Show Featured Images', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" id="embed_posts_show_images_desktop" name="embed_posts_show_images_desktop" value="1"
                                   <?php checked(get_option('embed_posts_show_images_desktop', 1), 1); ?>>
                            <?php esc_html_e('Show on Desktop', 'restpostsembedder'); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" id="embed_posts_show_images_mobile" name="embed_posts_show_images_mobile" value="1"
                                   <?php checked(get_option('embed_posts_show_images_mobile', 1), 1); ?>>
                            <?php esc_html_e('Show on Mobile', 'restpostsembedder'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Control whether featured images are displayed on different devices.', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_custom_css"><?php esc_html_e('Custom CSS', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <textarea id="embed_posts_custom_css"
                                  name="embed_posts_custom_css"
                                  rows="10"
                                  class="large-text code"><?php echo esc_textarea(get_option('embed_posts_custom_css', '')); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Add your custom CSS here. It will be applied to all embedded posts. Do not include <style> tags.', 'restpostsembedder'); ?>
                        </p>
                        <p class="description">
                            <strong><?php esc_html_e('Example:', 'restpostsembedder'); ?></strong><br>
                            <code>.embed-posts { border: 2px solid #000; }<br>
                            .embed-posts h3 { color: #ff0000; }</code>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(esc_html__('Save Styling Settings', 'restpostsembedder')); ?>
        </form>
    </div>
    <?php
}

/**
 * Render the cache management tab.
 *
 * @since 3.0.0
 * @return void
 */
function render_cache_management() {
    ?>
    <div class="wrap">
        <h2><?php esc_html_e('Cache Management', 'restpostsembedder'); ?></h2>

        <h3><?php esc_html_e('Clear Cache', 'restpostsembedder'); ?></h3>
        <p><?php esc_html_e('Clear all cached REST API responses. This will force the plugin to fetch fresh data from the API on the next request.', 'restpostsembedder'); ?></p>
        <form method="post" action="?page=embed-posts-settings&tab=cache">
            <?php wp_nonce_field('clear_rest_embedder_cache', 'clear_cache_nonce'); ?>
            <p class="submit">
                <input type="submit" name="clear_cache" class="button button-secondary" value="<?php esc_attr_e('Clear All Cache Now', 'restpostsembedder'); ?>">
            </p>
        </form>

        <hr>

        <h3><?php esc_html_e('Cache Expiration Settings', 'restpostsembedder'); ?></h3>
        <p><?php esc_html_e('Configure how long cached data should be stored before automatically refreshing.', 'restpostsembedder'); ?></p>
        <form method="post" action="options.php">
            <?php
            settings_fields('embed_posts_cache');
            do_settings_sections('embed_posts_cache');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="embed_posts_cache_expiration"><?php esc_html_e('Cache Expiration', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <?php $cache_expiration = get_option('embed_posts_cache_expiration', 'hour'); ?>
                        <select id="embed_posts_cache_expiration" name="embed_posts_cache_expiration" class="regular-text">
                            <option value="hour" <?php selected($cache_expiration, 'hour'); ?>><?php esc_html_e('1 Hour', 'restpostsembedder'); ?></option>
                            <option value="3hours" <?php selected($cache_expiration, '3hours'); ?>><?php esc_html_e('3 Hours', 'restpostsembedder'); ?></option>
                            <option value="6hours" <?php selected($cache_expiration, '6hours'); ?>><?php esc_html_e('6 Hours', 'restpostsembedder'); ?></option>
                            <option value="12hours" <?php selected($cache_expiration, '12hours'); ?>><?php esc_html_e('12 Hours', 'restpostsembedder'); ?></option>
                            <option value="day" <?php selected($cache_expiration, 'day'); ?>><?php esc_html_e('24 Hours (1 Day)', 'restpostsembedder'); ?></option>
                            <option value="custom" <?php selected($cache_expiration, 'custom'); ?>><?php esc_html_e('Custom', 'restpostsembedder'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('How often the cache should expire and fetch fresh data from the REST API.', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" id="custom_cache_hours_row" style="display: <?php echo ($cache_expiration === 'custom') ? 'table-row' : 'none'; ?>;">
                    <th scope="row">
                        <label for="embed_posts_cache_custom_hours"><?php esc_html_e('Custom Hours', 'restpostsembedder'); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="embed_posts_cache_custom_hours"
                               name="embed_posts_cache_custom_hours"
                               value="<?php echo esc_attr(get_option('embed_posts_cache_custom_hours', 1)); ?>"
                               min="1"
                               max="168"
                               class="small-text"
                        /> <?php esc_html_e('hours', 'restpostsembedder'); ?>
                        <p class="description">
                            <?php esc_html_e('Enter a custom number of hours (1-168). Max: 1 week.', 'restpostsembedder'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <script type="text/javascript">
                document.getElementById('embed_posts_cache_expiration').addEventListener('change', function() {
                    var customRow = document.getElementById('custom_cache_hours_row');
                    customRow.style.display = (this.value === 'custom') ? 'table-row' : 'none';
                });
            </script>

            <?php submit_button(esc_html__('Save Cache Settings', 'restpostsembedder')); ?>
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
 * Register styling settings.
 *
 * @since 3.0.1
 * @return void
 */
function embed_posts_styling_init() {
    register_setting('embed_posts_styling', 'embed_posts_count', array(
        'type' => 'number',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_posts_count',
        'default' => REST_POSTS_EMBEDDER_DEFAULT_COUNT
    ));

    register_setting('embed_posts_styling', 'embed_posts_columns_desktop', array(
        'type' => 'number',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_columns_desktop',
        'default' => 2
    ));

    register_setting('embed_posts_styling', 'embed_posts_columns_mobile', array(
        'type' => 'number',
        'sanitize_callback' => 'RestPostsEmbedder\\Admin\\sanitize_columns_mobile',
        'default' => 1
    ));

    register_setting('embed_posts_styling', 'embed_posts_show_images_desktop', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 1
    ));

    register_setting('embed_posts_styling', 'embed_posts_show_images_mobile', array(
        'type' => 'boolean',
        'sanitize_callback' => 'absint',
        'default' => 1
    ));

    register_setting('embed_posts_styling', 'embed_posts_custom_css', array(
        'type' => 'string',
        'sanitize_callback' => 'wp_strip_all_tags',
        'default' => ''
    ));
}
add_action('admin_init', 'RestPostsEmbedder\\Admin\\embed_posts_styling_init');

/**
 * Register cache settings.
 *
 * @since 3.0.1
 * @return void
 */
function embed_posts_cache_init() {
    register_setting('embed_posts_cache', 'embed_posts_cache_expiration', array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'hour'
    ));

    register_setting('embed_posts_cache', 'embed_posts_cache_custom_hours', array(
        'type' => 'number',
        'sanitize_callback' => 'absint',
        'default' => 1
    ));
}
add_action('admin_init', 'RestPostsEmbedder\\Admin\\embed_posts_cache_init');

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

/**
 * Sanitize and validate the desktop columns setting.
 *
 * @since 3.0.1
 * @param int $value The column count to validate.
 * @return int The validated column count or default value if invalid.
 */
function sanitize_columns_desktop($value) {
    $columns = absint($value);
    if ($columns < 1 || $columns > 5) {
        return 2; // Default
    }
    return $columns;
}

/**
 * Sanitize and validate the mobile columns setting.
 *
 * @since 3.0.1
 * @param int $value The column count to validate.
 * @return int The validated column count or default value if invalid.
 */
function sanitize_columns_mobile($value) {
    $columns = absint($value);
    if ($columns < 1 || $columns > 3) {
        return 1; // Default
    }
    return $columns;
}

/**
 * Get cache expiration time in seconds based on settings.
 *
 * @since 3.0.1
 * @return int Cache expiration time in seconds.
 */
function get_cache_expiration() {
    $expiration_setting = get_option('embed_posts_cache_expiration', 'hour');

    switch ($expiration_setting) {
        case '3hours':
            return 3 * HOUR_IN_SECONDS;
        case '6hours':
            return 6 * HOUR_IN_SECONDS;
        case '12hours':
            return 12 * HOUR_IN_SECONDS;
        case 'day':
            return DAY_IN_SECONDS;
        case 'custom':
            $custom_hours = absint(get_option('embed_posts_cache_custom_hours', 1));
            return $custom_hours * HOUR_IN_SECONDS;
        default: // 'hour'
            return HOUR_IN_SECONDS;
    }
}
