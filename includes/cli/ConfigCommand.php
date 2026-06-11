<?php

namespace Justbee\PostCaster\Cli;

if (!defined('ABSPATH')) {
    exit;
}

final class ConfigCommand extends AbstractCliCommand
{
    public function status(): void
    {
        $options = $this->settings->get();

        \WP_CLI::log(sprintf(
            /* translators: %s: whether global publishing is enabled or disabled. */
            __('Global publishing: %s', 'postcaster'),
            $this->formatState(($options['enabled'] ?? '0') === '1')
        ));
        foreach ($this->networks->all() as $network) {
            \WP_CLI::log(sprintf(
                '%s: %s',
                $network->getLabel(),
                $this->formatState(($options[$network->optionKey('enabled')] ?? '0') === '1')
            ));
        }
    }

    public function enable(array $args): void
    {
        $this->setState($args[0] ?? null, true);
    }

    public function disable(array $args): void
    {
        $this->setState($args[0] ?? null, false);
    }

    public function doctor(): void
    {
        $options = $this->settings->get();

        \WP_CLI::log(sprintf(
            /* translators: %s: whether global publishing is enabled or disabled. */
            __('Global publishing: %s', 'postcaster'),
            $this->formatState(($options['enabled'] ?? '0') === '1')
        ));

        foreach ($this->networks->all() as $network) {
            $enabled = ($options[$network->optionKey('enabled')] ?? '0') === '1';
            \WP_CLI::log(sprintf('%s: %s', $network->getLabel(), $this->formatState($enabled)));
            if (!$enabled) {
                continue;
            }

            foreach ($network->getAdminFields() as $field) {
                $key = (string) ($field['key'] ?? '');
                if ($key === '' || ($field['type'] ?? '') === 'checkbox') {
                    continue;
                }

                $value = trim((string) ($options[$key] ?? ''));
                if ($value === '') {
                    \WP_CLI::warning(sprintf(
                        /* translators: 1: social network label, 2: option key. */
                        __('%1$s is enabled but "%2$s" is empty.', 'postcaster'),
                        $network->getLabel(),
                        $key
                    ));
                }
            }
        }
    }

    private function setState(?string $networkKey, bool $enabled): void
    {
        if ($networkKey === null || $networkKey === '') {
            $this->settings->update(['enabled' => $enabled ? '1' : '0']);
            \WP_CLI::success(sprintf(
                /* translators: %s: whether global publishing was enabled or disabled. */
                __('Global publishing %s.', 'postcaster'),
                $enabled ? 'enabled' : 'disabled'
            ));
            return;
        }

        $network = $this->getNetworkOrExit($networkKey);
        $this->settings->update([$network->optionKey('enabled') => $enabled ? '1' : '0']);
        \WP_CLI::success(sprintf(
            /* translators: 1: social network label, 2: whether publishing was enabled or disabled. */
            __('%1$s %2$s.', 'postcaster'),
            $network->getLabel(),
            $enabled ? 'enabled' : 'disabled'
        ));
    }
}
