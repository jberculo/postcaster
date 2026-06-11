<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

function justbee_postcaster_run_lint(): int
{
    $justbee_postcaster_roots = ['includes', 'tests'];
    $justbee_postcaster_base = dirname(__DIR__);
    $justbee_postcaster_failed = 0;
    $justbee_postcaster_scanned = 0;

    foreach ($justbee_postcaster_roots as $justbee_postcaster_root) {
        $justbee_postcaster_path = $justbee_postcaster_base . DIRECTORY_SEPARATOR . $justbee_postcaster_root;
        if (!is_dir($justbee_postcaster_path)) {
            continue;
        }

        $justbee_postcaster_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($justbee_postcaster_path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($justbee_postcaster_iterator as $justbee_postcaster_file) {
            if ($justbee_postcaster_file->getExtension() !== 'php') {
                continue;
            }

            $justbee_postcaster_file_path = $justbee_postcaster_file->getPathname();
            $justbee_postcaster_scanned++;
            $justbee_postcaster_output = [];
            $justbee_postcaster_exit_code = 0;
            exec('php -l ' . escapeshellarg($justbee_postcaster_file_path) . ' 2>&1', $justbee_postcaster_output, $justbee_postcaster_exit_code);

            if ($justbee_postcaster_exit_code !== 0) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
                fwrite(STDOUT, sprintf("FAIL: %s\n", $justbee_postcaster_file_path));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
                fwrite(STDOUT, implode("\n", $justbee_postcaster_output) . "\n\n");
                $justbee_postcaster_failed++;
            }
        }
    }

    if ($justbee_postcaster_failed > 0) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
        fwrite(STDERR, sprintf("\n%d file(s) failed syntax check (%d scanned).\n", $justbee_postcaster_failed, $justbee_postcaster_scanned));
        return 1;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only diagnostics.
    fwrite(STDOUT, sprintf("Lint OK - %d files scanned.\n", $justbee_postcaster_scanned));

    return 0;
}

$justbee_postcaster_exit_status = justbee_postcaster_run_lint();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI process exit status, not rendered output.
exit($justbee_postcaster_exit_status);
