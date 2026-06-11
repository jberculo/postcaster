<?php

declare(strict_types=1);

$pluginDir = dirname(__DIR__, 2);

$autoload = $pluginDir . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Run `composer install` in the plugin directory first.\n");
    exit(1);
}
require_once $autoload;

$wpTestsDir = getenv('WP_TESTS_DIR');
if ($wpTestsDir === false || $wpTestsDir === '') {
    $wpTestsDir = $pluginDir . '/vendor/wp-phpunit/wp-phpunit';
}

if (!file_exists($wpTestsDir . '/includes/functions.php')) {
    fwrite(STDERR, "Could not find the wp-phpunit test library at {$wpTestsDir}.\n");
    exit(1);
}

$configFile = $pluginDir . '/tests/phpunit/wp-tests-config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Copy tests/phpunit/wp-tests-config-sample.php to tests/phpunit/wp-tests-config.php and fill in the DB details.\n");
    exit(1);
}

// wp-phpunit reads this env var to locate the test config.
putenv('WP_PHPUNIT__TESTS_CONFIG=' . $configFile);

// Provide a stable encryption key so SecretsCipher works under the
// transactional test database (option-stored keys would be rolled back
// between tests).
if (!defined('JUSTBEE_POSTCASTER_ENCRYPTION_KEY')) {
    define('JUSTBEE_POSTCASTER_ENCRYPTION_KEY', 'postcaster-phpunit-fixed-key-do-not-use-in-production');
}

require_once $wpTestsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function () use ($pluginDir): void {
    require $pluginDir . '/postcaster.php';
});

require $wpTestsDir . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/FakeNetworkPublisher.php';
require_once __DIR__ . '/includes/BuildsPublisherStack.php';
