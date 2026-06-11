<?php

namespace Justbee\PostCaster\Cli;

use Justbee\PostCaster\Models\DebugLogModel;
use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\TestPostService;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

final class Command
{
    private ConfigCommand $config;
    private TestCommand $test;
    private LogsCommand $logs;
    private PublishCommand $publish;
    private TemplateCommand $template;
    private ProfileCommand $profile;

    public function __construct(
        SettingsModel $settings,
        NetworkRegistry $networks,
        UserProfileModel $profiles,
        PostMetaModel $postMeta,
        DebugLogModel $debugLog,
        PublisherService $publisher,
        TestPostService $tests
    ) {
        $this->config = new ConfigCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
        $this->test = new TestCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
        $this->logs = new LogsCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
        $this->publish = new PublishCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
        $this->template = new TemplateCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
        $this->profile = new ProfileCommand($settings, $networks, $profiles, $postMeta, $debugLog, $publisher, $tests);
    }

    public function status(): void
    {
        $this->config->status();
    }

    /**
     * Enable global publishing or a specific network.
     *
     * ## OPTIONS
     *
     * [<network>]
     * : Optional network key. Leave empty to enable global publishing.
     *
     * ## EXAMPLES
     *
     *     wp postcaster enable
     *     wp postcaster enable bluesky
     */
    public function enable(array $args): void
    {
        $this->config->enable($args);
    }

    /**
     * Disable global publishing or a specific network.
     *
     * ## OPTIONS
     *
     * [<network>]
     * : Optional network key. Leave empty to disable global publishing.
     *
     * ## EXAMPLES
     *
     *     wp postcaster disable
     *     wp postcaster disable mastodon
     */
    public function disable(array $args): void
    {
        $this->config->disable($args);
    }

    /**
     * Send a test post using the current global settings.
     *
     * ## OPTIONS
     *
     * <network>
     * : Network key.
     *
     * ## EXAMPLES
     *
     *     wp postcaster test bluesky
     */
    public function test(array $args): void
    {
        $this->test->test($args);
    }

    /**
     * Show PostCaster logs.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Limit the output to one post.
     *
     * ## EXAMPLES
     *
     *     wp postcaster logs
     *     wp postcaster logs --post=123
     */
    public function logs(array $args, array $assocArgs): void
    {
        $this->logs->logs($args, $assocArgs);
    }

    /**
     * Clear PostCaster logs.
     *
     * ## OPTIONS
     *
     * @subcommand clear-logs
     *
     * ## EXAMPLES
     *
     *     wp postcaster clear-logs
     */
    public function clearLogs(): void
    {
        $this->logs->clearLogs();
    }

    /**
     * Publish an article directly.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : WordPress post ID.
     *
     * [--include-personal]
     * : Also publish to personal accounts.
     *
     * [--force]
     * : Allow reposting to targets that already received the article.
     *
     * ## EXAMPLES
     *
     *     wp postcaster publish 123
     *     wp postcaster publish 123 --include-personal
     *     wp postcaster publish 123 --force
     */
    public function publish(array $args, array $assocArgs): void
    {
        $this->publish->publish($args, $assocArgs);
    }

    /**
     * Show the rendered text that PostCaster would post.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : WordPress post ID.
     *
     * [--network=<key>]
     * : Limit the output to one network.
     *
     * [--include-personal]
     * : Also include personal accounts.
     *
     * ## EXAMPLES
     *
     *     wp postcaster preview 123
     *     wp postcaster preview 123 --network=bluesky
     */
    public function preview(array $args, array $assocArgs): void
    {
        $this->publish->preview($args, $assocArgs);
    }

    /**
     * Diagnose the current PostCaster configuration.
     *
     * ## EXAMPLES
     *
     *     wp postcaster doctor
     */
    public function doctor(): void
    {
        $this->config->doctor();
    }

    /**
     * Inspect or set the post-specific template for one article.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : WordPress post ID.
     *
     * [--set=<template>]
     * : Set the post-specific template.
     *
     * ## EXAMPLES
     *
     *     wp postcaster template 123
     *     wp postcaster template 123 --set="{title}\n\n{url}"
     */
    public function template(array $args, array $assocArgs): void
    {
        $this->template->template($args, $assocArgs);
    }

    /**
     * Inspect or test a user profile.
     *
     * ## OPTIONS
     *
     * <user-id>
     * : WordPress user ID.
     *
     * <action>
     * : Supported actions: status, test.
     *
     * [<network>]
     * : Required for the test action.
     *
     * ## EXAMPLES
     *
     *     wp postcaster profile 15 status
     *     wp postcaster profile 15 test mastodon
     */
    public function profile(array $args): void
    {
        $this->profile->profile($args);
    }
}
