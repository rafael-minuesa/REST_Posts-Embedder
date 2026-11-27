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
    private $cleanup = array();

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
        foreach ($this->cleanup as $cleanup) {
            $cleanup();
        }
        $this->cleanup = array();
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
            'modified_gmt' => '2026-01-15T12:00:00',
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
            '#<button type="button" class="embed-posts-load-more" data-token="([a-f0-9]{32})" data-page="1" data-total-pages="3" data-locale="en_US">Load More</button>#',
            $html,
            $match
        ));
        $this->assertSame(
            array('endpoint' => self::ENDPOINT, 'count' => 2, 'excerpt_length' => 200, 'source' => '', 'revision' => ''),
            get_option('rest_posts_embedder_lm_' . $match[1])
        );

        foreach (array(1, 0) as $total_pages) {
            update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
            $this->total_pages = $total_pages;
            $this->assertStringNotContainsString('embed-posts-load-more', do_shortcode('[posts_embedder]'));
        }
    }

    /** @dataProvider load_more_off_values */
    public function test_load_more_attribute_hides_the_button($value) {
        $with = do_shortcode('[posts_embedder]');
        $without = do_shortcode('[posts_embedder load_more="' . $value . '"]');
        $this->assertStringContainsString('embed-posts-load-more', $with);
        $this->assertStringNotContainsString('embed-posts-load-more', $without);
        $this->assertStringContainsString('<div class="embed-posts-wrapper">', $without);
        // Cached apart: the next feed with the button still gets it.
        $this->assertStringContainsString('embed-posts-load-more', do_shortcode('[posts_embedder load_more="yes"]'));
        $this->assertStringNotContainsString('embed-posts-load-more', do_shortcode('[posts_embedder load_more="' . $value . '"]'));
    }

    public static function load_more_off_values() {
        return array('no' => array('no'), 'NO' => array('NO'), 'false' => array('false'), 'off' => array('off'), '0' => array('0'));
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

    /** @dataProvider pages_past_the_end */
    public function test_page_beyond_the_known_end_is_the_end_of_the_list_without_a_request($page) {
        $token = $this->rendered_token();
        $response = $this->load_more(array('token' => $token, 'page' => $page));
        $this->assertSame(array('success' => true, 'data' => array('html' => '', 'has_more' => false)), $response);
        $this->assertCount(0, $this->requests, 'The number of pages is known; no request is needed.');
    }

    public static function pages_past_the_end() {
        return array('next after last' => array('4'), 'far' => array('5000'), 'huge' => array('99999999999999999999'));
    }

    public function test_shrinking_feed_ends_the_list_once_and_is_not_logged() {
        $token = $this->rendered_token();
        // The remote lost a page after the button was rendered with three.
        $this->total_pages = 2;
        $log = $this->capture_error_log();

        $response = $this->load_more(array('token' => $token, 'page' => '3'));
        $this->assertSame(array('success' => true, 'data' => array('html' => '', 'has_more' => false)), $response);
        // Page 3, then page 1 for the remote's new page count.
        $this->assertSame(array('3', '1'), array_column($this->requests, 'page'));

        // The new end is remembered: retries and later pages cost nothing.
        $this->load_more(array('token' => $token, 'page' => '3'));
        $this->load_more(array('token' => $token, 'page' => '4'));
        $this->assertCount(2, $this->requests);
        $this->assertSame('', file_get_contents($log), 'The end of a feed is not an error.');

        // Pages that still exist keep working.
        $second = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertStringContainsString('<h3>Remote post page 2</h3>', $second['data']['html']);
    }

    public function test_unknown_page_count_is_learned_from_the_remote_not_from_the_request() {
        $token = $this->rendered_token();
        // As after a cache expiry, "Clear All Cache Now" or an eviction.
        delete_transient(\RestPostsEmbedder\Shortcodes\page_bound_key(self::ENDPOINT, 2));

        $this->assertFalse($this->load_more(array('token' => $token, 'page' => '50'))['data']['has_more']);
        $this->assertSame(array('1'), array_column($this->requests, 'page'), 'Only page 1, for the page count.');

        // Walking down from a huge page number must not reach the remote.
        // Before this fix each step stored "page - 1" as the count and fetched.
        foreach (array('1000000', '999999', '999998', '51', '50', '4') as $page) {
            $this->assertFalse($this->load_more(array('token' => $token, 'page' => $page))['data']['has_more']);
        }
        $this->assertCount(1, $this->requests, 'Pages past the remote page count must not reach the remote feed.');
    }

    public function test_page_count_lookup_failure_is_not_retried_for_a_minute() {
        $token = $this->rendered_token();
        delete_transient(\RestPostsEmbedder\Shortcodes\page_bound_key(self::ENDPOINT, 2));
        $this->failure = new WP_Error('http_request_failed', 'Operation timed out');

        foreach (array('2', '3', '900') as $page) {
            $response = $this->load_more(array('token' => $token, 'page' => $page));
            $this->assertFalse($response['success']);
            $this->assertSame('Could not load more posts. Please try again.', $response['data']['message']);
        }
        $this->assertCount(1, $this->requests, 'A remote that is down is asked once a minute at most.');
    }

    public function test_empty_page_is_the_end_of_the_list() {
        $token = $this->rendered_token();
        $this->failure = $this->response(200, '[]');
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertSame(array('success' => true, 'data' => array('html' => '', 'has_more' => false)), $response);
        $count = count($this->requests);
        // Even when the remote still claims three pages, repeating the click is free.
        $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertCount($count, $this->requests);
    }

    /** Send error_log() to a file for the rest of the test and return its path. */
    private function capture_error_log() {
        $file = tempnam(sys_get_temp_dir(), 'rpe-log');
        $previous = ini_set('error_log', $file);
        $this->cleanup[] = function () use ($file, $previous) {
            ini_set('error_log', (string) $previous);
            unlink($file);
        };
        return $file;
    }

    private function save_source($overrides = array()) {
        update_option('rest_posts_embedder_sources', array('my-feed' => array_merge(array(
            'id' => 'my-feed', 'name' => 'My feed', 'endpoint' => self::ENDPOINT,
            'count' => 2, 'excerpt_length' => 200, 'enabled' => true,
        ), $overrides)));
    }

    /** Render a named source and return its button's token. */
    private function source_token() {
        $this->save_source();
        $html = do_shortcode('[posts_embedder source="my-feed"]');
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $html, $match), 'Load More button missing.');
        $this->requests = array();
        return $match[1];
    }

    private function assert_expired($token) {
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assert_rejected($response, 'This feed has expired. Please refresh the page.');
        $this->assertTrue($response['data']['expired']);
        $this->assertCount(0, $this->requests, 'A retired feed must not be fetched.');
        // Kept, so the button works again if the change is undone.
        $this->assertIsArray(get_option('rest_posts_embedder_lm_' . $token));
    }

    public function test_source_button_records_its_source() {
        $token = $this->source_token();
        $context = get_option('rest_posts_embedder_lm_' . $token);
        $this->assertSame('my-feed', $context['source']);
        $this->assertSame(md5(self::ENDPOINT . '|2'), $context['revision']);
        $this->assertTrue($this->load_more(array('token' => $token, 'page' => '2'))['success']);
        // The same endpoint outside a source is a different button.
        $this->assertNotSame($token, $this->rendered_token());
    }

    /** @dataProvider retiring_changes */
    public function test_button_of_a_changed_or_retired_source_expires_without_a_request($change) {
        $token = $this->source_token();
        if ('delete' === $change) {
            update_option('rest_posts_embedder_sources', array());
        } else {
            $this->save_source($change);
        }
        $this->assert_expired($token);

        // Undoing the change brings the same button back: it is still in the
        // cached HTML, which the shortcode serves without writing the context.
        $this->save_source();
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertStringContainsString('<h3>Remote post page 2</h3>', $response['data']['html']);
    }

    public static function retiring_changes() {
        return array(
            'disabled' => array(array('enabled' => false)),
            'deleted' => array('delete'),
            'endpoint edited' => array(array('endpoint' => 'https://93.184.216.34/other/wp-json/wp/v2/posts')),
            'batch size edited' => array(array('count' => 4)),
        );
    }

    public function test_button_survives_changes_that_do_not_alter_what_it_fetches() {
        $token = $this->source_token();
        $this->save_source(array('name' => 'Renamed', 'excerpt_length' => 50));
        $response = $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertTrue($response['success']);
        $this->assertStringContainsString('<h3>Remote post page 2</h3>', $response['data']['html']);
    }

    /** A context as 3.8.0 stored it: no source, no revision. */
    private function legacy_token($endpoint, $extra = array()) {
        $token = md5('legacy' . $endpoint . wp_json_encode($extra));
        update_option('rest_posts_embedder_lm_' . $token, array_merge(
            array('endpoint' => $endpoint, 'count' => 2, 'excerpt_length' => 200),
            $extra
        ), false);
        return $token;
    }

    public function test_context_from_an_earlier_version_works_while_its_feed_is_still_configured() {
        // Matches the default endpoint option.
        $token = $this->legacy_token(self::ENDPOINT);
        $this->assertTrue($this->load_more(array('token' => $token, 'page' => '2'))['success']);

        // Matches an enabled source.
        $other = 'https://93.184.216.34/other/wp-json/wp/v2/posts';
        $this->save_source(array('endpoint' => $other));
        $token = $this->legacy_token($other);
        $this->assertTrue($this->load_more(array('token' => $token, 'page' => '2'))['success']);
        // Nothing is added to a context that stays valid.
        $this->assertSame(
            array('endpoint' => $other, 'count' => 2, 'excerpt_length' => 200),
            get_option('rest_posts_embedder_lm_' . $token)
        );
    }

    /** @dataProvider unmatched_sources */
    public function test_unmatched_context_from_an_earlier_version_expires_at_once($source) {
        $retired = 'https://93.184.216.34/retired/wp-json/wp/v2/posts';
        if (null !== $source) {
            $this->save_source(array_merge(array('endpoint' => $retired), $source));
        }
        $this->assert_expired($this->legacy_token($retired));
    }

    public static function unmatched_sources() {
        return array(
            'no such feed' => array(null),
            'source disabled' => array(array('enabled' => false)),
            'source has another batch size' => array(array('count' => 4)),
            'source has another endpoint' => array(array('endpoint' => 'https://93.184.216.34/moved/wp-json/wp/v2/posts')),
        );
    }

    public function test_buttons_work_on_a_site_without_stored_defaults() {
        // As on a network site the activation hook never ran for: the demo
        // source exists only as a default. Its hostname would need DNS, so the
        // feed is sent to the fixture.
        delete_option('rest_posts_embedder_sources');
        delete_option('embed_posts_endpoint');
        add_filter('rest_posts_embedder_endpoint', function ($endpoint) {
            return REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT === $endpoint ? self::ENDPOINT : $endpoint;
        });

        $html = do_shortcode('[posts_embedder source="default"]');
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $html, $match), 'Load More button missing.');
        $this->assertSame('default', get_option('rest_posts_embedder_lm_' . $match[1])['source']);
        $response = $this->load_more(array('token' => $match[1], 'page' => '2'));
        $this->assertTrue($response['success'], wp_json_encode($response));
        $this->assertStringContainsString('<h3>Remote post page 2</h3>', $response['data']['html']);

        // A button from 3.8.0 for the demo feed matches the default endpoint.
        $this->assertTrue(\RestPostsEmbedder\Shortcodes\is_load_more_context_current(
            array('endpoint' => REST_POSTS_EMBEDDER_LEGACY_DEMO_ENDPOINT, 'count' => 5, 'excerpt_length' => 200)
        ));
    }

    /** @dataProvider logged_failures */
    public function test_failed_requests_do_not_log_the_query_string($failure) {
        update_option('embed_posts_endpoint', self::ENDPOINT . '?api_key=SECRET-KEY&signature=SECRET-SIG');
        $log = $this->capture_error_log();
        $this->failure = $failure;
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('Unable to fetch posts.', $html);

        $logged = file_get_contents($log);
        $this->assertStringContainsString('Endpoint: https://93.184.216.34/wp-json/wp/v2/posts?[redacted]', $logged);
        $this->assertStringNotContainsString('SECRET', $logged);
        $this->assertStringNotContainsString('api_key', $logged);
    }

    public function logged_failures() {
        return array(
            'transport error' => array(new WP_Error('http_request_failed', 'Operation timed out')),
            'HTTP status' => array(array('headers' => array(), 'body' => '', 'response' => array('code' => 503, 'message' => ''), 'cookies' => array())),
        );
    }

    /** @dataProvider urls_to_redact */
    public function test_redacted_url_keeps_only_scheme_host_port_and_path($url, $expected) {
        $this->assertSame($expected, \RestPostsEmbedder\Shortcodes\redact_url($url));
    }

    public static function urls_to_redact() {
        return array(
            'plain' => array('https://example.org/wp-json/wp/v2/posts', 'https://example.org/wp-json/wp/v2/posts'),
            'query' => array('https://example.org/wp-json/wp/v2/posts?key=abc&lang=en', 'https://example.org/wp-json/wp/v2/posts?[redacted]'),
            'credentials' => array('https://user:pass@example.org:8443/feed?x=1#frag', 'https://example.org:8443/feed?[redacted]'),
            'no path' => array('http://example.org', 'http://example.org'),
            'not a URL' => array('not a url', '[unparseable URL]'),
            'empty' => array('', '[unparseable URL]'),
            'array' => array(array('https://example.org'), '[unparseable URL]'),
        );
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

    /** The suite loads the plugin from outside wp-content/plugins; point WordPress at its bundled catalogs. */
    private function use_bundled_catalogs() {
        add_filter('load_textdomain_mofile', function ($mofile, $domain) {
            return 'restpostsembedder' === $domain ? dirname(__DIR__) . '/languages/' . basename($mofile) : $mofile;
        }, 10, 2);
        add_action('change_locale', function ($locale) {
            unload_textdomain('restpostsembedder');
            load_textdomain('restpostsembedder', "restpostsembedder-{$locale}.mo", $locale);
        });
    }

    public function test_appended_posts_use_the_locale_of_the_page_not_of_the_ajax_request() {
        $this->use_bundled_catalogs();
        $token = $this->rendered_token();
        $this->assertSame('en_US', determine_locale());

        $spanish = $this->load_more(array('token' => $token, 'page' => '2', 'locale' => 'es_ES'));
        $this->assertTrue($spanish['success']);
        $this->assertStringContainsString('Leer más...', $spanish['data']['html']);
        // The date format comes from the plugin's catalog. Month names are core's,
        // and the test suite's es_ES core catalog does not translate them.
        $this->assertStringContainsString('15 de ', $spanish['data']['html']);
        $this->assertStringNotContainsString('15th of', $spanish['data']['html']);
        // The request's own locale is back in place afterwards.
        $this->assertSame('en_US', determine_locale());
        $this->assertSame('Read more...', __('Read more...', 'restpostsembedder'));

        // The same page for an English page is a separate cache entry.
        $english = $this->load_more(array('token' => $token, 'page' => '2', 'locale' => 'en_US'));
        $this->assertStringContainsString('Read more...', $english['data']['html']);
        $this->assertStringContainsString('15th of January 2026', $english['data']['html']);
        $this->assertCount(2, $this->requests);

        // Both are now cached.
        $this->assertSame($spanish, $this->load_more(array('token' => $token, 'page' => '2', 'locale' => 'es_ES')));
        $this->assertSame($english, $this->load_more(array('token' => $token, 'page' => '2')));
        $this->assertCount(2, $this->requests);
    }

    /** @dataProvider locales_not_installed */
    public function test_locale_that_is_not_installed_falls_back_to_the_request_locale($locale) {
        $token = $this->rendered_token();
        $first = $this->load_more(array('token' => $token, 'page' => '2', 'locale' => $locale));
        $this->assertStringContainsString('Read more...', $first['data']['html']);
        // No cache entry of its own: a visitor cannot multiply entries with made-up locales.
        $this->load_more(array('token' => $token, 'page' => '2'));
        $this->assertCount(1, $this->requests);
    }

    public static function locales_not_installed() {
        return array(
            'unknown' => array('xx_YY'),
            'path traversal' => array('../../wp-config'),
            'markup' => array('<script>alert(1)</script>'),
            'very long' => array(str_repeat('a', 5000)),
        );
    }
}
