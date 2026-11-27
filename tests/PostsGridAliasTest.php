<?php

use function RestPostsEmbedder\Shortcodes\register_posts_grid_alias;

/** [prowoos_posts_grid] from the merged ProWoos Posts Grid plugin. */
class PostsGridAliasTest extends WP_UnitTestCase {
    private $posts = array();

    public function set_up() {
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        update_option('embed_posts_excerpt_length', 200);
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        add_filter('pre_http_request', array($this, 'no_http'), PHP_INT_MAX, 3);
        $news = self::factory()->category->create(array('slug' => 'news'));
        for ($i = 1; $i <= 12; $i++) {
            $this->posts[$i] = self::factory()->post->create(array(
                'post_title' => 'Post ' . $i,
                'post_date' => sprintf('2026-03-%02d 11:30:00', 28 - $i),
                'post_content' => 'one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty twentyone twentytwo',
                'post_excerpt' => '',
                'post_category' => $i <= 3 ? array($news) : array(),
            ));
        }
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'no_http'), PHP_INT_MAX);
        parent::tear_down();
    }

    public function no_http($preempt, $args, $url) {
        $this->fail('Unexpected HTTP request to ' . $url);
    }

    private function titles($html) {
        preg_match_all('/<h3 class="embed-posts-card-title"><a [^>]*>([^<]+)</', $html, $matches);
        return $matches[1];
    }

    public function test_alias_is_registered() {
        global $shortcode_tags;
        $this->assertSame('RestPostsEmbedder\\Shortcodes\\posts_grid_alias', $shortcode_tags['prowoos_posts_grid']);
    }

    public function test_prowoos_blog_page_shortcode() {
        // The shortcode on the three ProWoos Blog pages, as Elementor stores it.
        $html = do_shortcode('[prowoos_posts_grid posts="9" columns="3" category="" excerpt_length="18"]');
        $this->assertStringContainsString('<div class="embed-posts-container embed-posts-layout-grid"><div class="embed-posts-wrapper" style="--rpe-cols: 3; --rpe-cols-tablet: 2;">', $html);
        $this->assertCount(9, $this->titles($html));
        $this->assertSame('Post 1', $this->titles($html)[0]);
        $this->assertStringContainsString('<p>one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen…</p>', $html);
        // The site's date format, as get_the_date() showed it.
        $this->assertStringContainsString('>March 27, 2026</time>', $html);
        // Same-tab links, as in ProWoos Posts Grid.
        $this->assertStringNotContainsString('target=', $html);
        // No Load More button, as in ProWoos Posts Grid.
        $this->assertStringNotContainsString('embed-posts-load-more', $html);
    }

    public function test_load_more_yes_adds_the_button() {
        // 12 posts in batches of 9.
        $html = do_shortcode('[prowoos_posts_grid posts="9" load_more="yes"]');
        $this->assertStringContainsString('data-total-pages="2"', $html);
        $this->assertStringNotContainsString('embed-posts-load-more', do_shortcode('[prowoos_posts_grid posts="9" load_more="no"]'));
    }

    public function test_defaults_match_prowoos_posts_grid() {
        $html = do_shortcode('[prowoos_posts_grid]');
        $this->assertCount(6, $this->titles($html));
        $this->assertStringContainsString('style="--rpe-cols: 3; --rpe-cols-tablet: 2;"', $html);
        $this->assertStringContainsString('<p>one two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteen twenty…</p>', $html);
    }

    public function test_query_attributes_pass_through() {
        $this->assertSame(array('Post 1', 'Post 2', 'Post 3'), $this->titles(do_shortcode('[prowoos_posts_grid category="news"]')));
        $this->assertSame(array('Post 12', 'Post 11'), $this->titles(do_shortcode('[prowoos_posts_grid posts="2" order="asc"]')));
        $this->assertSame(array('Post 11', 'Post 12'), $this->titles(do_shortcode('[prowoos_posts_grid posts="5" offset="10"]')));
    }

    public function test_columns_tablet_is_kept_even_above_columns() {
        $this->assertStringContainsString('style="--rpe-cols: 1; --rpe-cols-tablet: 2;"', do_shortcode('[prowoos_posts_grid columns="1"]'));
    }

    /** @dataProvider post_counts */
    public function test_post_count_stays_within_the_plugin_range($posts, $expected) {
        $this->assertCount($expected, $this->titles(do_shortcode('[prowoos_posts_grid posts="' . $posts . '"]')));
    }

    public static function post_counts() {
        // Only 12 posts exist, so 50 shows 12 (capped at 20 per batch).
        return array('zero' => array('0', 6), 'text' => array('many', 6), 'large' => array('50', 12));
    }

    public function test_alias_steps_aside_while_prowoos_posts_grid_is_active() {
        global $shortcode_tags;
        $original = $shortcode_tags['prowoos_posts_grid'];
        $theirs = function () {
            return 'ProWoos Posts Grid output';
        };
        remove_shortcode('prowoos_posts_grid');
        add_shortcode('prowoos_posts_grid', $theirs);
        register_posts_grid_alias();
        $this->assertSame('ProWoos Posts Grid output', do_shortcode('[prowoos_posts_grid]'));

        $shortcode_tags['prowoos_posts_grid'] = $original;
    }
}
