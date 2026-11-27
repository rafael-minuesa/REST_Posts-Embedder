<?php
/**
 * "Posts Grid" block for the block editor.
 *
 * A dynamic block: the server renders it through the same code as the
 * [posts_embedder] shortcode, so it has every layout, source and Load More.
 * The editor script is plain JavaScript with no build step.
 *
 * @package restpostsembedder
 * @since 3.10.0
 */

namespace RestPostsEmbedder\Blocks;

use RestPostsEmbedder\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}

const BLOCK_NAME = 'restpostsembedder/posts-grid';

/**
 * Attributes of the block. Optional numbers have no default: unset means the
 * source's or the site's setting, as for an absent shortcode attribute.
 *
 * @since 3.10.0
 * @return array
 */
function block_attributes() {
    return array(
        'source'        => array('type' => 'string', 'default' => Shortcodes\LOCAL_SOURCE_ID),
        'layout'        => array('type' => 'string', 'default' => 'grid'),
        'count'         => array('type' => 'number'),
        'columns'       => array('type' => 'number'),
        'columnsTablet' => array('type' => 'number'),
        'excerptUnit'   => array('type' => 'string', 'enum' => array('characters', 'words'), 'default' => 'words'),
        'excerptLength' => array('type' => 'number'),
        'category'      => array('type' => 'string', 'default' => ''),
        'tag'           => array('type' => 'string', 'default' => ''),
        'orderby'       => array('type' => 'string', 'default' => 'date'),
        'order'         => array('type' => 'string', 'default' => 'DESC'),
        'offset'        => array('type' => 'number', 'default' => 0),
        'align'         => array('type' => 'string'),
        'className'     => array('type' => 'string'),
    );
}

/**
 * Register the block type.
 *
 * @since 3.10.0
 * @return void
 */
function register_block() {
    if (!function_exists('register_block_type')) {
        return;
    }
    register_block_type(BLOCK_NAME, array(
        'api_version'     => 3,
        'attributes'      => block_attributes(),
        'render_callback' => __NAMESPACE__ . '\\render_block',
        'supports'        => array('align' => array('wide', 'full'), 'html' => false),
    ));
}
add_action('init', __NAMESPACE__ . '\\register_block');

/**
 * Shortcode attributes for a block's attributes.
 *
 * @since 3.10.0
 * @param array $attributes Block attributes.
 * @return array
 */
function shortcode_atts_for_block($attributes) {
    $get = function ($key, $default = '') use ($attributes) {
        return (isset($attributes[$key]) && is_scalar($attributes[$key])) ? $attributes[$key] : $default;
    };
    $number = function ($key) use ($get) {
        $value = $get($key, null);
        return (null === $value || '' === $value) ? '' : (string) (int) $value;
    };

    $atts = array(
        'source'         => (string) $get('source', Shortcodes\LOCAL_SOURCE_ID),
        'layout'         => (string) $get('layout'),
        'count'          => $number('count'),
        'columns'        => $number('columns'),
        'columns_tablet' => $number('columnsTablet'),
        'category'       => (string) $get('category'),
        'tag'            => (string) $get('tag'),
        'orderby'        => (string) $get('orderby'),
        'order'          => (string) $get('order'),
        'offset'         => $number('offset'),
    );
    $length = $number('excerptLength');
    if ('' !== $length) {
        $atts['words' === $get('excerptUnit', 'words') ? 'excerpt_words' : 'excerpt_length'] = $length;
    }
    // Leave unset values to the shortcode's own defaults.
    return array_filter($atts, function ($value) {
        return '' !== $value;
    });
}

/**
 * Render the block on the front end and in the editor preview.
 *
 * @since 3.10.0
 * @param array $attributes Block attributes.
 * @return string
 */
function render_block($attributes) {
    $html  = Shortcodes\rest_posts_embedder(shortcode_atts_for_block(is_array($attributes) ? $attributes : array()));
    $class = 'wp-block-restpostsembedder-posts-grid';
    // get_block_wrapper_attributes() (WordPress 5.6+) adds the block's own
    // class, alignment and custom class by itself.
    $wrapper = function_exists('get_block_wrapper_attributes')
        ? get_block_wrapper_attributes()
        : 'class="' . esc_attr($class . (empty($attributes['className']) ? '' : ' ' . $attributes['className'])) . '"';
    return '<div ' . $wrapper . '>' . $html . '</div>';
}

/**
 * Load the editor script, with the sources and layouts it offers.
 *
 * Registered here and not on init, so nothing touches the script registry
 * outside the editor.
 *
 * @since 3.10.0
 * @return void
 */
function enqueue_editor_assets() {
    $url = plugin_dir_url(__DIR__);
    wp_enqueue_script(
        'rest-posts-embedder-block',
        $url . 'assets/js/block-editor.js',
        array('wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-data'),
        REST_POSTS_EMBEDDER_VERSION,
        true
    );
    wp_add_inline_script('rest-posts-embedder-block', 'window.restPostsEmbedderBlock = ' . wp_json_encode(editor_data()) . ';', 'before');
    if (function_exists('wp_set_script_translations')) {
        wp_set_script_translations('rest-posts-embedder-block', 'restpostsembedder', dirname(__DIR__) . '/languages');
    }
}
add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets');

/**
 * Data the editor script needs.
 *
 * @since 3.10.0
 * @return array
 */
function editor_data() {
    $sources = array();
    foreach (\RestPostsEmbedder\get_sources() as $id => $source) {
        if (is_array($source) && !empty($source['enabled'])) {
            $sources[] = array('id' => (string) $id, 'name' => isset($source['name']) ? (string) $source['name'] : (string) $id);
        }
    }
    $layouts = array();
    foreach (Shortcodes\get_layouts() as $id => $layout) {
        $layouts[] = array('id' => $id, 'label' => $layout['label']);
    }
    return array(
        'name'        => BLOCK_NAME,
        'localSource' => Shortcodes\LOCAL_SOURCE_ID,
        'sources'     => $sources,
        'layouts'     => $layouts,
        'maxCount'    => REST_POSTS_EMBEDDER_MAX_COUNT,
        'maxColumns'  => REST_POSTS_EMBEDDER_MAX_COLUMNS,
        'maxOffset'   => Shortcodes\LOCAL_MAX_OFFSET,
        'settingsUrl' => current_user_can('manage_options') ? admin_url('options-general.php?page=embed-posts-settings&tab=sources') : '',
    );
}

/**
 * The plugin's styles inside the editor (the editor canvas may be an
 * iframe, which only gets styles enqueued on this hook).
 *
 * @since 3.10.0
 * @return void
 */
function enqueue_editor_styles() {
    if (!is_admin()) {
        return; // The front end loads them on wp_enqueue_scripts.
    }
    wp_enqueue_style('display-posts-style', plugin_dir_url(__DIR__) . 'assets/css/custom.css', array(), REST_POSTS_EMBEDDER_VERSION);
    wp_add_inline_style('display-posts-style', Shortcodes\build_inline_css());
}
add_action('enqueue_block_assets', __NAMESPACE__ . '\\enqueue_editor_styles');
