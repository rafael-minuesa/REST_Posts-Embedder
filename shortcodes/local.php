<?php
/**
 * "This site" feeds: posts of the site itself, read with WP_Query.
 *
 * [posts_embedder source="this-site"] renders this site's published posts
 * with the same layouts and Load More button as a remote feed. They are not
 * cached: a query is cheap, and a newly published post shows at once.
 *
 * @package restpostsembedder
 * @since 3.10.0
 */

namespace RestPostsEmbedder\Shortcodes;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Source ID of the built-in "This site" feed. Generated source IDs always
 * end in "_<timestamp>", so no stored source can be given it.
 */
const LOCAL_SOURCE_ID = 'this-site';

/**
 * Ways a local feed may be ordered (shortcode value => WP_Query orderby).
 * Random order is left out: it would repeat posts across Load More pages.
 */
const LOCAL_ORDERBY = array(
    'date'          => 'date',
    'modified'      => 'modified',
    'title'         => 'title',
    'comment_count' => 'comment_count',
);

/** Largest number of posts a local feed may skip. */
const LOCAL_MAX_OFFSET = 100;

/**
 * Whether a source attribute asks for the "This site" feed.
 *
 * @since 3.10.0
 * @param mixed $source Source attribute.
 * @return bool
 */
function is_local_source($source) {
    return is_string($source) && LOCAL_SOURCE_ID === sanitize_key($source);
}

/**
 * The query of a local feed, from shortcode attributes.
 *
 * @since 3.10.0
 * @param array $atts Attributes with category, tag, orderby, order and offset.
 * @return array{category: string, tag: string, orderby: string, order: string, offset: int}
 */
function sanitize_local_query($atts) {
    $slugs = function ($value) {
        if (!is_string($value) || '' === trim($value)) {
            return '';
        }
        return implode(',', array_unique(array_filter(array_map('sanitize_title', explode(',', $value)))));
    };
    $orderby = (isset($atts['orderby']) && is_string($atts['orderby'])) ? sanitize_key($atts['orderby']) : '';
    $order   = (isset($atts['order']) && is_string($atts['order'])) ? strtoupper(trim($atts['order'])) : '';
    $offset  = (isset($atts['offset']) && is_scalar($atts['offset'])) ? absint($atts['offset']) : 0;

    return array(
        'category' => $slugs(isset($atts['category']) ? $atts['category'] : ''),
        'tag'      => $slugs(isset($atts['tag']) ? $atts['tag'] : ''),
        'orderby'  => array_key_exists($orderby, LOCAL_ORDERBY) ? $orderby : 'date',
        'order'    => 'ASC' === $order ? 'ASC' : 'DESC',
        'offset'   => min(LOCAL_MAX_OFFSET, $offset),
    );
}

/**
 * Fetch one page of this site's posts.
 *
 * Only published posts without a password, the same posts this site shows
 * to any visitor. Sticky posts keep their date order.
 *
 * @since 3.10.0
 * @param array $query   Query from sanitize_local_query().
 * @param int   $count   Posts per page.
 * @param int   $page    Page number (1-based).
 * @param array $carried Query vars carried over from the page the feed was
 *                       rendered on, see carried_query_vars().
 * @return array{posts: \WP_Post[], total_pages: int, carried: array}
 */
function fetch_local_posts($query, $count, $page, $carried = array()) {
    $count = max(1, (int) $count);
    $page  = max(1, (int) $page);
    $args  = array(
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'has_password'        => false,
        'ignore_sticky_posts' => true,
        'posts_per_page'      => $count,
        // WP_Query ignores "paged" once "offset" is set, so the page's own
        // offset is computed here.
        'offset'              => $query['offset'] + ($page - 1) * $count,
        'orderby'             => LOCAL_ORDERBY[$query['orderby']],
        'order'               => $query['order'],
    );
    if ('' !== $query['category']) {
        $args['category_name'] = $query['category'];
    }
    if ('' !== $query['tag']) {
        $args['tag'] = $query['tag'];
    }
    $args = array_merge($args, $carried);

    /**
     * Filter the WP_Query arguments of a "This site" feed.
     *
     * @since 3.10.0
     * @param array $args  WP_Query arguments.
     * @param array $query The feed's sanitized query attributes.
     * @param int   $page  Page number.
     */
    $args = apply_filters('rest_posts_embedder_local_query_args', $args, $query, $page);

    $wp_query = new \WP_Query($args);
    // found_posts counts the skipped posts too.
    $available = max(0, (int) $wp_query->found_posts - $query['offset']);

    return array(
        'posts'       => $wp_query->posts,
        'total_pages' => (int) ceil($available / $count),
        'carried'     => carried_query_vars($wp_query, $args),
    );
}

/**
 * Query vars that other plugins added to a local feed's query, to apply
 * again when Load More fetches the next pages.
 *
 * Multilingual plugins narrow front-end queries to the page's language
 * (WC Multilang with a tax_query, Polylang with a "lang" var), but not
 * admin-ajax requests, which have no page URL. Without these, the posts
 * appended by Load More would mix every language.
 *
 * @since 3.10.0
 * @param \WP_Query $wp_query The query after it ran.
 * @param array     $args     The arguments the feed passed to it.
 * @return array Query vars, empty when nothing was added.
 */
function carried_query_vars($wp_query, $args) {
    /**
     * Filter the names of the query vars a "This site" feed carries from the
     * page it was rendered on to its Load More requests.
     *
     * @since 3.10.0
     * @param string[] $names Query var names.
     */
    $names   = (array) apply_filters('rest_posts_embedder_local_carried_query_vars', array('tax_query', 'lang'));
    $carried = array();
    foreach ($names as $name) {
        if (!is_string($name) || !isset($wp_query->query_vars[$name])) {
            continue;
        }
        $value = $wp_query->query_vars[$name];
        if (array() === $value || '' === $value || (array_key_exists($name, $args) && $args[$name] === $value)) {
            continue;
        }
        $carried[$name] = $value;
    }
    return $carried;
}

/**
 * Render a page of local posts, with the global post set up for each one as
 * the REST API does, so excerpt filters see the right post.
 *
 * @since 3.10.0
 * @param \WP_Post[] $posts          Posts.
 * @param int        $excerpt_length Excerpt length in characters (0 = unlimited).
 * @param string     $layout         Layout ID.
 * @param int        $excerpt_words  Excerpt length in words (0 = not used).
 * @return string
 */
function render_local_articles($posts, $excerpt_length, $layout, $excerpt_words = 0) {
    global $post;
    $previous = $post;
    try {
        return render_articles($posts, $excerpt_length, $layout, $excerpt_words);
    } finally {
        $post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        if ($previous instanceof \WP_Post) {
            setup_postdata($previous);
        } else {
            wp_reset_postdata();
        }
    }
}

/**
 * Card data of one of this site's posts. Same keys as prepare_card().
 *
 * Shows the publication date in the site's date format, where remote cards
 * show the date of the last change, and links open in the same tab.
 *
 * @since 3.10.0
 * @param \WP_Post $local_post     Post.
 * @param int      $excerpt_length Excerpt length in characters (0 = unlimited).
 * @param int      $excerpt_words  Excerpt length in words (0 = not used).
 * @return array
 */
function prepare_local_card($local_post, $excerpt_length, $excerpt_words = 0) {
    global $post;
    $post = $local_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    setup_postdata($local_post);

    $title = get_the_title($local_post);
    $title = '' !== $title ? esc_html(wp_strip_all_tags($title)) : esc_html__('Untitled', 'restpostsembedder');
    $link  = esc_url(get_permalink($local_post));

    // This site's own date format, as its theme shows the date.
    $date_format = (string) get_option('date_format');
    $timestamp   = feed_gmt_timestamp(str_replace(' ', 'T', (string) $local_post->post_date_gmt));
    $fordate     = false !== $timestamp ? (string) wp_date($date_format, $timestamp) : '';

    $image_src    = '';
    $image_srcset = '';
    $thumbnail_id = get_post_thumbnail_id($local_post);
    if ($thumbnail_id) {
        $image = wp_get_attachment_image_src($thumbnail_id, 'large');
        if ($image) {
            $image_src = esc_url($image[0]);
            $srcset    = wp_get_attachment_image_srcset($thumbnail_id, 'large');
            $image_srcset = $srcset ? esc_attr($srcset) : '';
        }
    }
    $columns_desktop = max(1, absint(get_option('embed_posts_columns_desktop', 2)));
    $image_sizes     = '(max-width: 768px) 100vw, ' . round(100 / $columns_desktop) . 'vw';

    $author_name = get_the_author_meta('display_name', $local_post->post_author);
    $author_name = '' !== $author_name ? esc_html($author_name) : esc_html__('Unknown Author', 'restpostsembedder');
    $author_url  = $local_post->post_author ? esc_url(get_author_posts_url($local_post->post_author)) : '';
    $author_html = $author_url ? '<a href="' . $author_url . '">' . $author_name . '</a>' : $author_name;

    if ('' !== $fordate) {
        /* translators: 1: date the post was last modified, 2: author name. */
        $byline = sprintf(__('%1$s, by %2$s', 'restpostsembedder'), esc_html($fordate), $author_html);
    } else {
        /* translators: %s: author name. */
        $byline = sprintf(__('by %s', 'restpostsembedder'), $author_html);
    }

    // The excerpt as the REST API renders it, then the same truncation as a
    // remote excerpt.
    $excerpt = format_excerpt((string) apply_filters('the_excerpt', get_the_excerpt($local_post)), $excerpt_length, $excerpt_words);

    return array(
        'title'        => $title,
        'link'         => $link,
        'link_target'  => '',
        'date'         => esc_html($fordate),
        'timestamp'    => $timestamp,
        'date_iso'     => false !== $timestamp ? esc_attr((string) wp_date('c', $timestamp)) : '',
        'author'       => $author_name,
        'author_url'   => $author_url,
        'author_html'  => $author_html,
        'byline'       => $byline,
        'image_src'    => $image_src,
        'image_srcset' => $image_srcset,
        'image_sizes'  => esc_attr($image_sizes),
        'image_alt'    => esc_attr($title),
        'excerpt'      => $excerpt,
        'read_more'    => esc_html__('Read more...', 'restpostsembedder'),
        'post'         => $local_post,
    );
}

/**
 * Render a "This site" feed.
 *
 * @since 3.10.0
 * @param array  $query          Query from sanitize_local_query().
 * @param int    $count          Posts per page.
 * @param int    $excerpt_length Excerpt length in characters.
 * @param string $layout         Layout ID.
 * @param string $columns_style  Inline column style ('' for none).
 * @param array  $atts           Shortcode attributes, for the output filter.
 * @param int    $excerpt_words  Excerpt length in words (0 = not used).
 * @param bool   $load_more      Whether to show the Load More button.
 * @return string
 */
function render_local_feed($query, $count, $excerpt_length, $layout, $columns_style, $atts, $excerpt_words = 0, $load_more = true) {
    $fetch = fetch_local_posts($query, $count, 1);
    if (empty($fetch['posts'])) {
        return '<p>' . esc_html__('No posts found.', 'restpostsembedder') . '</p>';
    }

    $html  = '<div class="embed-posts-container embed-posts-layout-' . esc_attr($layout) . '">';
    $html .= '<div class="embed-posts-wrapper"' . ('' !== $columns_style ? ' style="' . esc_attr($columns_style) . '"' : '') . '>';
    $html .= render_local_articles($fetch['posts'], $excerpt_length, $layout, $excerpt_words);
    $html .= '</div>';
    if ($load_more && $fetch['total_pages'] > 1) {
        $html .= render_local_load_more_button($query, $count, $excerpt_length, $fetch['total_pages'], determine_locale(), $layout, $fetch['carried'], $excerpt_words);
    }
    $html .= '</div>';

    /** This filter is documented in shortcodes/functions.php */
    return apply_filters('rest_posts_embedder_output', $html, $atts);
}

/**
 * Load More button of a local feed. Its stored context holds the query
 * instead of an endpoint.
 *
 * @since 3.10.0
 * @param array  $query          Query from sanitize_local_query().
 * @param int    $count          Posts per page.
 * @param int    $excerpt_length Excerpt length in characters.
 * @param int    $total_pages    Total pages available.
 * @param string $locale         Locale the page was rendered in.
 * @param string $layout         Layout ID.
 * @param array  $carried        Query vars to apply again, see carried_query_vars().
 * @param int    $excerpt_words  Excerpt length in words (0 = not used).
 * @return string Button HTML.
 */
function render_local_load_more_button($query, $count, $excerpt_length, $total_pages, $locale, $layout, $carried = array(), $excerpt_words = 0) {
    // The carried vars differ per language, so each language gets its own token.
    $context = array(
        'local'          => $query,
        'count'          => $count,
        'excerpt_length' => $excerpt_length,
        'layout'         => $layout,
        'carried'        => $carried,
        'excerpt_words'  => $excerpt_words,
    );
    $token  = md5('v3_local|' . wp_json_encode($context));
    $option = 'rest_posts_embedder_lm_' . $token;
    if (get_option($option) !== $context) {
        update_option($option, $context, false);
    }
    return load_more_button_html($token, $total_pages, $locale);
}

/**
 * Answer a Load More request for a local feed.
 *
 * @since 3.10.0
 * @param array  $context Stored context with a "local" query.
 * @param int    $page    Requested page (2 or more).
 * @param string $locale  Locale to render in.
 * @return array{html: string, has_more: bool}
 */
function load_more_local($context, $page, $locale) {
    $query          = sanitize_local_query(is_array($context['local']) ? $context['local'] : array());
    $count          = absint($context['count']);
    $count          = ($count > 0 && $count <= REST_POSTS_EMBEDDER_MAX_COUNT) ? $count : REST_POSTS_EMBEDDER_DEFAULT_COUNT;
    $excerpt_length = isset($context['excerpt_length']) ? sanitize_excerpt_length($context['excerpt_length']) : 0;
    $excerpt_words  = isset($context['excerpt_words']) ? sanitize_excerpt_words($context['excerpt_words']) : 0;
    $layout         = resolve_layout(isset($context['layout']) ? $context['layout'] : DEFAULT_LAYOUT);
    // Written by the server when the button was rendered, never by a request.
    $carried        = (isset($context['carried']) && is_array($context['carried'])) ? $context['carried'] : array();

    return with_locale($locale, function () use ($query, $count, $page, $excerpt_length, $layout, $carried, $excerpt_words) {
        $fetch = fetch_local_posts($query, $count, $page, $carried);
        if (empty($fetch['posts'])) {
            return array('html' => '', 'has_more' => false);
        }
        return array(
            'html'     => render_local_articles($fetch['posts'], $excerpt_length, $layout, $excerpt_words),
            'has_more' => $page < $fetch['total_pages'],
        );
    });
}
