<?php

use function RestPostsEmbedder\Shortcodes\rest_posts_embedder;

/**
 * Exercise the Load More button and its admin-ajax handler through the real
 * wp_ajax_* hooks and WordPress's AJAX die handler.
 */
class LoadMoreTest extends WP_Ajax_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';
    private const ACTION = 'rest_posts_embedder_load_more';

    private $total_pages;
    private $failure;
    private $requests;

    public function set_up() {
        parent::set_up();
        // _handleAjax() fires admin_init. The parent class removes these update
        // checks once per class, but the saved hooks restore them after each test
        // when another test class ran first.
        remove_action('admin_init', '_maybe_update_core');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('admin_init', '_maybe_update_themes');
        $this->requests = array();
        $this->total_pages = 3;
        $this->failure = null;
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 2);
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
        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query);
        $this->requests[] = $query;
        if (null !== $this->failure) {
            return $this->failure;
        }
        $page = (int) $query['page'];
        if ($page > $this->total_pages) {
            // What WordPress returns past the last page (rest_post_invalid_page_number).
            return $this->response(400, '{"code":"rest_post_invalid_page_number"}');
        }
        $post = (object) array(
            'title' => (object) array('rendered' => 'Remote post page ' . $page),
            'link' => 'https://example.org/page-' . $page,
            'modified' => '2026-01-15T12:00:00',
            'excerpt' => (object) array('rendered' => '<p>Excerpt ' . $page . '</p>'),
        );
        return $this->response(200, wp_json_encode(array($post)));
    }

    private function response($status, $body) {
        return array(
            'headers' => array('x-wp-totalpages' => (string) $this->total_pages),
            'body' => $body,
            'response' => array('code' => $status, 'message' => ''),
            'cookies' => array(),
        );
    }

    /** Render the feed and return the Load More button's token. */
    private function rendered_token() {
        $html = do_shortcode('[posts_embedder]');
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $html, $match), 'Load More button missing.');
        $this->requests = array();
        return $match[1];
    }

    /** Run the handler as a logged-out visitor would reach it and decode the JSON. */
    private function load_more($post, $logged_in = false) {
        $_POST = $post;
        $this->_last_response = '';
        try {
            if ($logged_in) {
                $this->_handleAjax(self::ACTION);
            } else {
                $this->handle_nopriv_ajax();
            }
            $this->fail('The handler must end with wp_die().');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_*() printed its JSON and called wp_die().
        }
        $response = json_decode($this->_last_response, true);
        $this->assertIsArray($response, 'Response is not JSON: ' . $this->_last_response);
        return $response;
    }

    /** Same as _handleAjax(), but through the wp_ajax_nopriv_ hook. */
    private function handle_nopriv_ajax() {
        ob_start();
        $_POST['action'] = self::ACTION;
        $_REQUEST = $_POST;
        do_action('wp_ajax_nopriv_' . self::ACTION);
        $buffer = ob_get_clean();
        if ('' !== $buffer) {
            $this->_last_response = $buffer;
        }
    }

    private function assert_rejected($response, $message) {
        $this->assertFalse($response['success']);
        $this->assertSame($message, $response['data']['message']);
        $this->assertCount(0, $this->requests, 'A rejected request must not reach the remote feed.');
    }

    public function test_button_is_rendered_only_when_more_pages_exist() {
        $html = do_shortcode('[posts_embedder]');
        $this->assertSame(1, preg_match(
            '#<button type="button" class="embed-posts-load-more" data-token="([a-f0-9]{32})" data-page="1" data-total-pages="3">Load More</button>#',
            $html,
            $match
        ));
        $this->assertSame(
            array('endpoint' => self::ENDPOINT, 'count' => 2, 'excerpt_length' => 200),
            get_option('rest_posts_embedder_lm_' . $match[1])
        );

        foreach (array(1, 0) as $total_pages) {
            update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
            $this->total_pages = $total_pages;
            $this->assertStringNotContainsString('embed-posts-load-more', do_shortcode('[posts_embedder]'));
        }
    }

    public function test_valid_token_returns_next_page_and_caches_it() {
        $token = $this->rendered_token();
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertTrue($response['success']);
        $this->assertTrue($response['data']['has_more']);
        $this->assertStringContainsString('<h3>Remote post page 2</h3>', $response['data']['html']);
        $this->assertStringStartsWith('<article class="embed-posts">', $response['data']['html']);
        $this->assertSame(array('per_page' => '2', 'page' => '2', '_embed' => '1'), $this->requests[0]);

        $this->assertSame($response, $this->load_more(array('token' => $token, 'page' => '2')));
        $this->assertCount(1, $this->requests, 'A rendered page must be served from cache.');

        $last = $this->load_more(array('token' => $token, 'page' => '3'), true);
        $this->assertTrue($last['success']);
        $this->assertFalse($last['data']['has_more']);
        $this->assertStringContainsString('<h3>Remote post page 3</h3>', $last['data']['html']);
    }

    /** @dataProvider malformed_requests */
    public function test_malformed_token_or_page_is_rejected($token, $page) {
        $valid = $this->rendered_token();
        $post = array();
        if (null !== $token) {
            $post['token'] = str_replace('VALID', $valid, $token);
        }
        if (null !== $page) {
            $post['page'] = $page;
        }
        $this->assert_rejected($this->load_more($post), 'Invalid request.');
    }

    public static function malformed_requests() {
        return array(
            'missing token' => array(null, '2'),
            'empty token' => array('', '2'),
            'short token' => array('abc123', '2'),
            'token with suffix' => array("VALID' OR 1=1", '2'),
            'page 1 is already rendered' => array('VALID', '1'),
            'page 0' => array('VALID', '0'),
            'non-numeric page' => array('VALID', 'next'),
            'missing page' => array('VALID', null),
        );
    }

    public function test_uppercase_token_is_rejected() {
        // MySQL option names are case-insensitive, so only the format check stops this one.
        $token = $this->rendered_token();
        $this->assert_rejected($this->load_more(array('token' => strtoupper($token), 'page' => '2')), 'Invalid request.');
    }

    public function test_unknown_token_reports_expired_feed() {
        $response = $this->load_more(array('token' => str_repeat('a', 32), 'page' => '2'));
        $this->assert_rejected($response, 'This feed has expired. Please refresh the page.');
        $this->assertTrue($response['data']['expired']);
    }

    public function test_page_beyond_the_last_is_an_error() {
        $token = $this->rendered_token();
        $response = $this->load_more(array('token' => $token, 'page' => '4'));
        $this->assertFalse($response['success']);
        $this->assertSame('Unable to fetch posts. Server returned HTTP error 400. Please verify the endpoint URL is correct.', $response['data']['message']);
        $this->assertArrayNotHasKey('html', $response['data']);
    }

    public function test_remote_failure_is_an_escaped_error_and_not_cached() {
        $token = $this->rendered_token();
        $this->failure = new WP_Error('http_request_failed', '<script>alert("x")</script> & timed out');
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertFalse($response['success']);
        $this->assertSame('Unable to fetch posts. Error: &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; timed out.'
            . ' Please check your endpoint URL in the plugin settings.', $response['data']['message']);
        $this->assertArrayNotHasKey('html', $response['data']);

        $this->failure = null;
        $retry = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertTrue($retry['success']);
        $this->assertCount(2, $this->requests, 'A failed page must not poison the cache.');
    }

    public function test_handler_is_public_and_needs_no_nonce() {
        // By design: a nonce in page-cached HTML would expire and break the button.
        $this->assertNotFalse(has_action('wp_ajax_nopriv_' . self::ACTION));
        $this->assertNotFalse(has_action('wp_ajax_' . self::ACTION));
        $token = $this->rendered_token();
        $this->assertTrue($this->load_more(array('token' => $token, 'page' => '2'))['success']);
    }
}
