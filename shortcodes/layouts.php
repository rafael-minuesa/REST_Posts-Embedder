<?php
/**
 * Card layouts for REST Posts Embedder.
 *
 * A layout is a PHP template that turns one post's card data into HTML. The
 * plugin ships "Classic" and "Grid"; more are registered through the
 * rest_posts_embedder_layouts filter. Every feed resolves its layout from the
 * shortcode attribute, then its source, then the site setting.
 *
 * @package restpostsembedder
 * @since 3.10.0
 */

namespace RestPostsEmbedder\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Layout every feed falls back to. It cannot be unregistered.
 */
const DEFAULT_LAYOUT = 'classic';

/**
 * Registered layouts, keyed by ID.
 *
 * @since 3.10.0
 * @return array<string, array{label: string, template: string}>
 */
function get_layouts() {
    $builtin = array(
        DEFAULT_LAYOUT => array(
            'label'    => __('Classic', 'restpostsembedder'),
            'template' => dirname(__DIR__) . '/templates/layouts/classic.php',
        ),
        'grid' => array(
            'label'    => __('Grid', 'restpostsembedder'),
            'template' => dirname(__DIR__) . '/templates/layouts/grid.php',
        ),
    );

    /**
     * Filter the available card layouts.
     *
     * Each entry is keyed by a layout ID (lowercase letters, digits, "-" and
     * "_") and holds a "label" for the settings screens and the absolute path
     * of a "template" file. The template is included with one variable in
     * scope, $card, described in prepare_card(), and prints one post.
     *
     * @since 3.10.0
     * @param array $layouts Layouts keyed by ID.
     */
    $layouts = apply_filters('rest_posts_embedder_layouts', $builtin);

    $valid = array();
    if (is_array($layouts)) {
        foreach ($layouts as $id => $layout) {
            if (
                !is_string($id) || sanitize_key($id) !== $id || '' === $id
                || !is_array($layout) || empty($layout['template']) || !is_string($layout['template'])
                || !is_readable($layout['template'])
            ) {
                continue;
            }
            $valid[$id] = array(
                'label'    => (isset($layout['label']) && is_string($layout['label']) && '' !== $layout['label']) ? $layout['label'] : $id,
                'template' => $layout['template'],
            );
        }
    }
    // Every stored setting and cached button may name it.
    if (!isset($valid[DEFAULT_LAYOUT])) {
        $valid = array(DEFAULT_LAYOUT => $builtin[DEFAULT_LAYOUT]) + $valid;
    }
    return $valid;
}

/**
 * A layout ID if it is registered, otherwise ''.
 *
 * @since 3.10.0
 * @param mixed $value Requested layout.
 * @return string
 */
function sanitize_layout($value) {
    if (!is_string($value) || '' === $value) {
        return '';
    }
    $id = sanitize_key($value);
    return array_key_exists($id, get_layouts()) ? $id : '';
}

/**
 * The layout the site uses when a feed asks for none.
 *
 * @since 3.10.0
 * @return string
 */
function get_default_layout() {
    $layout = sanitize_layout(get_option('embed_posts_layout', DEFAULT_LAYOUT));
    return '' !== $layout ? $layout : DEFAULT_LAYOUT;
}

/**
 * Pick the layout of a feed. The first registered one wins, in the order
 * given; a layout that is no longer registered is skipped, so removing one
 * never breaks a page.
 *
 * @since 3.10.0
 * @param mixed ...$candidates Requested layouts, most specific first.
 * @return string
 */
function resolve_layout(...$candidates) {
    foreach ($candidates as $candidate) {
        $layout = sanitize_layout($candidate);
        if ('' !== $layout) {
            return $layout;
        }
    }
    return get_default_layout();
}

/**
 * Render one card with a layout's template.
 *
 * @since 3.10.0
 * @param array  $card   Card data from prepare_card().
 * @param string $layout Layout ID.
 * @return string
 */
function render_card($card, $layout) {
    $layouts  = get_layouts();
    $template = isset($layouts[$layout]) ? $layouts[$layout]['template'] : $layouts[DEFAULT_LAYOUT]['template'];

    ob_start();
    try {
        include_layout_template($template, $card);
    } finally {
        $html = ob_get_clean();
    }
    return (string) $html;
}

/**
 * Include a template with nothing but $card in its scope.
 *
 * @since 3.10.0
 * @param string $template Template path.
 * @param array  $card     Card data.
 * @return void
 */
function include_layout_template($template, $card) {
    include $template;
}
