<?php
/** Load the real WordPress test framework and the plugin. */
$wordpress_dir = getenv('WP_DEVELOP_DIR') ?: sys_get_temp_dir() . '/rpe-wordpress';
$tests_dir = $wordpress_dir . '/tests/phpunit';
if (!file_exists($tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "WordPress tests not found. Run bash dev-tools/install-test-wordpress.sh first.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
define('WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php');
define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');
require_once $tests_dir . '/includes/functions.php';

// Fail closed: even requests made while loading WordPress cannot reach a feed.
tests_add_filter('pre_http_request', function () {
    return new WP_Error('unexpected_http_request', 'HTTP is disabled in the test suite.');
}, PHP_INT_MAX);
tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__) . '/restpostsembedder.php';
});
require $tests_dir . '/includes/bootstrap.php';
