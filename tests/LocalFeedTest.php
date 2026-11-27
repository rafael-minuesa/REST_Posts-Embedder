<?php

use function RestPostsEmbedder\Shortcodes\sanitize_local_query;

/** source="this-site": the site's own posts, through WP_Query. */
class LocalFeedTest extends WP_Ajax_UnitTestCase {
    private const ACTION = 'rest_posts_embedder_load_more';

    private $posts = array();

    public function set_up() {
        parent::set_up();
        remove_action('admin_init', '_maybe_update_core');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('admin_init', '_maybe_update_themes');
        update_option('embed_posts_excerpt_length', 200);
        update_option('timezone_string', 'Europe/Madrid');
        delete_option('embed_posts_layout');
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        wp_set_current_user(0);
        // A local feed never makes an HTTP request.
        add_filter('pre_http_request', array($this, 'no_http'), PHP_INT_MAX, 3);

        $author = self::factory()->user->create(array('display_name' => 'Ana López', 'role' => 'author'));
        $news = self::factory()->category->create(array('slug' => 'news'));
        $tips = self::factory()->category->create(array('slug' => 'tips'));
        // Five published posts, one day apart: "Post 1" is the newest.
        for ($i = 1; $i <= 5; $i++) {
            $this->posts[$i] = self::factory()->post->create(array(
                'post_title' => 'Post ' . $i,
                'post_author' => $author,
                'post_date_gmt' => sprintf('2026-03-%02d 10:30:00', 20 - $i),
                'post_date' => sprintf('2026-03-%02d 11:30:00', 20 - $i),
                'post_content' => 'Content of post ' . $i . '. Alpha beta gamma delta epsilon zeta eta theta.',
                'post_excerpt' => '',
                'post_category' => array($i <= 2 ? $news : $tips),
                'tags_input' => 3 === $i ? array('featured') : array(),
            ));
        }
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'no_http'), PHP_INT_MAX);
        $this->posts = array();
        parent::tear_down();
    }

    public function no_http($preempt, $args, $url) {
        $this->fail('Unexpected HTTP request to ' . $url);
    }

    private function titles($html) {
        preg_match_all('/<h3[^>]*>(?:<a [^>]*>)?\s*(?:<h3>)?([^<]+)</', $html, $matches);
        return array_map('trim', $matches[1]);
    }

    private function token($html) {
        $this->assertSame(1, preg_match('/data-token="([^"]*)"/', $html, $match), 'Load More button missing.');
        return $match[1];
    }

    private function load_more($token, $page) {
        $_POST = array('token' => $token, 'page' => (string) $page, 'action' => self::ACTION);
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

    public function test_feed_shows_published_posts_newest_first() {
        $html = do_shortcode('[posts_embedder source="this-site" count="3"]');
        $this->assertSame(array('Post 1', 'Post 2', 'Post 3'), $this->titles($html));
        $this->assertStringContainsString('<div class="embed-posts-container embed-posts-layout-classic">', $html);
    }

    public function test_source_id_is_case_insensitive() {
        $this->assertSame(array('Post 1'), $this->titles(do_shortcode('[posts_embedder source="This-Site" count="1"]')));
    }

    public function test_only_public_posts_are_shown() {
        self::factory()->post->create(array('post_title' => 'Draft', 'post_status' => 'draft'));
        self::factory()->post->create(array('post_title' => 'Private', 'post_status' => 'private'));
        self::factory()->post->create(array('post_title' => 'Protected', 'post_password' => 'secret'));
        self::factory()->post->create(array('post_title' => 'A page', 'post_type' => 'page'));
        $future = self::factory()->post->create(array('post_title' => 'Scheduled', 'post_status' => 'future', 'post_date' => '2099-01-01 00:00:00'));
        $this->assertSame('future', get_post_status($future));
        // Even for an administrator, who could read the drafts.
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        wp_set_current_user($admin);
        $titles = $this->titles(do_shortcode('[posts_embedder source="this-site" count="20"]'));
        $this->assertSame(array('Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5'), $titles);
    }

    public function test_sticky_posts_keep_their_date_order() {
        stick_post($this->posts[5]);
        $this->assertSame(array('Post 1', 'Post 2'), $this->titles(do_shortcode('[posts_embedder source="this-site" count="2"]')));
    }

    /** @dataProvider queries */
    public function test_query_attributes($attributes, $expected) {
        $this->assertSame($expected, $this->titles(do_shortcode('[posts_embedder source="this-site" count="20" ' . $attributes . ']')));
    }

    public static function queries() {
        return array(
            'category' => array('category="news"', array('Post 1', 'Post 2')),
            'two categories' => array('category="news, tips"', array('Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5')),
            'tag' => array('tag="featured"', array('Post 3')),
            'oldest first' => array('order="asc"', array('Post 5', 'Post 4', 'Post 3', 'Post 2', 'Post 1')),
            'by title' => array('orderby="title" order="ASC"', array('Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5')),
            'offset' => array('offset="3"', array('Post 4', 'Post 5')),
            'random order is not offered' => array('orderby="rand"', array('Post 1', 'Post 2', 'Post 3', 'Post 4', 'Post 5')),
        );
    }

    public function test_unknown_category_has_no_posts() {
        $this->assertStringContainsString('No posts found.', do_shortcode('[posts_embedder source="this-site" category="nope"]'));
    }

    /** @dataProvider raw_queries */
    public function test_query_is_sanitized($raw, $expected) {
        $this->assertSame($expected, sanitize_local_query($raw));
    }

    public static function raw_queries() {
        $defaults = array('category' => '', 'tag' => '', 'orderby' => 'date', 'order' => 'DESC', 'offset' => 0);
        return array(
            'empty' => array(array(), $defaults),
            'slugs are cleaned and deduplicated' => array(array('category' => ' News ,tips,,news', 'tag' => '<b>x</b>'), array_merge($defaults, array('category' => 'news,tips', 'tag' => 'x'))),
            'orderby outside the list' => array(array('orderby' => 'meta_value'), $defaults),
            'order in lowercase' => array(array('order' => 'asc'), array_merge($defaults, array('order' => 'ASC'))),
            'order nonsense' => array(array('order' => 'sideways'), $defaults),
            'negative offset' => array(array('offset' => '-4'), array_merge($defaults, array('offset' => 4))),
            'huge offset' => array(array('offset' => '5000'), array_merge($defaults, array('offset' => 100))),
            'non-string values' => array(array('category' => array('news'), 'orderby' => array(), 'order' => 1, 'offset' => array()), $defaults),
        );
    }

    public function test_card_shows_this_site_post_data() {
        $html = do_shortcode('[posts_embedder source="this-site" count="1" excerpt_length="30"]');
        $link = get_permalink($this->posts[1]);
        // Same tab: no target attribute anywhere.
        $this->assertStringNotContainsString('target=', $html);
        $this->assertStringContainsString('<a href="' . esc_url($link) . '">', $html);
        // Publication date in the site's timezone.
        $this->assertStringContainsString('19th of March 2026, by <a href="' . esc_url(get_author_posts_url(get_post_field('post_author', $this->posts[1]))) . '">Ana López</a>', $html);
        $this->assertStringContainsString('<p>Content of post 1. Alpha beta…</p>', $html);
    }

    public function test_grid_card_gets_iso_date_and_manual_excerpt() {
        wp_update_post(array('ID' => $this->posts[1], 'post_excerpt' => 'Hand-written <em>summary</em>.'));
        $html = do_shortcode('[posts_embedder source="this-site" count="1" layout="grid" excerpt_length="0"]');
        $this->assertStringContainsString('<time class="embed-posts-card-date" datetime="2026-03-19T11:30:00+01:00">19th of March 2026</time>', $html);
        $this->assertStringContainsString('<div class="embed-posts-card-excerpt"><p>Hand-written <em>summary</em>.</p>', $html);
    }

    public function test_title_is_escaped() {
        wp_update_post(array('ID' => $this->posts[1], 'post_title' => 'Tips <script>alert(1)</script> & tricks'));
        $html = do_shortcode('[posts_embedder source="this-site" count="1"]');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Tips alert(1) &amp; tricks', $html);
    }

    public function test_featured_image_with_srcset() {
        $attachment = self::factory()->attachment->create_object('2026/03/cafe.jpg', $this->posts[1], array('post_mime_type' => 'image/jpeg'));
        wp_update_attachment_metadata($attachment, array(
            'width' => 1600, 'height' => 1000, 'file' => '2026/03/cafe.jpg',
            'sizes' => array(
                'medium' => array('file' => 'cafe-300x188.jpg', 'width' => 300, 'height' => 188, 'mime-type' => 'image/jpeg'),
                'large' => array('file' => 'cafe-1024x640.jpg', 'width' => 1024, 'height' => 640, 'mime-type' => 'image/jpeg'),
            ),
        ));
        set_post_thumbnail($this->posts[1], $attachment);
        $html = do_shortcode('[posts_embedder source="this-site" count="1"]');
        $this->assertMatchesRegularExpression('#<img src="[^"]*/2026/03/cafe-1024x640\.jpg" srcset="[^"]*cafe-300x188\.jpg 300w[^"]*" sizes="[^"]+" alt="Post 1" loading="lazy" />#', $html);
    }

    public function test_new_posts_show_at_once() {
        $before = do_shortcode('[posts_embedder source="this-site" count="1"]');
        self::factory()->post->create(array('post_title' => 'Just published'));
        $after = do_shortcode('[posts_embedder source="this-site" count="1"]');
        $this->assertSame(array('Post 1'), $this->titles($before));
        $this->assertSame(array('Just published'), $this->titles($after));
    }

    public function test_load_more_pages_through_the_feed() {
        $html = do_shortcode('[posts_embedder source="this-site" count="2" layout="grid"]');
        $this->assertStringContainsString('data-total-pages="3"', $html);
        $token = $this->token($html);
        $context = get_option('rest_posts_embedder_lm_' . $token);
        $this->assertSame(array('category' => '', 'tag' => '', 'orderby' => 'date', 'order' => 'DESC', 'offset' => 0), $context['local']);
        $this->assertSame(array(), $context['carried'], 'Nothing to carry without a plugin narrowing the query.');

        $page2 = $this->load_more($token, 2);
        $this->assertTrue($page2['success']);
        $this->assertSame(array('Post 3', 'Post 4'), $this->titles($page2['data']['html']));
        $this->assertStringContainsString('class="embed-posts-card"', $page2['data']['html']);
        $this->assertTrue($page2['data']['has_more']);

        $page3 = $this->load_more($token, 3);
        $this->assertSame(array('Post 5'), $this->titles($page3['data']['html']));
        $this->assertFalse($page3['data']['has_more']);

        $page4 = $this->load_more($token, 4);
        $this->assertTrue($page4['success']);
        $this->assertSame(array('html' => '', 'has_more' => false), $page4['data']);
    }

    public function test_load_more_keeps_offset_and_filters() {
        $html = do_shortcode('[posts_embedder source="this-site" count="1" category="tips" offset="1"]');
        // tips has Posts 3, 4, 5; one skipped leaves two pages.
        $this->assertSame(array('Post 4'), $this->titles($html));
        $this->assertStringContainsString('data-total-pages="2"', $html);
        $page2 = $this->load_more($this->token($html), 2);
        $this->assertSame(array('Post 5'), $this->titles($page2['data']['html']));
        $this->assertFalse($page2['data']['has_more']);
    }

    /**
     * Like WC Multilang: a language taxonomy, and a pre_get_posts filter that
     * narrows front-end queries to the page's language but skips admin-ajax.
     */
    public function test_load_more_keeps_the_language_of_the_page() {
        register_taxonomy('language', 'post');
        foreach ($this->posts as $i => $id) {
            wp_set_object_terms($id, $i % 2 ? 'de' : 'en', 'language');
        }
        $narrow = function ($query) {
            if (!wp_doing_ajax()) {
                $query->set('tax_query', array(array('taxonomy' => 'language', 'field' => 'slug', 'terms' => 'de')));
            }
        };
        add_action('pre_get_posts', $narrow);

        // The page itself is a front-end request.
        remove_filter('wp_doing_ajax', '__return_true');
        $html = do_shortcode('[posts_embedder source="this-site" count="1"]');
        add_filter('wp_doing_ajax', '__return_true');

        // German posts are 1, 3 and 5.
        $this->assertSame(array('Post 1'), $this->titles($html));
        $this->assertStringContainsString('data-total-pages="3"', $html);
        $token = $this->token($html);
        $this->assertSame(array('tax_query'), array_keys(get_option('rest_posts_embedder_lm_' . $token)['carried']));

        $page2 = $this->load_more($token, 2);
        $this->assertSame(array('Post 3'), $this->titles($page2['data']['html']));
        $page3 = $this->load_more($token, 3);
        $this->assertSame(array('Post 5'), $this->titles($page3['data']['html']));
        $this->assertFalse($page3['data']['has_more']);

        remove_action('pre_get_posts', $narrow);
        unregister_taxonomy('language');
    }

    public function test_no_button_when_everything_fits() {
        $this->assertStringNotContainsString('embed-posts-load-more', do_shortcode('[posts_embedder source="this-site" count="5"]'));
    }

    public function test_rendering_restores_the_global_post() {
        global $post;
        $page = self::factory()->post->create_and_get(array('post_type' => 'page', 'post_title' => 'Host page'));
        $post = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata($page);
        do_shortcode('[posts_embedder source="this-site" count="3"]');
        $this->assertSame($page->ID, $post->ID);
        $this->assertSame('Host page', get_the_title());
    }

    public function test_query_args_filter() {
        add_filter('rest_posts_embedder_local_query_args', function ($args) {
            $args['post__not_in'] = array($this->posts[1]);
            return $args;
        });
        $this->assertSame(array('Post 2'), $this->titles(do_shortcode('[posts_embedder source="this-site" count="1"]')));
        remove_all_filters('rest_posts_embedder_local_query_args');
    }

    public function test_unknown_source_is_still_reported() {
        $this->assertStringContainsString('Feed source "this-site-2" not found.', do_shortcode('[posts_embedder source="this-site-2"]'));
    }
}
