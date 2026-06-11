<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

$justbee_postcaster_targets = [
    dirname(__DIR__) . '/includes/vendor-prefixed',
];

foreach ($justbee_postcaster_targets as $justbee_postcaster_target) {
    if (!is_dir($justbee_postcaster_target)) {
        continue;
    }

    $justbee_postcaster_iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($justbee_postcaster_target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($justbee_postcaster_iterator as $justbee_postcaster_item) {
        $justbee_postcaster_path = $justbee_postcaster_item->getPathname();

        if ($justbee_postcaster_item->isDir()) {
            rmdir($justbee_postcaster_path);
            continue;
        }

        unlink($justbee_postcaster_path);
    }

    rmdir($justbee_postcaster_target);
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
fwrite(STDOUT, "Removed previous Action Scheduler build output.\n");
