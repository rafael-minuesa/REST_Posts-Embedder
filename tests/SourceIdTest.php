<?php

use function RestPostsEmbedder\Admin\generate_source_id;
use function RestPostsEmbedder\Admin\handle_source_actions;
use function RestPostsEmbedder\Shortcodes\resolve_source_id;

/** Source IDs for non-Latin names, and IDs stored by earlier versions. */
class SourceIdTest extends WP_UnitTestCase {
    // A public IP avoids DNS lookups in wp_http_validate_url(). HTTP is intercepted.
    private const ENDPOINT = 'https://93.184.216.34/wp-json/wp/v2/posts';
    // What 3.8.0 generated for a source named 東京.
    private const LEGACY_ID = '%e6%9d%b1%e4%ba%ac_1700000000';

    private $requests;

    public function set_up() {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/template.php';
        $this->requests = 0;
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        delete_option('rest_posts_embedder_sources');
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        $_POST = array();
        parent::tear_down();
    }

    public function intercept_request($preempt, $args, $url) {
        $this->assertSame('93.184.216.34', wp_parse_url($url, PHP_URL_HOST), 'Unexpected HTTP request.');
        $this->requests++;
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

    private function source($id, $name = 'Feed') {
        return array('id' => $id, 'name' => $name, 'endpoint' => self::ENDPOINT, 'count' => 3, 'excerpt_length' => 200, 'enabled' => true);
    }

    private function sources() {
        return get_option('rest_posts_embedder_sources', array());
    }

    /** Submit the source form as the settings page does. */
    private function submit($fields) {
        $_POST = wp_slash(array_merge(array(
            'source_nonce' => wp_create_nonce('manage_rest_embedder_source'),
        ), $fields));
        handle_source_actions();
        $_POST = array();
    }

    private function save($name, $source_id = '') {
        $this->submit(array(
            'save_source' => '1', 'source_id' => $source_id, 'source_name' => $name,
            'source_endpoint' => self::ENDPOINT, 'source_count' => '3',
            'source_excerpt_length' => '200', 'source_enabled' => '1',
        ));
    }

    /** @dataProvider source_names */
    public function test_generated_id_survives_every_reader($name, $pattern) {
        $id = generate_source_id($name, array());
        $this->assertMatchesRegularExpression($pattern, $id);
        // Shortcode lookup, URLs and form fields all leave it unchanged.
        $this->assertSame($id, sanitize_key($id));
        $this->assertSame($id, sanitize_title($id));
        $this->assertSame($id, sanitize_text_field($id));
        $this->assertSame($id, rawurlencode($id));
    }

    public static function source_names() {
        return array(
            'Japanese' => array('東京', '/^feed_\d+$/'),
            'Arabic' => array('أخبار', '/^feed_\d+$/'),
            'Cyrillic' => array('Новости', '/^feed_\d+$/'),
            'accents' => array('Café Niños', '/^cafe-ninos_\d+$/'),
            'mixed scripts' => array('東京 News 2', '/^news-2_\d+$/'),
            'plain' => array('My Feed', '/^my-feed_\d+$/'),
            'punctuation only' => array('!!! ???', '/^feed_\d+$/'),
            'percent signs' => array('50% off %e6', '/^50-off-e6_\d+$/'),
            'very long' => array(str_repeat('long name ', 40), '/^[a-z-]{1,60}_\d+$/'),
        );
    }

    public function test_generated_id_never_replaces_an_existing_source() {
        $first = generate_source_id('東京', array());
        $second = generate_source_id('大阪', array($first => $this->source($first)));
        $third = generate_source_id('京都', array($first => array(), $second => array()));
        $this->assertCount(3, array_unique(array($first, $second, $third)));
    }

    public function test_source_with_a_non_latin_name_can_be_saved_used_edited_and_deleted() {
        $this->save('東京');
        $sources = $this->sources();
        $this->assertCount(1, $sources);
        $id = (string) key($sources);
        $this->assertMatchesRegularExpression('/^feed_\d+$/', $id);
        $this->assertSame($id, $sources[$id]['id']);
        $this->assertSame('東京', $sources[$id]['name']);

        // The shortcode shown for it works.
        $this->assertStringContainsString('A remote post', do_shortcode('[posts_embedder source="' . $id . '"]'));

        // A second one saved in the same second gets its own ID.
        $this->save('大阪');
        $this->assertCount(2, $this->sources());

        // Editing updates it in place.
        $this->save('東京都', $id);
        $this->assertCount(2, $this->sources());
        $this->assertSame('東京都', $this->sources()[$id]['name']);

        $this->submit(array('delete_source' => '1', 'source_id' => $id));
        $this->assertCount(1, $this->sources());
        $this->assertArrayNotHasKey($id, $this->sources());
    }

    /** @dataProvider forms_of_a_stored_id */
    public function test_id_stored_by_an_earlier_version_is_found_in_every_form($requested) {
        $sources = array('other_1' => $this->source('other_1'), self::LEGACY_ID => $this->source(self::LEGACY_ID, '東京'));
        $this->assertSame(self::LEGACY_ID, resolve_source_id($sources, $requested));
    }

    public static function forms_of_a_stored_id() {
        return array(
            'as stored and as shown in its shortcode' => array(self::LEGACY_ID),
            'uppercase escapes' => array('%E6%9D%B1%E4%BA%AC_1700000000'),
            'percent signs stripped, as sanitize_key() left it' => array('e69db1e4baac_1700000000'),
            'URL-decoded, as a query string delivers it' => array('東京_1700000000'),
        );
    }

    public function test_shortcode_of_a_source_stored_by_an_earlier_version_renders() {
        update_option('rest_posts_embedder_sources', array(self::LEGACY_ID => $this->source(self::LEGACY_ID, '東京')));
        $html = do_shortcode('[posts_embedder source="' . self::LEGACY_ID . '"]');
        $this->assertStringContainsString('A remote post', $html);
        $this->assertStringNotContainsString('not found', $html);
        $this->assertSame(1, $this->requests);
    }

    public function test_source_stored_by_an_earlier_version_can_be_edited_and_deleted() {
        update_option('rest_posts_embedder_sources', array(self::LEGACY_ID => $this->source(self::LEGACY_ID, '東京')));

        $this->save('Tokyo', self::LEGACY_ID);
        $sources = $this->sources();
        $this->assertSame(array(self::LEGACY_ID), array_map('strval', array_keys($sources)), 'Editing must not create a second source.');
        $this->assertSame('Tokyo', $sources[self::LEGACY_ID]['name']);
        $this->assertSame(self::LEGACY_ID, $sources[self::LEGACY_ID]['id']);

        $this->submit(array('delete_source' => '1', 'source_id' => self::LEGACY_ID));
        $this->assertSame(array(), $this->sources());
    }

    /** @dataProvider unknown_ids */
    public function test_unknown_id_matches_nothing($requested) {
        $sources = array('my-feed' => $this->source('my-feed'), self::LEGACY_ID => $this->source(self::LEGACY_ID));
        $this->assertSame('', resolve_source_id($sources, $requested));
    }

    public static function unknown_ids() {
        return array(
            'empty' => array(''),
            'other name' => array('your-feed'),
            'prefix' => array('my'),
            'other escapes' => array('%e6%9d%b1_1700000000'),
            'only symbols' => array('%%%'),
            'array' => array(array('my-feed')),
            'null' => array(null),
        );
    }

    public function test_ids_that_worked_before_still_resolve_the_same_way() {
        $sources = array('my-feed' => $this->source('my-feed'), 'default' => $this->source('default'), 'gibraltar_1700000000' => $this->source('gibraltar_1700000000'));
        $this->assertSame('my-feed', resolve_source_id($sources, 'my-feed'));
        $this->assertSame('my-feed', resolve_source_id($sources, 'MY-FEED!'));
        $this->assertSame('default', resolve_source_id($sources, ' Default '));
        $this->assertSame('gibraltar_1700000000', resolve_source_id($sources, 'gibraltar_1700000000'));
    }

    public function test_saving_with_an_unknown_id_creates_a_generated_one() {
        $this->save('Injected', 'chosen-by-request');
        $ids = array_map('strval', array_keys($this->sources()));
        $this->assertCount(1, $ids);
        $this->assertMatchesRegularExpression('/^injected_\d+$/', $ids[0]);
    }
}
