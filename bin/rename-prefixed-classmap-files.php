<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

$justbee_postcaster_target = dirname(__DIR__) . '/includes/vendor-prefixed/woocommerce/action-scheduler';

if (!is_dir($justbee_postcaster_target)) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
    fwrite(STDERR, "Prefixed Action Scheduler build output not found.\n");
    exit(1);
}

$justbee_postcaster_iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($justbee_postcaster_target, FilesystemIterator::SKIP_DOTS)
);

$justbee_postcaster_renamed = 0;

foreach ($justbee_postcaster_iterator as $justbee_postcaster_file) {
    if (!$justbee_postcaster_file->isFile() || strtolower($justbee_postcaster_file->getExtension()) !== 'php') {
        continue;
    }

    $justbee_postcaster_path = $justbee_postcaster_file->getPathname();
    $justbee_postcaster_contents = file_get_contents($justbee_postcaster_path);
    if (!is_string($justbee_postcaster_contents) || $justbee_postcaster_contents === '') {
        continue;
    }

    if (!preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $justbee_postcaster_contents, $justbee_postcaster_match)) {
        continue;
    }

    $justbee_postcaster_expected_path = $justbee_postcaster_file->getPath() . DIRECTORY_SEPARATOR . $justbee_postcaster_match[1] . '.php';
    if (strcasecmp($justbee_postcaster_path, $justbee_postcaster_expected_path) === 0 || is_file($justbee_postcaster_expected_path)) {
        continue;
    }

    if (!rename($justbee_postcaster_path, $justbee_postcaster_expected_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
        fwrite(STDERR, sprintf("Could not rename %s\n", $justbee_postcaster_path));
        exit(1);
    }

    $justbee_postcaster_renamed++;
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
fwrite(STDOUT, sprintf("Renamed %d prefixed Action Scheduler classmap files.\n", $justbee_postcaster_renamed));
