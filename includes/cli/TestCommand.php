<?php

namespace Justbee\PostCaster\Cli;

if (!defined('ABSPATH')) {
    exit;
}

final class TestCommand extends AbstractCliCommand
{
    public function test(array $args): void
    {
        $network = $this->getNetworkOrExit((string) ($args[0] ?? ''));
        $notice = $this->tests->send($network->getKey(), $this->settings->get(), [
            'type' => 'general',
            'scope' => 'cli:general',
        ]);
        $this->handleNoticeResult($notice);
    }
}
