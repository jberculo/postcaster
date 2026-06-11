<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

$justbee_postcaster_strauss = dirname(__DIR__) . '/vendor/bin/strauss';

if (!is_file($justbee_postcaster_strauss)) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
    fwrite(STDERR, "Strauss binary not found. Run composer install first.\n");
    exit(1);
}

$justbee_postcaster_error_reporting = (string) (E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$justbee_postcaster_command = escapeshellarg(PHP_BINARY)
    . ' -d error_reporting=' . escapeshellarg($justbee_postcaster_error_reporting)
    . ' ' . escapeshellarg($justbee_postcaster_strauss);

$justbee_postcaster_exit_code = 0;
passthru($justbee_postcaster_command, $justbee_postcaster_exit_code);

exit($justbee_postcaster_exit_code);
