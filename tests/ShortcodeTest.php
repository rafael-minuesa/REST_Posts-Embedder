<?php

use function RestPostsEmbedder\Shortcodes\rest_posts_embedder;

/** Exercise the registered shortcode using real WordPress sanitizers and KSES. */
class ShortcodeTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';

    private $response;
    private $requests;

    public function set_up() {
        parent::set_up();
        $this->requests = array();
        $this->response = $this->response_for(array($this->post()));
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 5);
        update_option('embed_posts_excerpt_length', 200);
        // Do not let a persistent object cache hide requests between test cases.
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
        $this->requests[] = array('url' => $url, 'args' => $args);
        return $this->response;
    }

    private function response_for($posts, $status = 200) {
        return array(
            'headers' => array('x-wp-totalpages' => '1'),
            'body' => wp_json_encode($posts),
            'response' => array('code' => $status, 'message' => ''),
            'cookies' => array(),
        );
    }

    private function post() {
        return (object) array(
            'title' => (object) array('rendered' => 'A remote post'),
            'link' => 'https://example.org/a-post',
            'modified' => '2026-01-15T12:00:00',
            'excerpt' => (object) array('rendered' => '<p>Alpha beta gamma delta.</p>'),
        );
    }

    private function request_query() {
        $this->assertCount(1, $this->requests);
        parse_str(wp_parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);
        return $query;
    }

    public function test_registered_shortcode_uses_defaults_and_caches_success() {
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('<h3>A remote post</h3>', $html);
        $this->assertStringContainsString('<p>Alpha beta gamma delta.</p>', $html);
        $this->assertSame($html, do_shortcode('[posts_embedder]'));
        $this->assertSame(array('per_page' => '5', 'page' => '1', '_embed' => '1'), $this->request_query());
        $this->assertTrue($this->requests[0]['args']['reject_unsafe_urls']);
        $this->assertTrue($this->requests[0]['args']['sslverify']);
        $this->assertSame(10, $this->requests[0]['args']['timeout']);
    }

    /** @dataProvider counts */
    public function test_shortcode_sanitizes_count($raw, $expected) {
        do_shortcode('[posts_embedder count="' . $raw . '"]');
        $this->assertSame((string) $expected, $this->request_query()['per_page']);
    }

    public static function counts() {
        return array(
            'minimum' => array('1', 1),
            'maximum' => array('20', 20),
            'negative becomes absolute' => array('-3', 3),
            'fraction is truncated' => array('3.9', 3),
            'zero falls back' => array('0', 5),
            'too large falls back' => array('21', 5),
            'text falls back' => array('garbage', 5),
            'empty falls back' => array('', 5),
        );
    }

    public function test_endpoint_is_cleaned_and_pagination_overrides_query_values() {
        rest_posts_embedder(array('endpoint' => '  ' . self::ENDPOINT . '?categories=12&per_page=999&page=8&_embed=0  ', 'count' => 2));
        $this->assertSame(array('categories' => '12', 'per_page' => '2', 'page' => '1', '_embed' => '1'), $this->request_query());
        $this->assertStringStartsWith(self::ENDPOINT . '?', $this->requests[0]['url']);
    }

    /** @dataProvider invalid_endpoints */
    public function test_invalid_endpoint_is_rejected_without_http($endpoint) {
        $this->assertSame('<p>Invalid endpoint URL.</p>', rest_posts_embedder(array('endpoint' => $endpoint)));
        $this->assertCount(0, $this->requests);
    }

    public static function invalid_endpoints() {
        return array(
            'empty' => array(''),
            'malformed' => array('http://'),
            'script' => array('javascript:alert(1)'),
            'file' => array('file:///etc/passwd'),
            'ftp' => array('ftp://93.184.216.34/feed'),
            'loopback' => array('http://127.0.0.1/feed'),
            'private network' => array('http://192.168.1.2/feed'),
            'credentials' => array('https://user:password@93.184.216.34/feed'),
            'unsafe port' => array('https://93.184.216.34:22/feed'),
        );
    }

    public function test_source_id_is_sanitized_and_source_settings_take_precedence() {
        update_option('rest_posts_embedder_sources', array('my-feed' => array(
            'name' => 'My feed', 'enabled' => true, 'endpoint' => self::ENDPOINT,
            'count' => '7', 'excerpt_length' => 10,
        )));
        $html = do_shortcode('[posts_embedder source="MY-FEED!" endpoint="javascript:alert(1)" count="19"]');
        $this->assertSame('7', $this->request_query()['per_page']);
        $this->assertStringContainsString('<p>Alpha…</p>', $html);
        $overridden = do_shortcode('[posts_embedder source="my-feed" excerpt_length="0"]');
        $this->assertStringContainsString('<p>Alpha beta gamma delta.</p>', $overridden);
    }

    public function test_unknown_and_disabled_sources_do_not_fetch() {
        $this->assertStringContainsString('Feed source "missing" not found.', do_shortcode('[posts_embedder source="MISSING!"]'));
        update_option('rest_posts_embedder_sources', array('disabled' => array(
            'name' => '<script>alert(1)</script>', 'enabled' => false,
        )));
        $html = do_shortcode('[posts_embedder source="disabled"]');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertCount(0, $this->requests);
    }

    /** @dataProvider excerpt_lengths */
    public function test_excerpt_length_is_sanitized($raw, $expected) {
        $resolved = null;
        add_filter('rest_posts_embedder_excerpt_length', function ($length) use (&$resolved) {
            $resolved = $length;
            return $length;
        });
        rest_posts_embedder(array('excerpt_length' => $raw));
        $this->assertSame($expected, $resolved);
    }

    public static function excerpt_lengths() {
        return array(
            'default' => array('', 200),
            'unlimited' => array('0', 0),
            'negative' => array('-10', 10),
            'fraction' => array('10.8', 10),
            'non-numeric' => array('invalid', 0),
            'capped' => array('99999', 2000),
        );
    }

    public function test_remote_title_author_links_and_image_attributes_are_escaped() {
        $post = $this->post();
        $post->title->rendered = '<script>alert("title")</script> " onerror="alert(1)';
        $post->link = 'javascript:alert(2)';
        $post->author = 1;
        $post->featured_media = 1;
        $post->_embedded = (object) array(
            'author' => array((object) array('name' => '<img src=x onerror=alert(3)>', 'link' => 'javascript:alert(4)')),
            'wp:featuredmedia' => array((object) array(
                'source_url' => 'https://example.org/photo.jpg" onerror="alert(5)',
                'media_details' => (object) array('width' => 1200, 'sizes' => (object) array(
                    'medium' => (object) array('width' => 300, 'source_url' => 'https://example.org/small.jpg" onload="alert(6)'),
                )),
            )),
        );
        $this->response = $this->response_for(array($post));
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(3)&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $xpath = $this->html_xpath($html);
        $this->assertSame(0, $xpath->query('//script | //*[@onerror or @onload]')->length);
        $this->assertSame(1, $xpath->query('//img[@src and @srcset and @alt]')->length);
        $this->assertSame($post->title->rendered, $xpath->query('//img')->item(0)->getAttribute('alt'));
    }

    public function test_unsafe_featured_image_url_is_not_rendered() {
        $post = $this->post();
        $post->featured_media = 1;
        $post->_embedded = (object) array('wp:featuredmedia' => array((object) array('source_url' => 'javascript:alert(1)')));
        $this->response = $this->response_for(array($post));
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_unlimited_excerpt_keeps_safe_html_and_removes_active_content() {
        $post = $this->post();
        $post->excerpt->rendered = '<p onclick="alert(1)">Hello <strong>reader</strong> '
            . '<a href="https://example.org/safe">safe link</a><script>alert(2)</script>'
            . '<iframe src="https://example.org"></iframe><img src="x" onerror="alert(3)">'
            . '<a href="javascript:alert(4)">bad link</a></p>';
        $this->response = $this->response_for(array($post));
        $html = do_shortcode('[posts_embedder excerpt_length="0"]');
        $this->assertStringContainsString('<strong>reader</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.org/safe">safe link</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame(0, $this->html_xpath($html)->query('//script | //iframe | //*[@onclick or @onerror]')->length);
    }

    public function test_truncated_excerpt_escapes_decoded_entities() {
        $post = $this->post();
        $post->excerpt->rendered = '<p>&lt;img src=x onerror=alert(1)&gt; more words here</p>';
        $this->response = $this->response_for(array($post));
        $html = do_shortcode('[posts_embedder excerpt_length="35"]');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; more…', $html);
        $this->assertSame(0, $this->html_xpath($html)->query('//img')->length);
    }

    private function html_xpath($html) {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    /** @dataProvider transport_errors */
    public function test_unreachable_endpoints_escape_errors_and_allow_retry($message) {
        $this->response = new WP_Error('http_request_failed', $message);
        $html = do_shortcode('[posts_embedder]');
        $this->assertSame('<p>Unable to fetch posts. Error: ' . esc_html($message)
            . '. Please check your endpoint URL in the plugin settings.</p>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('embed-posts-load-more', $html);
        $this->response = $this->response_for(array($this->post()));
        $this->assertStringContainsString('<h3>A remote post</h3>', do_shortcode('[posts_embedder]'));
        $this->assertCount(2, $this->requests, 'A failed response must not poison the cache.');
    }

    public static function transport_errors() {
        return array(
            'DNS' => array('Could not resolve host'),
            'timeout' => array('Operation timed out'),
            'unsafe redirect' => array('A valid URL was not provided.'),
            'remote markup' => array('<script>alert("error")</script> & failed'),
        );
    }

    /** @dataProvider http_errors */
    public function test_http_error_statuses_are_reported_without_rendering_response_body($status) {
        $this->response = $this->response_for(array($this->post()), $status);
        $this->assertSame('<p>Unable to fetch posts. Server returned HTTP error ' . $status
            . '. Please verify the endpoint URL is correct.</p>', do_shortcode('[posts_embedder]'));
        $this->assertCount(1, $this->requests);
    }

    public static function http_errors() {
        return array(array(301), array(403), array(404), array(429), array(500), array(503));
    }

    /** @dataProvider invalid_json */
    public function test_invalid_json_is_reported_and_not_cached($body) {
        $this->response['body'] = $body;
        $this->assertSame('<p>Unable to parse response from API. Invalid JSON received.</p>', do_shortcode('[posts_embedder]'));
        $this->response = $this->response_for(array($this->post()));
        $this->assertStringContainsString('<h3>A remote post</h3>', do_shortcode('[posts_embedder]'));
        $this->assertCount(2, $this->requests);
    }

    public static function invalid_json() {
        return array('HTML error page' => array('<html><script>alert(1)</script></html>'), 'truncated JSON' => array('[{"title":'), 'empty body' => array(''));
    }

    /** @dataProvider empty_or_non_list_json */
    public function test_empty_or_non_list_json_has_no_posts($body) {
        $this->response['body'] = $body;
        $this->assertSame('<p>No posts found.</p>', do_shortcode('[posts_embedder]'));
        $this->assertCount(1, $this->requests);
    }

    public static function empty_or_non_list_json() {
        return array(array('[]'), array('{}'), array('null'), array('false'), array('"not a list"'));
    }
}
