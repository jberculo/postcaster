<?php

namespace Justbee\PostCaster\Cli;

use Justbee\PostCaster\Models\DebugLogModel;
use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\TestPostService;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractCliCommand
{
    protected SettingsModel $settings;
    protected NetworkRegistry $networks;
    protected UserProfileModel $profiles;
    protected PostMetaModel $postMeta;
    protected DebugLogModel $debugLog;
    protected PublisherService $publisher;
    protected TestPostService $tests;

    public function __construct(
        SettingsModel $settings,
        NetworkRegistry $networks,
        UserProfileModel $profiles,
        PostMetaModel $postMeta,
        DebugLogModel $debugLog,
        PublisherService $publisher,
        TestPostService $tests
    ) {
        $this->settings = $settings;
        $this->networks = $networks;
        $this->profiles = $profiles;
        $this->postMeta = $postMeta;
        $this->debugLog = $debugLog;
        $this->publisher = $publisher;
        $this->tests = $tests;
    }

    protected function getNetworkOrExit(string $networkKey)
    {
        $networkKey = sanitize_key($networkKey);
        if ($networkKey === '') {
            \WP_CLI::error(__('Please provide a network key.', 'postcaster'));
        }

        $network = $this->networks->get($networkKey);
        if (!$network) {
            \WP_CLI::error(sprintf(
                /* translators: 1: requested network key, 2: comma-separated list of available network keys. */
                __('Unknown network "%1$s". Available networks: %2$s', 'postcaster'),
                $networkKey,
                implode(', ', $this->networks->keys())
            ));
        }

        return $network;
    }

    protected function getPostOrExit(int $postId): WP_Post
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            \WP_CLI::error(sprintf(
                /* translators: %d: WordPress post ID. */
                __('Unknown post %d.', 'postcaster'),
                $postId
            ));
        }

        return $post;
    }

    protected function handleNoticeResult(array $notice): void
    {
        if (($notice['type'] ?? '') === 'success') {
            \WP_CLI::success((string) ($notice['message'] ?? ''));
            return;
        }

        \WP_CLI::error((string) ($notice['message'] ?? __('Unknown PostCaster CLI error.', 'postcaster')));
    }

    protected function formatState(bool $enabled): string
    {
        return $enabled
            ? __('enabled', 'postcaster')
            : __('disabled', 'postcaster');
    }
}
