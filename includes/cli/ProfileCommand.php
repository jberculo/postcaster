<?php

namespace Justbee\PostCaster\Cli;

use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileCommand extends AbstractCliCommand
{
    public function profile(array $args): void
    {
        $userId = (int) ($args[0] ?? 0);
        $action = sanitize_key((string) ($args[1] ?? 'status'));
        $user = get_userdata($userId);

        if (!$user instanceof WP_User) {
            \WP_CLI::error(sprintf(
                /* translators: %d: WordPress user ID. */
                __('Unknown user %d.', 'postcaster'),
                $userId
            ));
        }

        if ($action === 'status') {
            $profile = $this->profiles->get($userId);
            \WP_CLI::log(sprintf(
                /* translators: 1: WordPress username, 2: whether personal publishing is enabled or disabled. */
                __('Personal publishing for %1$s: %2$s', 'postcaster'),
                $user->user_login,
                $this->formatState(($profile['enabled'] ?? '0') === '1')
            ));
            foreach ($this->networks->all() as $network) {
                \WP_CLI::log(sprintf(
                    '%s: %s',
                    $network->getLabel(),
                    $this->formatState(($profile[$network->optionKey('enabled')] ?? '0') === '1')
                ));
            }
            return;
        }

        if ($action === 'test') {
            if (!isset($args[2]) || (string) $args[2] === '') {
                \WP_CLI::error(__('Please provide a network key for profile test.', 'postcaster'));
            }
            $network = $this->getNetworkOrExit((string) $args[2]);

            $options = $this->settings->get();
            $profile = $this->profiles->getExplicit($userId);
            $options = $this->profiles->mergeIntoOptions($options, $profile);
            $options = $network->mergeProfileIntoOptions($options, $profile);

            $notice = $this->tests->send($network->getKey(), $options, [
                'type' => 'profile',
                'scope' => 'cli:profile:user_' . $user->user_login,
            ]);
            $this->handleNoticeResult($notice);
            return;
        }

        \WP_CLI::error(sprintf(
            /* translators: %s: unknown profile action name. */
            __('Unknown profile action "%s".', 'postcaster'),
            $action
        ));
    }
}
