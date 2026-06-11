<?php

namespace Justbee\PostCaster\Cli;

if (!defined('ABSPATH')) {
    exit;
}

final class LogsCommand extends AbstractCliCommand
{
    public function logs(array $args, array $assocArgs): void
    {
        $postId = isset($assocArgs['post']) ? (int) $assocArgs['post'] : 0;

        if ($postId > 0) {
            $lines = $this->postMeta->getLog($postId);
            if ($lines === []) {
                \WP_CLI::log(sprintf(
                    /* translators: %d: WordPress post ID. */
                    __('No post-specific logs found for post %d.', 'postcaster'),
                    $postId
                ));
                return;
            }

            \WP_CLI::log(sprintf(
                /* translators: %d: WordPress post ID. */
                __('Post logs for %d:', 'postcaster'),
                $postId
            ));
            foreach (array_reverse($lines) as $line) {
                \WP_CLI::log((string) $line);
            }
            return;
        }

        $entries = $this->postMeta->getAllLogs();
        if ($entries === []) {
            \WP_CLI::log(__('No PostCaster post logs found.', 'postcaster'));
        } else {
            foreach ($entries as $entry) {
                \WP_CLI::log(sprintf(
                    '[Post %d] %s',
                    (int) $entry['post_id'],
                    (string) $entry['title']
                ));
                foreach ((array) $entry['lines'] as $line) {
                    \WP_CLI::log('  ' . (string) $line);
                }
            }
        }

        $systemEntries = $this->debugLog->getAll();
        if ($systemEntries !== []) {
            \WP_CLI::log(__('System log:', 'postcaster'));
            foreach ($systemEntries as $entry) {
                \WP_CLI::log(sprintf(
                    '  [%s] %s',
                    (string) ($entry['timestamp'] ?? ''),
                    (string) ($entry['message'] ?? '')
                ));
            }
        }
    }

    public function clearLogs(): void
    {
        $this->postMeta->clearAllLogs();
        $this->debugLog->clear();
        \WP_CLI::success(__('PostCaster logs cleared.', 'postcaster'));
    }
}
