<?php

use function RestPostsEmbedder\Shortcodes\build_inline_css;

/** Byline dates, unexpected feed field types and the generated image CSS. */
class RenderingTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';

    private $posts;
    private $php_timezone;

    public function set_up() {
        parent::set_up();
        $this->posts = array($this->post());
        $this->php_timezone = date_default_timezone_get();
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 5);
        update_option('embed_posts_excerpt_length', 200);
        update_option('timezone_string', 'UTC');
        // Do not let a persistent object cache hide requests between test cases.
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
        date_default_timezone_set($this->php_timezone);
        parent::tear_down();
    }

    public function intercept_request($preempt, $args, $url) {
        $this->assertSame('93.184.216.34', wp_parse_url($url, PHP_URL_HOST), 'Unexpected HTTP request.');
        return array(
            'headers' => array('x-wp-totalpages' => '1'),
            'body' => wp_json_encode($this->posts),
            'response' => array('code' => 200, 'message' => ''),
            'cookies' => array(),
        );
    }

    /** A complete post as the REST API returns it with _embed. */
    private function post($title = 'A remote post') {
        return json_decode(wp_json_encode(array(
            'title' => array('rendered' => $title),
            'link' => 'https://example.org/a-post',
            // 2 July, half past midnight in Madrid (UTC+2) is still 1 July in UTC.
            'modified' => '2026-07-02T00:30:00',
            'modified_gmt' => '2026-07-01T22:30:00',
            'excerpt' => array('rendered' => '<p>Alpha beta gamma delta.</p>'),
            'author' => 7,
            'featured_media' => 9,
            '_embedded' => array(
                'author' => array(array('name' => 'Ada', 'link' => 'https://example.org/author/ada')),
                'wp:featuredmedia' => array(array(
                    'source_url' => 'https://example.org/full.jpg',
                    'media_details' => array(
                        'width' => 1200,
                        'sizes' => array(
                            'medium' => array('source_url' => 'https://example.org/medium.jpg', 'width' => 300),
                            'large' => array('source_url' => 'https://example.org/large.jpg', 'width' => 1024),
                        ),
                    ),
                )),
            ),
        )));
    }

    private function byline($html) {
        $this->assertSame(1, preg_match('#<small>(.*?)</small>#s', $html, $match), 'Byline missing: ' . $html);
        return wp_strip_all_tags($match[1]);
    }

    /** @dataProvider display_timezones */
    public function test_byline_shows_the_gmt_date_in_the_display_timezone($site_timezone, $php_timezone, $expected) {
        update_option('timezone_string', $site_timezone);
        // The result must not depend on PHP's default timezone either.
        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
        date_default_timezone_set($php_timezone);
        $this->assertSame($expected . ', by Ada', $this->byline(do_shortcode('[posts_embedder]')));
    }

    public static function display_timezones() {
        return array(
            'UTC site' => array('UTC', 'UTC', '1st of July 2026'),
            'Madrid site' => array('Europe/Madrid', 'UTC', '2nd of July 2026'),
            'New York site' => array('America/New_York', 'UTC', '1st of July 2026'),
            'UTC site, PHP default elsewhere' => array('UTC', 'Pacific/Kiritimati', '1st of July 2026'),
            'Madrid site, PHP default elsewhere' => array('Europe/Madrid', 'America/Los_Angeles', '2nd of July 2026'),
        );
    }

    /** @dataProvider unusable_dates */
    public function test_byline_without_a_valid_gmt_date_shows_the_author_alone($modified_gmt) {
        if ('__missing__' === $modified_gmt) {
            unset($this->posts[0]->modified_gmt);
        } else {
            $this->posts[0]->modified_gmt = $modified_gmt;
        }
        $this->assertSame('by Ada', $this->byline(do_shortcode('[posts_embedder]')));
    }

    public static function unusable_dates() {
        return array(
            'missing' => array('__missing__'),
            'null' => array(null),
            'empty' => array(''),
            'relative words' => array('yesterday'),
            'date only' => array('2026-07-01'),
            'with offset' => array('2026-07-01T22:30:00+02:00'),
            'impossible day' => array('2026-02-31T10:00:00'),
            'impossible hour' => array('2026-07-01T25:00:00'),
            'number' => array(1782945000),
            'array' => array(array('2026-07-01T22:30:00')),
            'object' => array((object) array('rendered' => '2026-07-01T22:30:00')),
            'boolean' => array(true),
        );
    }

    /** @dataProvider malformed_posts */
    public function test_unexpected_field_types_do_not_interrupt_the_page($mutate) {
        $broken = $this->post('Broken post');
        $mutate($broken);
        $this->posts = array($broken, $this->post('Good post'));

        $html = do_shortcode('[posts_embedder]');

        $this->assertSame(2, substr_count($html, '<article class="embed-posts">'));
        // The post after the malformed one is complete.
        $this->assertStringContainsString('<h3>Good post</h3>', $html);
        $this->assertStringContainsString('1st of July 2026, by <a href="https://example.org/author/ada"', $html);
        $this->assertStringContainsString('src="https://example.org/full.jpg"', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public static function malformed_posts() {
        $junk = array(
            'array' => array('x', 'y'),
            'object' => (object) array('x' => 'y'),
            'number' => 42,
            'boolean' => true,
            'null' => null,
        );
        $fields = array(
            'title' => function ($post, $value) { $post->title = $value; },
            'title.rendered' => function ($post, $value) { $post->title->rendered = $value; },
            'link' => function ($post, $value) { $post->link = $value; },
            'modified_gmt' => function ($post, $value) { $post->modified_gmt = $value; },
            'excerpt' => function ($post, $value) { $post->excerpt = $value; },
            'excerpt.rendered' => function ($post, $value) { $post->excerpt->rendered = $value; },
            '_embedded' => function ($post, $value) { $post->_embedded = $value; },
            'author list' => function ($post, $value) { $post->_embedded->author = $value; },
            'author entry' => function ($post, $value) { $post->_embedded->author = array($value); },
            'author name' => function ($post, $value) { $post->_embedded->author[0]->name = $value; },
            'author link' => function ($post, $value) { $post->_embedded->author[0]->link = $value; },
            'media list' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'} = $value; },
            'media entry' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'} = array($value); },
            'media source_url' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->source_url = $value; },
            'media_details' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details = $value; },
            'media width' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details->width = $value; },
            'sizes' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes = $value; },
            'size entry' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->large = $value; },
            'size source_url' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->large->source_url = $value; },
            'size width' => function ($post, $value) { $post->_embedded->{'wp:featuredmedia'}[0]->media_details->sizes->large->width = $value; },
        );
        $cases = array();
        foreach ($fields as $field => $setter) {
            foreach ($junk as $type => $value) {
                $cases["$field is $type"] = array(function ($post) use ($setter, $value) {
                    $setter($post, is_object($value) ? clone $value : $value);
                });
            }
        }
        return $cases;
    }

    public function test_feed_items_that_are_not_posts_are_skipped() {
        $this->posts = array(null, 'text', 5, array('a', 'b'), true, $this->post('Good post'));
        $html = do_shortcode('[posts_embedder]');
        $this->assertSame(1, substr_count($html, '<article class="embed-posts">'));
        $this->assertStringContainsString('<h3>Good post</h3>', $html);
    }

    public function test_feed_without_any_post_object_has_no_posts() {
        $this->posts = array(null, 'text', 5);
        $this->assertStringContainsString('No posts found.', do_shortcode('[posts_embedder]'));
    }

    /** @dataProvider image_settings */
    public function test_image_visibility_rules_apply_to_their_own_viewport_only($desktop, $mobile) {
        update_option('embed_posts_show_images_desktop', $desktop);
        update_option('embed_posts_show_images_mobile', $mobile);
        $css = preg_replace('/\s+/', ' ', build_inline_css());

        $desktop_rule = '@media only screen and (min-width: 769px) { .embed-posts img { display: none; } }';
        $mobile_rule = '.embed-posts img { display: none !important; }';
        $this->assertSame($desktop ? 0 : 1, substr_count($css, $desktop_rule));
        $this->assertSame($mobile ? 0 : 1, substr_count($css, $mobile_rule));

        // The mobile rule sits inside the max-width block, after the desktop one.
        $mobile_block = strpos($css, '@media only screen and (max-width: 768px)');
        $this->assertNotFalse($mobile_block);
        if (!$mobile) {
            $this->assertGreaterThan($mobile_block, strpos($css, $mobile_rule));
        }
        // Nothing hides images outside those two rules.
        $rest = str_replace(array($desktop_rule, $mobile_rule), '', $css);
        $this->assertStringNotContainsString('display: none', $rest);
    }

    public static function image_settings() {
        return array(
            'both shown' => array(1, 1),
            'desktop hidden, mobile shown' => array(0, 1),
            'desktop shown, mobile hidden' => array(1, 0),
            'both hidden' => array(0, 0),
        );
    }
}
