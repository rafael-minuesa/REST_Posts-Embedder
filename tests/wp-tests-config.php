<?php
/** Use a disposable database: the WordPress suite creates and drops tables. */
define('ABSPATH', (getenv('WP_DEVELOP_DIR') ?: sys_get_temp_dir() . '/rpe-wordpress') . '/src/');
define('DB_NAME', getenv('WP_TESTS_DB_NAME') ?: 'rpe_tests');
define('DB_USER', getenv('WP_TESTS_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('WP_TESTS_DB_PASSWORD') ?: '');
define('DB_HOST', getenv('WP_TESTS_DB_HOST') ?: '127.0.0.1');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');
$table_prefix = 'rpe_tests_';
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'REST Posts Embedder tests');
define('WP_PHP_BINARY', PHP_BINARY);
define('WPLANG', '');
define('WP_DEBUG', true);
