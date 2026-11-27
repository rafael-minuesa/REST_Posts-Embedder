<?php

use function RestPostsEmbedder\default_sources;
use function RestPostsEmbedder\get_default_endpoint;
use function RestPostsEmbedder\get_sources;
use function RestPostsEmbedder\rest_posts_embedder_activate;

/** A site that never stored the plugin's defaults, such as the other sites of a network. */
class SiteDefaultsTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';

    private $requests;
    private $filtered;

    public function set_up() {
        parent::set_up();
        $this->requests = array();
        $this->filtered = array();
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
        // The demo feed's hostname would need DNS; send it to the fixture and
        // record which endpoint the shortcode had chosen.
        add_filter('rest_posts_embedder_endpoint', array($this, 'use_fixture'));
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        remove_filter('rest_posts_embedder_endpoint', array($this, 'use_fixture'));
        parent::tear_down();
    }

    public function use_fixture($endpoint) {
        $this->filtered[] = $endpoint;
        return REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT === $endpoint ? self::ENDPOINT : $endpoint;
    }

    public function intercept_request($preempt, $args, $url) {
        $this->assertSame('93.184.216.34', wp_parse_url($url, PHP_URL_HOST), 'Unexpected HTTP request.');
        $this->requests[] = $url;
        $post = (object) array(
            'title' => (object) array('rendered' => 'A remote post'),
            'link' => 'https://example.org/a-post',
            'modified_gmt' => '2026-01-15T12:00:00',
            'excerpt' => (object) array('rendered' => '<p>Alpha beta gamma delta.</p>'),
        );
        return array(
            'headers' => array('x-wp-totalpages' => '1'),
            'body' => wp_json_encode(array($post)),
            'response' => array('code' => 200, 'message' => ''),
            'cookies' => array(),
        );
    }

    /** As on a network site the activation hook never ran for. */
    private function forget_stored_defaults() {
        delete_option('rest_posts_embedder_sources');
        delete_option('embed_posts_endpoint');
        delete_option('embed_posts_count');
    }

    private function assert_demo_defaults() {
        $this->assertSame(REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT, get_default_endpoint());
        $sources = get_sources();
        $this->assertSame(array('default'), array_keys($sources));
        $this->assertSame(REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT, $sources['default']['endpoint']);
        $this->assertSame(REST_POSTS_EMBEDDER_DEFAULT_COUNT, $sources['default']['count']);
        $this->assertTrue($sources['default']['enabled']);
    }

    public function test_site_without_stored_defaults_uses_the_demo_feed() {
        $this->forget_stored_defaults();
        $this->assert_demo_defaults();
        // Reading the defaults does not store them.
        $this->assertFalse(get_option('rest_posts_embedder_sources'));
        $this->assertFalse(get_option('embed_posts_endpoint'));
    }

    /** @dataProvider shortcodes */
    public function test_shortcodes_render_on_a_site_without_stored_defaults($shortcode) {
        $this->forget_stored_defaults();
        $html = do_shortcode($shortcode);
        $this->assertStringContainsString('A remote post', $html);
        $this->assertStringNotContainsString('Invalid endpoint URL', $html);
        $this->assertStringNotContainsString('not found', $html);
        $this->assertSame(array(REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT), $this->filtered);
        $this->assertCount(1, $this->requests);
    }

    public static function shortcodes() {
        return array('bare' => array('[posts_embedder]'), 'default source' => array('[posts_embedder source="default"]'));
    }

    public function test_activation_stores_the_same_defaults_a_site_gets_without_it() {
        $this->forget_stored_defaults();
        $lazy = get_sources();
        rest_posts_embedder_activate();
        $this->assertSame($lazy, get_option('rest_posts_embedder_sources'));
        $this->assertSame(REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT, get_option('embed_posts_endpoint'));
        $this->assertSame($lazy, get_sources());
    }

    public function test_endpoint_from_before_multiple_sources_becomes_the_default_source() {
        $this->forget_stored_defaults();
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 7);
        $source = default_sources()['default'];
        $this->assertSame(self::ENDPOINT, $source['endpoint']);
        $this->assertEquals(7, $source['count']);
        $this->assertSame('Default Source', $source['name']);
        $this->assertSame(default_sources(), get_sources());
    }

    public function test_site_that_deleted_all_its_sources_keeps_none() {
        update_option('rest_posts_embedder_sources', array());
        $this->assertSame(array(), get_sources());
        $this->assertStringContainsString('not found', do_shortcode('[posts_embedder source="default"]'));
        $this->assertCount(0, $this->requests);
    }

    public function test_corrupted_sources_option_is_an_empty_list() {
        update_option('rest_posts_embedder_sources', 'not an array');
        $this->assertSame(array(), get_sources());
    }

    public function test_other_site_of_a_network_works_without_its_own_activation() {
        if (!is_multisite()) {
            $this->markTestSkipped('Runs with composer test:multisite.');
        }
        // Network activation runs the hook on the main site only.
        rest_posts_embedder_activate();
        $site = self::factory()->blog->create();
        switch_to_blog($site);
        try {
            $this->assertFalse(get_option('rest_posts_embedder_sources'), 'The new site has nothing stored.');
            $this->assert_demo_defaults();
            update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
            $this->assertStringContainsString('A remote post', do_shortcode('[posts_embedder]'));
            $this->assertStringContainsString('A remote post', do_shortcode('[posts_embedder source="default"]'));
        } finally {
            restore_current_blog();
        }
    }
}
