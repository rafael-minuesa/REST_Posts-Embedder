<?php

use function RestPostsEmbedder\Blocks\shortcode_atts_for_block;
use function RestPostsEmbedder\Blocks\editor_data;

/** The "Posts Grid" block: registration, attributes and server rendering. */
class BlockTest extends WP_UnitTestCase {
    private const BLOCK = 'restpostsembedder/posts-grid';

    public function set_up() {
        parent::set_up();
        update_option('timezone_string', 'Europe/Madrid');
        update_option('embed_posts_excerpt_length', 200);
        delete_option('embed_posts_layout');
        update_option('rest_posts_embedder_cache_generation', wp_generate_uuid4());
        add_filter('pre_http_request', array($this, 'no_http'), PHP_INT_MAX, 3);
        for ($i = 1; $i <= 4; $i++) {
            self::factory()->post->create(array(
                'post_title' => 'Post ' . $i,
                'post_date' => sprintf('2026-03-%02d 11:30:00', 20 - $i),
                'post_content' => 'one two three four five six seven eight',
                'post_excerpt' => '',
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

    public function test_block_is_registered_as_a_dynamic_block() {
        $block = WP_Block_Type_Registry::get_instance()->get_registered(self::BLOCK);
        $this->assertInstanceOf('WP_Block_Type', $block);
        $this->assertTrue($block->is_dynamic());
        $this->assertSame('this-site', $block->attributes['source']['default']);
        $this->assertSame('grid', $block->attributes['layout']['default']);
        $this->assertArrayNotHasKey('default', $block->attributes['count'], 'Unset means the site setting.');
    }

    public function test_default_block_shows_this_site_as_a_grid() {
        $html = do_blocks('<!-- wp:restpostsembedder/posts-grid /-->');
        $this->assertStringStartsWith('<div class="wp-block-restpostsembedder-posts-grid"><div class="embed-posts-container embed-posts-layout-grid">', $html);
        $this->assertStringContainsString('>Post 1</a></h3>', $html);
    }

    public function test_block_output_matches_the_shortcode() {
        $attributes = array('count' => 2, 'columns' => 3, 'excerptUnit' => 'words', 'excerptLength' => 3, 'order' => 'ASC');
        $block = do_blocks('<!-- wp:restpostsembedder/posts-grid ' . wp_json_encode($attributes) . ' /-->');
        $shortcode = do_shortcode('[posts_embedder source="this-site" layout="grid" count="2" columns="3" excerpt_words="3" order="ASC" orderby="date" offset="0"]');
        $this->assertSame('<div class="wp-block-restpostsembedder-posts-grid">' . $shortcode . '</div>', $block);
        $this->assertStringContainsString('<p>one two three…</p>', $block);
    }

    public function test_load_more_toggle() {
        $this->assertStringContainsString('embed-posts-load-more', do_blocks('<!-- wp:restpostsembedder/posts-grid {"count":2} /-->'));
        $this->assertStringNotContainsString('embed-posts-load-more', do_blocks('<!-- wp:restpostsembedder/posts-grid {"count":2,"loadMore":false} /-->'));
    }

    public function test_wrapper_keeps_alignment_and_custom_class() {
        $html = do_blocks('<!-- wp:restpostsembedder/posts-grid {"align":"wide","className":"my-grid"} /-->');
        $this->assertMatchesRegularExpression('/^<div class="wp-block-restpostsembedder-posts-grid[^"]*\balignwide\b[^"]*"|^<div class="[^"]*my-grid/', $html);
        $this->assertStringContainsString('my-grid', substr($html, 0, 120));
    }

    /** @dataProvider attribute_sets */
    public function test_attributes_map_to_shortcode_attributes($attributes, $expected) {
        $this->assertSame($expected, shortcode_atts_for_block($attributes));
    }

    public static function attribute_sets() {
        $defaults = array('source' => 'this-site', 'layout' => 'grid', 'orderby' => 'date', 'order' => 'DESC', 'offset' => '0');
        return array(
            'defaults' => array($defaults + array('excerptUnit' => 'words', 'category' => '', 'tag' => ''), $defaults),
            'words' => array(array('excerptUnit' => 'words', 'excerptLength' => 18), array('source' => 'this-site', 'excerpt_words' => '18')),
            'characters, including 0 for the whole excerpt' => array(array('excerptUnit' => 'characters', 'excerptLength' => 0), array('source' => 'this-site', 'excerpt_length' => '0')),
            'columns' => array(array('columns' => 4, 'columnsTablet' => 2), array('source' => 'this-site', 'columns' => '4', 'columns_tablet' => '2')),
            'Load More on' => array(array('loadMore' => true), array('source' => 'this-site')),
            'Load More off' => array(array('loadMore' => false), array('source' => 'this-site', 'load_more' => 'no')),
            'stored source' => array(array('source' => 'my-feed', 'layout' => 'classic'), array('source' => 'my-feed', 'layout' => 'classic')),
            'non-scalar values are dropped' => array(array('source' => array('x'), 'count' => array(3), 'category' => array('news')), array('source' => 'this-site')),
        );
    }

    public function test_editor_preview_through_the_block_renderer_endpoint() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $request = new WP_REST_Request('GET', '/wp/v2/block-renderer/' . self::BLOCK);
        $request->set_param('context', 'edit');
        $request->set_param('attributes', array('count' => 1, 'layout' => 'classic'));
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $this->assertStringContainsString('embed-posts-layout-classic', $response->get_data()['rendered']);
        $this->assertStringContainsString('<h3>Post 1</h3>', $response->get_data()['rendered']);
    }

    public function test_block_renderer_rejects_unknown_attribute_values() {
        wp_set_current_user(self::factory()->user->create(array('role' => 'editor')));
        $request = new WP_REST_Request('GET', '/wp/v2/block-renderer/' . self::BLOCK);
        $request->set_param('context', 'edit');
        $request->set_param('attributes', array('excerptUnit' => 'lines'));
        $this->assertSame(400, rest_get_server()->dispatch($request)->get_status());
    }

    public function test_editor_lists_enabled_sources_and_layouts() {
        update_option('rest_posts_embedder_sources', array(
            'on_1' => array('id' => 'on_1', 'name' => 'On', 'endpoint' => 'https://example.org/wp-json/wp/v2/posts', 'count' => 3, 'enabled' => true),
            'off_1' => array('id' => 'off_1', 'name' => 'Off', 'endpoint' => 'https://example.org/wp-json/wp/v2/posts', 'count' => 3, 'enabled' => false),
        ));
        $data = editor_data();
        $this->assertSame(array(array('id' => 'on_1', 'name' => 'On')), $data['sources']);
        $this->assertSame(array('classic', 'grid'), wp_list_pluck($data['layouts'], 'id'));
        $this->assertSame('this-site', $data['localSource']);
        $this->assertSame('', $data['settingsUrl'], 'Only administrators get the settings link.');
    }

    public function test_editor_script_parses() {
        $this->assertFileExists(dirname(__DIR__) . '/assets/js/block-editor.js');
    }
}
