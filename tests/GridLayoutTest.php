<?php

use function RestPostsEmbedder\Shortcodes\build_inline_css;
use function RestPostsEmbedder\Shortcodes\columns_style;

/** The Grid layout's markup and the per-feed column attributes. */
class GridLayoutTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';

    private $requests;

    public function set_up() {
        parent::set_up();
        $this->requests = 0;
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 2);
        update_option('embed_posts_excerpt_length', 40);
        update_option('timezone_string', 'Europe/Madrid');
        delete_option('embed_posts_layout');
        delete_option('embed_posts_columns_desktop');
        delete_option('embed_posts_columns_mobile');
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        parent::tear_down();
    }

    public function intercept_request($preempt, $args, $url) {
        $this->assertSame('93.184.216.34', wp_parse_url($url, PHP_URL_HOST), 'Unexpected HTTP request.');
        $this->requests++;
        return array(
            'headers' => array('x-wp-totalpages' => '3'),
            'body' => file_get_contents(__DIR__ . '/fixtures/feed.json'),
            'response' => array('code' => 200, 'message' => ''),
            'cookies' => array(),
        );
    }

    public function test_grid_markup() {
        $this->assertSame(
            file_get_contents(__DIR__ . '/fixtures/grid-feed.html'),
            do_shortcode('[posts_embedder layout="grid"]')
        );
    }

    public function test_grid_can_be_the_site_layout() {
        update_option('embed_posts_layout', 'grid');
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('embed-posts-layout-grid', $html);
        $this->assertSame(2, substr_count($html, '<article class="embed-posts-card">'));
    }

    public function test_grid_card_shows_the_date_in_the_display_timezone() {
        $html = do_shortcode('[posts_embedder layout="grid"]');
        // modified_gmt 10:30 UTC is 11:30 in Madrid in March.
        $this->assertStringContainsString('<time class="embed-posts-card-date" datetime="2026-03-08T11:30:00+01:00">8th of March 2026</time>', $html);
        // The second fixture post has no valid date: no empty <time>.
        $this->assertSame(1, substr_count($html, '<time'));
    }

    public function test_grid_card_without_image_or_excerpt_omits_them() {
        $html = do_shortcode('[posts_embedder layout="grid"]');
        $this->assertSame(1, substr_count($html, 'class="embed-posts-card-image"'));
        // Decorative: the title right below carries the link text.
        $this->assertStringContainsString('aria-hidden="true" tabindex="-1"><img src="https://example.org/uploads/cafe.jpg"', $html);
        $this->assertStringContainsString(' alt="" loading="lazy"', $html);
    }

    /** @dataProvider column_attributes */
    public function test_column_attributes_become_wrapper_variables($columns, $tablet, $style) {
        $this->assertSame($style, columns_style($columns, $tablet));
        $html = do_shortcode('[posts_embedder layout="grid" columns="' . $columns . '" columns_tablet="' . $tablet . '"]');
        $wrapper = '' === $style ? '<div class="embed-posts-wrapper">' : '<div class="embed-posts-wrapper" style="' . $style . '">';
        $this->assertStringContainsString($wrapper, $html);
    }

    public static function column_attributes() {
        return array(
            'none' => array('', '', ''),
            'ProWoos blog pages' => array('3', '', '--rpe-cols: 3; --rpe-cols-tablet: 2;'),
            'one column follows on tablets' => array('1', '', '--rpe-cols: 1; --rpe-cols-tablet: 1;'),
            'both' => array('4', '3', '--rpe-cols: 4; --rpe-cols-tablet: 3;'),
            'tablet only' => array('', '1', '--rpe-cols-tablet: 1;'),
            'above the maximum' => array('9', '', '--rpe-cols: 5; --rpe-cols-tablet: 2;'),
            'zero or negative' => array('0', '-2', '--rpe-cols: 1; --rpe-cols-tablet: 1;'),
            'not a number' => array('three', '"><script>', ''),
        );
    }

    public function test_column_attributes_apply_to_classic_too() {
        $html = do_shortcode('[posts_embedder columns="4"]');
        $this->assertStringContainsString('<div class="embed-posts-wrapper" style="--rpe-cols: 4; --rpe-cols-tablet: 2;">', $html);
        $this->assertStringContainsString('<article class="embed-posts">', $html);
    }

    public function test_each_column_setting_is_cached_separately() {
        $three = do_shortcode('[posts_embedder layout="grid" columns="3"]');
        $four = do_shortcode('[posts_embedder layout="grid" columns="4"]');
        $this->assertNotSame($three, $four);
        $this->assertSame(2, $this->requests);
        $this->assertSame($three, do_shortcode('[posts_embedder layout="grid" columns="3"]'));
        $this->assertSame(2, $this->requests);
    }

    /** @dataProvider site_columns */
    public function test_site_settings_set_the_column_variables($desktop, $mobile, $expected) {
        update_option('embed_posts_columns_desktop', $desktop);
        update_option('embed_posts_columns_mobile', $mobile);
        $css = preg_replace('/\s+/', ' ', build_inline_css());
        $this->assertStringContainsString($expected, $css);
        $this->assertStringContainsString('grid-template-columns: repeat(var(--rpe-cols), 1fr);', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(var(--rpe-cols-mobile), 1fr);', $css);
    }

    public static function site_columns() {
        return array(
            'defaults' => array(2, 1, '--rpe-cols: 2; --rpe-cols-tablet: 2; --rpe-cols-mobile: 1;'),
            'three' => array(3, 2, '--rpe-cols: 3; --rpe-cols-tablet: 2; --rpe-cols-mobile: 2;'),
            'single column' => array(1, 1, '--rpe-cols: 1; --rpe-cols-tablet: 1; --rpe-cols-mobile: 1;'),
            'zero stored' => array(0, 0, '--rpe-cols: 1; --rpe-cols-tablet: 1; --rpe-cols-mobile: 1;'),
        );
    }

    /** @dataProvider word_counts */
    public function test_excerpt_in_words_wins_over_characters($attributes, $excerpt) {
        $html = do_shortcode('[posts_embedder layout="grid" ' . $attributes . ']');
        $this->assertStringContainsString('<div class="embed-posts-card-excerpt">' . $excerpt . '</div>', $html);
    }

    public static function word_counts() {
        return array(
            'words' => array('excerpt_words="3"', '<p>Alpha beta gamma…</p>'),
            'words over characters' => array('excerpt_words="3" excerpt_length="10"', '<p>Alpha beta gamma…</p>'),
            'more words than the excerpt has' => array('excerpt_words="50"', '<p>Alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu.</p>'),
            'zero words means characters' => array('excerpt_words="0" excerpt_length="12"', '<p>Alpha beta…</p>'),
            'capped at 200' => array('excerpt_words="9999"', '<p>Alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu.</p>'),
        );
    }

    public function test_excerpt_in_words_is_cached_separately_and_kept_by_load_more() {
        $chars = do_shortcode('[posts_embedder layout="grid"]');
        $words = do_shortcode('[posts_embedder layout="grid" excerpt_words="2"]');
        $this->assertNotSame($chars, $words);
        $this->assertSame(2, $this->requests);
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $words, $match));
        $this->assertSame(2, get_option('rest_posts_embedder_lm_' . $match[1])['excerpt_words']);
        // A feed without words keeps the context of 3.9.0 (no excerpt_words key).
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $chars, $match));
        $this->assertArrayNotHasKey('excerpt_words', get_option('rest_posts_embedder_lm_' . $match[1]));
    }

    public function test_grid_styles_ship_in_the_stylesheet() {
        $css = file_get_contents(dirname(__DIR__) . '/assets/css/custom.css');
        foreach (array('.embed-posts-layout-grid .embed-posts-wrapper', '.embed-posts-card-image', '.embed-posts-card-date', '.embed-posts-card-readmore', 'aspect-ratio: 16 / 10') as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }
}
