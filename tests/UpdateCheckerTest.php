<?php

/** Exercise the self-hosted updater through WordPress's own update check. */
class UpdateCheckerTest extends WP_UnitTestCase {
    private const MANIFEST_HOST = 'prowoos.com';
    private const PACKAGE = 'https://prowoos.com/wp-content/uploads/rpe-updates/restpostsembedder-99.0.0.zip';

    private $manifest;
    private $manifest_requests;

    public function set_up() {
        parent::set_up();
        $this->manifest_requests = 0;
        $this->manifest = $this->manifest_response(array(
            'version' => '99.0.0',
            'package' => self::PACKAGE,
            'tested' => '6.8',
            'requires' => '5.3',
            'requires_php' => '7.4',
        ));
        delete_transient(REST_Posts_Embedder_Update_Checker::CACHE_KEY);
        delete_site_transient('update_plugins');
        unset($_GET['force-check']);
        add_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX, 3);
    }

    public function tear_down() {
        remove_filter('pre_http_request', array($this, 'intercept_request'), PHP_INT_MAX);
        unset($_GET['force-check']);
        parent::tear_down();
    }

    public function intercept_request($preempt, $args, $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (self::MANIFEST_HOST === $host) {
            $this->assertSame(REST_Posts_Embedder_Update_Checker::MANIFEST_URL, $url);
            $this->manifest_requests++;
            return $this->manifest;
        }
        // WordPress's own plugin update request. Nothing to report.
        $this->assertSame('api.wordpress.org', $host, 'Unexpected HTTP request.');
        return array(
            'headers' => array(),
            'body' => wp_json_encode(array('plugins' => array(), 'translations' => array(), 'no_update' => array())),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
        );
    }

    private function manifest_response($manifest, $status = 200) {
        return array(
            'headers' => array(),
            'body' => is_string($manifest) ? $manifest : wp_json_encode($manifest),
            'response' => array('code' => $status, 'message' => ''),
            'cookies' => array(),
        );
    }

    private function basename() {
        return plugin_basename(dirname(__DIR__) . '/restpostsembedder.php');
    }

    /** The update list as WordPress would save it after checking these versions. */
    private function checked($version) {
        $transient = new stdClass();
        $transient->checked = array($this->basename() => $version);
        set_site_transient('update_plugins', $transient);
        return get_site_transient('update_plugins');
    }

    public function test_checker_is_registered_outside_wp_admin_and_wp_cli() {
        $this->assertFalse(is_admin());
        $this->assertFalse(defined('WP_CLI') && WP_CLI);
        $this->assertTrue(class_exists('REST_Posts_Embedder_Update_Checker'));
        $this->assertNotFalse(has_filter('pre_set_site_transient_update_plugins'));
    }

    public function test_scheduled_plugin_check_reports_the_self_hosted_release() {
        add_filter('wp_doing_cron', '__return_true');
        $this->assertTrue(wp_doing_cron());

        wp_update_plugins();

        $updates = get_site_transient('update_plugins');
        $this->assertNotEmpty($updates->checked, 'WordPress found no installed plugins to check.');
        $this->assertArrayHasKey($this->basename(), $updates->response);
        $update = $updates->response[$this->basename()];
        $this->assertSame('99.0.0', $update->new_version);
        $this->assertSame(self::PACKAGE, $update->package);
        $this->assertSame('restpostsembedder', $update->slug);
        $this->assertSame($this->basename(), $update->plugin);
    }

    public function test_current_version_is_reported_as_up_to_date() {
        $updates = $this->checked('99.0.0');
        $this->assertTrue(empty($updates->response[$this->basename()]));
        $this->assertSame('99.0.0', $updates->no_update[$this->basename()]->new_version);
    }

    /** @dataProvider unusable_manifests */
    public function test_unusable_manifest_announces_nothing_and_is_not_cached($body, $status) {
        $this->manifest = $this->manifest_response($body, $status);
        $updates = $this->checked('3.8.0');
        $this->assertTrue(empty($updates->response[$this->basename()]));
        $this->assertTrue(empty($updates->no_update[$this->basename()]));
        $this->assertFalse(get_transient(REST_Posts_Embedder_Update_Checker::CACHE_KEY));
    }

    public static function unusable_manifests() {
        return array(
            'server error' => array('{"version":"99.0.0"}', 500),
            'HTML page' => array('<html>Maintenance</html>', 200),
            'no version' => array('{"package":"https://prowoos.com/x.zip"}', 200),
        );
    }

    public function test_manifest_is_cached_between_checks() {
        $this->checked('3.8.0');
        $this->checked('3.8.0');
        $this->assertSame(1, $this->manifest_requests);
    }

    public function test_main_file_declares_the_self_hosted_update_uri() {
        $headers = get_file_data(dirname(__DIR__) . '/restpostsembedder.php', array('UpdateURI' => 'Update URI'));
        $this->assertSame(REST_Posts_Embedder_Update_Checker::MANIFEST_URL, $headers['UpdateURI']);
    }

    /** @dataProvider users_without_update_rights */
    public function test_force_check_is_ignored_without_update_rights($role) {
        $this->checked('3.8.0');
        wp_set_current_user($role ? self::factory()->user->create(array('role' => $role)) : 0);
        $_GET['force-check'] = '1';

        // The request an anonymous visitor could send in 3.8.0: admin-ajax.php
        // makes is_admin() true without any login.
        set_current_screen('admin-ajax');
        $this->assertTrue(is_admin());
        $checker = new REST_Posts_Embedder_Update_Checker($this->basename());
        $checker->maybe_force_check();

        $this->assertNotFalse(get_transient(REST_Posts_Embedder_Update_Checker::CACHE_KEY));
        $this->assertNotFalse(get_site_transient('update_plugins'));
    }

    public static function users_without_update_rights() {
        return array('visitor' => array(''), 'subscriber' => array('subscriber'), 'editor' => array('editor'));
    }

    public function test_force_check_refreshes_for_a_user_who_can_update_plugins() {
        $this->checked('3.8.0');
        $admin = self::factory()->user->create(array('role' => 'administrator'));
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        $checker = new REST_Posts_Embedder_Update_Checker($this->basename());

        // Without the flag nothing is cleared.
        $checker->maybe_force_check();
        $this->assertNotFalse(get_transient(REST_Posts_Embedder_Update_Checker::CACHE_KEY));

        $_GET['force-check'] = '1';
        $checker->maybe_force_check();
        $this->assertFalse(get_transient(REST_Posts_Embedder_Update_Checker::CACHE_KEY));
        $this->assertFalse(get_site_transient('update_plugins'));
    }

    public function test_force_check_runs_only_on_the_updates_screen() {
        global $wp_filter;
        $hooked = array();
        foreach ($wp_filter as $hook => $callbacks) {
            foreach ($callbacks->callbacks as $priority => $group) {
                foreach ($group as $callback) {
                    $function = $callback['function'];
                    if (is_array($function) && $function[0] instanceof REST_Posts_Embedder_Update_Checker && 'maybe_force_check' === $function[1]) {
                        $hooked[] = $hook . '@' . $priority;
                    }
                }
            }
        }
        // Priority 9 runs before core's wp_update_plugins() on the same hook.
        $this->assertSame(array('load-update-core.php@9'), $hooked);
    }
}
