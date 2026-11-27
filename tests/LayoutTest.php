<?php

use function RestPostsEmbedder\Shortcodes\get_layouts;
use function RestPostsEmbedder\Shortcodes\render_articles;
use function RestPostsEmbedder\Shortcodes\resolve_layout;
use function RestPostsEmbedder\Admin\sanitize_layout_setting;

/** Card layouts: Classic markup, how a feed picks its layout, and Load More. */
class LayoutTest extends WP_Ajax_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';
    private const ACTION = 'rest_posts_embedder_load_more';

    private $requests;

    public function set_up() {
        parent::set_up();
        remove_action('admin_init', '_maybe_update_core');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('admin_init', '_maybe_update_themes');
        $this->requests = 0;
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 2);
        update_option('embed_posts_excerpt_length', 40);
        update_option('timezone_string', 'Europe/Madrid');
        delete_option('embed_posts_layout');
        delete_option('rest_posts_embedder_sources');
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        remove_all_filters('rest_posts_embedder_layouts');
        remove_all_filters('rest_posts_embedder_post_html');
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

    private function register_test_layout($id = 'test') {
        add_filter('rest_posts_embedder_layouts', function ($layouts) use ($id) {
            $layouts[$id] = array('label' => 'Test', 'template' => __DIR__ . '/fixtures/layout-test.php');
            return $layouts;
        });
    }

    private function save_source($layout) {
        update_option('rest_posts_embedder_sources', array('my-feed' => array(
            'id' => 'my-feed', 'name' => 'My feed', 'endpoint' => self::ENDPOINT,
            'count' => 2, 'excerpt_length' => 40, 'layout' => $layout, 'enabled' => true,
        )));
    }

    private function token($html) {
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $html, $match), 'Load More button missing.');
        return $match[1];
    }

    private function load_more($token) {
        $_POST = array('token' => $token, 'page' => '2', 'action' => self::ACTION);
        $_REQUEST = $_POST;
        $this->_last_response = '';
        try {
            // The die handler closes this buffer and keeps what it holds.
            ob_start();
            do_action('wp_ajax_nopriv_' . self::ACTION);
            ob_end_clean();
            $this->fail('The handler must end with wp_die().');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_*() printed its JSON and called wp_die().
        }
        $response = json_decode($this->_last_response, true);
        $this->assertIsArray($response);
        return $response;
    }

    public function test_classic_markup_is_unchanged_from_3_9_0() {
        // Captured from 3.9.0; the container gained only its layout class.
        $this->assertSame(
            file_get_contents(__DIR__ . '/fixtures/classic-feed.html'),
            do_shortcode('[posts_embedder]')
        );
        $posts = json_decode(file_get_contents(__DIR__ . '/fixtures/feed.json'));
        $this->assertSame(
            file_get_contents(__DIR__ . '/fixtures/classic-articles-unlimited.html'),
            render_articles($posts, 0)
        );
    }

    public function test_classic_button_keeps_its_3_9_0_token_and_context() {
        $html = do_shortcode('[posts_embedder]');
        $token = $this->token($html);
        $this->assertSame(md5('v3_||' . self::ENDPOINT . '|2|40'), $token);
        $this->assertSame(
            array('endpoint' => self::ENDPOINT, 'count' => 2, 'excerpt_length' => 40, 'source' => '', 'revision' => ''),
            get_option('rest_posts_embedder_lm_' . $token)
        );
    }

    public function test_classic_is_the_only_layout_out_of_the_box() {
        $this->assertSame(array('classic'), array_keys(get_layouts()));
        $this->assertSame('classic', resolve_layout());
    }

    /** @dataProvider layout_choices */
    public function test_layout_comes_from_shortcode_then_source_then_site($site, $source, $attribute, $expected) {
        $this->register_test_layout();
        update_option('embed_posts_layout', $site);
        $this->save_source($source);
        $html = do_shortcode('[posts_embedder source="my-feed" layout="' . $attribute . '"]');
        $this->assertStringContainsString('embed-posts-layout-' . $expected . '"', $html);
        $this->assertSame('test' === $expected ? 2 : 0, substr_count($html, 'class="test-card"'));
    }

    public static function layout_choices() {
        return array(
            'nothing chosen' => array('', '', '', 'classic'),
            'site setting' => array('test', '', '', 'test'),
            'source over site' => array('test', 'classic', '', 'classic'),
            'shortcode over source' => array('classic', 'classic', 'test', 'test'),
            'shortcode over site' => array('test', '', 'classic', 'classic'),
            'unknown shortcode value falls back to source' => array('classic', 'test', 'nope', 'test'),
            'unregistered site setting' => array('gone', '', '', 'classic'),
            'attribute is case-insensitive' => array('', '', 'TEST', 'test'),
        );
    }

    public function test_template_receives_card_data_and_nothing_else() {
        $this->register_test_layout();
        $html = do_shortcode('[posts_embedder layout="test"]');
        $this->assertStringContainsString('data-scope="template,card"', $html, 'Only $card (and the path) may be in scope.');
        $this->assertStringContainsString('<h4>Caf&eacute; &amp; &lt;em&gt;tips&lt;/em&gt;</h4>', $html);
        $this->assertStringContainsString('<time data-ts="1772965800">8th of March 2026</time>', $html);
        $this->assertStringContainsString('<a href="https://example.org/author/ana/" target="_blank" rel="noopener noreferrer">Ana López</a>', $html);
        $this->assertStringContainsString('<img src="https://example.org/uploads/cafe.jpg"', $html);
        $this->assertStringContainsString('<p>Alpha beta gamma delta epsilon zeta eta…</p>', $html);
        $this->assertStringContainsString('<a href="https://example.org/cafe-tips/">Read more...</a><i>101</i>', $html);
        // The post without a date or image.
        $this->assertStringContainsString('<h4>Untitled</h4><time data-ts="">', $html);
        $this->assertStringContainsString('<span class="author">Unknown Author</span><img src="" alt="Untitled">', $html);
    }

    public function test_each_layout_is_cached_separately() {
        $this->register_test_layout();
        $classic = do_shortcode('[posts_embedder]');
        $test = do_shortcode('[posts_embedder layout="test"]');
        $this->assertNotSame($classic, $test);
        $this->assertSame(2, $this->requests);
        $this->assertSame($test, do_shortcode('[posts_embedder layout="test"]'));
        $this->assertSame($classic, do_shortcode('[posts_embedder]'));
        $this->assertSame(2, $this->requests);
    }

    public function test_load_more_appends_posts_in_the_feed_layout() {
        $this->register_test_layout();
        $classic_token = $this->token(do_shortcode('[posts_embedder]'));
        $token = $this->token(do_shortcode('[posts_embedder layout="test"]'));
        $this->assertNotSame($classic_token, $token);
        $this->assertSame('test', get_option('rest_posts_embedder_lm_' . $token)['layout']);

        $response = $this->load_more($token);
        $this->assertTrue($response['success']);
        $this->assertSame(2, substr_count($response['data']['html'], 'class="test-card"'));

        $response = $this->load_more($classic_token);
        $this->assertTrue($response['success']);
        $this->assertStringContainsString('<article class="embed-posts">', $response['data']['html']);
        $this->assertStringNotContainsString('test-card', $response['data']['html']);
    }

    public function test_button_of_an_unregistered_layout_falls_back_to_the_site_layout() {
        $this->register_test_layout();
        $token = $this->token(do_shortcode('[posts_embedder layout="test"]'));
        remove_all_filters('rest_posts_embedder_layouts');

        $response = $this->load_more($token);
        $this->assertTrue($response['success']);
        $this->assertStringContainsString('<article class="embed-posts">', $response['data']['html']);
    }

    /** @dataProvider broken_registrations */
    public function test_broken_registrations_are_ignored_and_classic_stays($layouts) {
        add_filter('rest_posts_embedder_layouts', function () use ($layouts) {
            return $layouts;
        });
        $this->assertSame(array('classic'), array_keys(get_layouts()));
        $this->assertStringContainsString('<article class="embed-posts">', do_shortcode('[posts_embedder layout="bad"]'));
    }

    public static function broken_registrations() {
        $template = __DIR__ . '/fixtures/layout-test.php';
        return array(
            'not an array' => array('classic'),
            'classic removed' => array(array()),
            'missing template file' => array(array('bad' => array('label' => 'Bad', 'template' => __DIR__ . '/fixtures/missing.php'))),
            'no template' => array(array('bad' => array('label' => 'Bad'))),
            'ID with uppercase or spaces' => array(array('Bad' => array('template' => $template), 'b a d' => array('template' => $template))),
            'numeric ID' => array(array(7 => array('template' => $template))),
        );
    }

    public function test_layout_without_label_is_listed_by_its_id() {
        add_filter('rest_posts_embedder_layouts', function ($layouts) {
            $layouts['plain'] = array('template' => __DIR__ . '/fixtures/layout-test.php');
            return $layouts;
        });
        $this->assertSame('plain', get_layouts()['plain']['label']);
    }

    public function test_post_html_filter_receives_the_layout() {
        $this->register_test_layout();
        $seen = array();
        add_filter('rest_posts_embedder_post_html', function ($html, $post, $layout = null) use (&$seen) {
            $seen[] = $layout;
            return $html;
        }, 10, 3);
        do_shortcode('[posts_embedder layout="test"]');
        $this->assertSame(array('test', 'test'), $seen);
    }

    public function test_site_setting_accepts_only_registered_layouts() {
        $this->register_test_layout();
        $this->assertSame('test', sanitize_layout_setting('test'));
        $this->assertSame('classic', sanitize_layout_setting('gone'));
        $this->assertSame('classic', sanitize_layout_setting(array('test')));
        $this->assertSame('classic', sanitize_layout_setting(''));
    }

    public function test_saved_source_keeps_only_a_registered_layout() {
        $this->register_test_layout();
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        foreach (array('test' => 'test', 'gone' => '', '' => '') as $submitted => $stored) {
            $_POST = array(
                'save_source' => '1',
                'source_nonce' => wp_create_nonce('manage_rest_embedder_source'),
                'source_name' => 'Feed ' . $submitted,
                'source_endpoint' => self::ENDPOINT,
                'source_count' => '2',
                'source_layout' => $submitted,
            );
            \RestPostsEmbedder\Admin\handle_source_actions();
            $sources = get_option('rest_posts_embedder_sources');
            $saved = end($sources);
            $this->assertSame($stored, $saved['layout'], "Submitted '$submitted'.");
        }
        $_POST = array();
    }
}
