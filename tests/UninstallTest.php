<?php

/** Run the shipped uninstall.php and check what is left. */
class UninstallTest extends WP_UnitTestCase {
    private const OWNED_OPTIONS = array(
        'rest_posts_embedder_sources',
        'embed_posts_endpoint',
        'embed_posts_count',
        'embed_posts_layout',
        'embed_posts_excerpt_length',
        'embed_posts_columns_desktop',
        'embed_posts_columns_mobile',
        'embed_posts_show_images_desktop',
        'embed_posts_show_images_mobile',
        'embed_posts_custom_css',
        'embed_posts_cache_expiration',
        'embed_posts_cache_custom_hours',
        'rest_posts_embedder_cache_generation',
        'rest_posts_embedder_lm_0123456789abcdef0123456789abcdef',
        'rest_posts_embedder_lm_fedcba9876543210fedcba9876543210',
    );
    private const OWNED_TRANSIENTS = array(
        'rest_posts_embedder_0123456789abcdef0123456789abcdef',
        'rest_posts_embedder_p_0123456789abcdef0123456789abcdef',
        'rest_posts_embedder_tp_0123456789abcdef0123456789abcdef',
        'rest_posts_embedder_update_data',
        'rpe_lm_0123456789abcdef0123456789abcdef',
    );
    private const FOREIGN_OPTIONS = array('blogname', 'embed_posts_by_someone_else', 'rest_posts_other_plugin', 'another_rest_posts_embedder_sources');

    private function store_everything() {
        foreach (self::OWNED_OPTIONS as $option) {
            update_option($option, 'value of ' . $option);
        }
        foreach (self::OWNED_TRANSIENTS as $transient) {
            set_transient($transient, 'cached', HOUR_IN_SECONDS);
        }
        foreach (self::FOREIGN_OPTIONS as $option) {
            update_option($option, 'keep me');
        }
    }

    private function uninstall() {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'restpostsembedder/restpostsembedder.php');
        }
        require dirname(__DIR__) . '/uninstall.php';
    }

    /** Option names in the database that look like they belong to the plugin. */
    private function leftovers() {
        global $wpdb;
        wp_cache_flush();
        return $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE 'embed\\_posts\\_%' OR option_name LIKE 'rest\\_posts\\_embedder\\_%'
                OR option_name LIKE '\\_transient%rest\\_posts\\_embedder\\_%' OR option_name LIKE '\\_transient%rpe\\_lm\\_%'
             ORDER BY option_name"
        );
    }

    public function test_every_registered_setting_is_covered() {
        global $wp_registered_settings;
        \RestPostsEmbedder\Admin\embed_posts_settings_init();
        \RestPostsEmbedder\Admin\embed_posts_styling_init();
        \RestPostsEmbedder\Admin\embed_posts_cache_init();
        $registered = array();
        foreach ($wp_registered_settings as $name => $setting) {
            if (0 === strpos($setting['group'], 'embed_posts')) {
                $registered[] = $name;
            }
        }
        $this->assertNotEmpty($registered);
        $this->assertSame(array(), array_values(array_diff($registered, self::OWNED_OPTIONS)), 'A registered setting is missing from the uninstall test and uninstall.php.');
    }

    public function test_uninstall_removes_everything_the_plugin_stored_and_nothing_else() {
        $this->store_everything();
        $this->assertCount(count(self::OWNED_OPTIONS) + 2 * count(self::OWNED_TRANSIENTS) + 1, $this->leftovers());

        $this->uninstall();

        $this->assertSame(array('embed_posts_by_someone_else'), $this->leftovers());
        foreach (self::OWNED_OPTIONS as $option) {
            $this->assertFalse(get_option($option), $option);
        }
        foreach (self::FOREIGN_OPTIONS as $option) {
            $this->assertSame('keep me', get_option($option), $option);
        }
    }

    public function test_reinstalling_after_uninstall_starts_from_the_demo_feed() {
        update_option('rest_posts_embedder_sources', array('old' => array('id' => 'old', 'name' => 'Old', 'endpoint' => 'https://93.184.216.34/old', 'count' => 3, 'enabled' => true)));
        update_option('embed_posts_endpoint', 'https://93.184.216.34/old');
        $this->uninstall();
        wp_cache_flush();
        \RestPostsEmbedder\rest_posts_embedder_activate();
        $sources = get_option('rest_posts_embedder_sources');
        $this->assertSame(array('default'), array_keys($sources));
        $this->assertSame(REST_POSTS_EMBEDDER_DEFAULT_ENDPOINT, $sources['default']['endpoint']);
    }

    public function test_uninstall_cleans_every_site_of_a_network() {
        if (!is_multisite()) {
            $this->markTestSkipped('Runs with composer test:multisite.');
        }
        $this->store_everything();
        $other = self::factory()->blog->create();
        switch_to_blog($other);
        $this->store_everything();
        restore_current_blog();

        $this->uninstall();

        $this->assertSame(array('embed_posts_by_someone_else'), $this->leftovers());
        switch_to_blog($other);
        $leftovers = $this->leftovers();
        $kept = get_option('blogname');
        restore_current_blog();
        $this->assertSame(array('embed_posts_by_someone_else'), $leftovers);
        $this->assertSame('keep me', $kept);
        $this->assertSame(get_main_site_id(), get_current_blog_id());
    }
}
