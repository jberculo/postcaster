<?php
/**
 * Copy this file to wp-tests-config.php and fill in the DB credentials.
 * The test database will be CREATED AND DROPPED by wp-phpunit — use a dedicated one.
 */

// WordPress core is installed under tests/wordpress/ by composer (roots/wordpress-no-content).
define('ABSPATH', dirname(__DIR__, 2) . '/tests/wordpress/');

// Test DB — must exist; contents are wiped on each test run.
define('DB_NAME', 'postcaster_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';

define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'PostCaster Tests');
define('WP_PHP_BINARY', 'php');

define('WPLANG', '');
define('WP_DEBUG', true);

define('AUTH_KEY', 'put-anything-here');
define('SECURE_AUTH_KEY', 'put-anything-here');
define('LOGGED_IN_KEY', 'put-anything-here');
define('NONCE_KEY', 'put-anything-here');
define('AUTH_SALT', 'put-anything-here');
define('SECURE_AUTH_SALT', 'put-anything-here');
define('LOGGED_IN_SALT', 'put-anything-here');
define('NONCE_SALT', 'put-anything-here');
