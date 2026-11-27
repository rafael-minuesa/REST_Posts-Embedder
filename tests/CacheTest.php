<?php

use function RestPostsEmbedder\Admin\get_cache_expiration;
use function RestPostsEmbedder\Admin\sanitize_cache_custom_hours;

/** What the rendered-HTML cache is keyed on, when it is read, and how long it lives. */
class CacheTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';
    // The .invalid TLD never resolves (RFC 6761), like a hostname during a DNS outage.
    private const UNRESOLVABLE = 'https://feed.invalid/wp-json/wp/v2/posts';

    private $requests;

    public function set_up() {
        parent::set_up();
        $this->requests = 0;
        update_option('embed_posts_endpoint', self::ENDPOINT);
        update_option('embed_posts_count', 5);
        update_option('embed_posts_excerpt_length', 200);
        // Do not let a persistent object cache hide requests between test cases.
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
        // The suite loads the plugin from outside wp-content/plugins, where
        // WordPress cannot find its bundled catalogs by itself.
        add_filter('load_textdomain_mofile', array($this, 'bundled_catalog'), 10, 2);
        add_action('change_locale', array($this, 'reload_catalog'));
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        remove_filter('load_textdomain_mofile', array($this, 'bundled_catalog'), 10);
        remove_action('change_locale', array($this, 'reload_catalog'));
        while (restore_previous_locale()) {
            continue;
        }
        unload_textdomain('restpostsembedder');
        parent::tear_down();
    }

    public function bundled_catalog($mofile, $domain) {
        return 'restpostsembedder' === $domain ? dirname(__DIR__) . '/languages/' . basename($mofile) : $mofile;
    }

    public function reload_catalog($locale) {
        unload_textdomain('restpostsembedder');
        load_textdomain('restpostsembedder', "restpostsembedder-{$locale}.mo", $locale);
    }

    public function intercept_request($preempt, $args, $url) {
        $this->assertContains(wp_parse_url($url, PHP_URL_HOST), array('93.184.216.34', 'feed.invalid'), 'Unexpected HTTP request.');
        $this->requests++;
        $post = (object) array(
            'title' => (object) array('rendered' => 'A remote post'),
            'link' => 'https://example.org/a-post',
            'modified' => '2026-01-15T12:00:00',
            'modified_gmt' => '2026-01-15T12:00:00',
            'excerpt' => (object) array('rendered' => '<p>Alpha beta gamma delta.</p>'),
        );
        return array(
            'headers' => array('x-wp-totalpages' => '3'),
            'body' => wp_json_encode(array($post)),
            'response' => array('code' => 200, 'message' => ''),
            'cookies' => array(),
        );
    }

    public function test_cached_html_is_not_reused_for_another_locale() {
        $english = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('Read more...', $english);
        $this->assertStringContainsString('data-locale="en_US"', $english);

        $this->assertTrue(switch_to_locale('es_ES'));
        $spanish = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('Leer más...', $spanish);
        $this->assertStringNotContainsString('Read more...', $spanish);
        $this->assertStringContainsString('Cargar más', $spanish);
        $this->assertStringContainsString('data-locale="es_ES"', $spanish);
        $this->assertSame(2, $this->requests);

        // Each locale keeps its own entry.
        $this->assertSame($spanish, do_shortcode('[posts_embedder]'));
        restore_previous_locale();
        $this->assertSame($english, do_shortcode('[posts_embedder]'));
        $this->assertSame(2, $this->requests);
    }

    public function test_cached_feed_is_served_while_its_hostname_does_not_resolve() {
        // While the feed's host is this site's own, WordPress accepts it without a DNS lookup.
        update_option('home', 'https://feed.invalid');
        update_option('embed_posts_endpoint', self::UNRESOLVABLE);
        $this->assertNotFalse(wp_http_validate_url(self::UNRESOLVABLE));
        $cached = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('A remote post', $cached);
        $this->assertSame(1, $this->requests);

        // From here on the hostname has to resolve, and cannot.
        update_option('home', 'http://example.org');
        $this->assertFalse(wp_http_validate_url(self::UNRESOLVABLE));

        $this->assertSame($cached, do_shortcode('[posts_embedder]'));
        $this->assertSame(1, $this->requests);
    }

    public function test_uncached_feed_with_unresolvable_hostname_is_rejected_without_http() {
        update_option('embed_posts_endpoint', self::UNRESOLVABLE);
        $html = do_shortcode('[posts_embedder]');
        $this->assertStringContainsString('Invalid endpoint URL.', $html);
        $this->assertSame(0, $this->requests);

        // The rejection is not cached: the feed works again once the name resolves.
        update_option('home', 'https://feed.invalid');
        $this->assertStringContainsString('A remote post', do_shortcode('[posts_embedder]'));
        $this->assertSame(1, $this->requests);
    }

    /** @dataProvider custom_hours */
    public function test_custom_cache_duration_stays_within_one_hour_and_one_week($stored, $hours) {
        update_option('embed_posts_cache_expiration', 'custom');
        update_option('embed_posts_cache_custom_hours', $stored);
        $this->assertSame($hours * HOUR_IN_SECONDS, get_cache_expiration());
        $this->assertSame($hours, sanitize_cache_custom_hours($stored));
    }

    public static function custom_hours() {
        return array(
            'blank field' => array('', 1),
            'zero saved by an earlier version' => array(0, 1),
            'negative' => array('-5', 5),
            'text' => array('soon', 1),
            'lower bound' => array('1', 1),
            'in range' => array('36', 36),
            'upper bound' => array('168', 168),
            'above a week' => array('500', 168),
        );
    }

    public function test_saving_a_blank_custom_duration_stores_one_hour() {
        \RestPostsEmbedder\Admin\embed_posts_cache_init();
        update_option('embed_posts_cache_custom_hours', '');
        $this->assertSame(1, (int) get_option('embed_posts_cache_custom_hours'));
        update_option('embed_posts_cache_custom_hours', '900');
        $this->assertSame(168, (int) get_option('embed_posts_cache_custom_hours'));
    }

    public function test_custom_duration_never_creates_an_entry_without_expiration() {
        update_option('embed_posts_cache_expiration', 'custom');
        update_option('embed_posts_cache_custom_hours', '');
        $expirations = array();
        add_filter('expiration_of_transient_' . $this->first_page_key(), function ($expiration) use (&$expirations) {
            $expirations[] = $expiration;
            return $expiration;
        });
        do_shortcode('[posts_embedder]');
        $this->assertSame(array(HOUR_IN_SECONDS), $expirations);
    }

    private function first_page_key() {
        return 'rest_posts_embedder_' . md5('v2_' . get_option('rest_posts_embedder_cache_generation') . '_' . self::ENDPOINT . '_5_200_' . determine_locale() . '__');
    }
}
