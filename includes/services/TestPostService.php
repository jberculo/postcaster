<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\DebugLogModel;

if (!defined('ABSPATH')) {
    exit;
}

final class TestPostService
{
    private NetworkRegistry $networks;
    private DebugLogModel $debugLog;
    private PublisherService $publisher;

    public function __construct(NetworkRegistry $networks, DebugLogModel $debugLog, PublisherService $publisher)
    {
        $this->networks = $networks;
        $this->debugLog = $debugLog;
        $this->publisher = $publisher;
    }

    public function send(string $networkKey, array $options, array $context = []): array
    {
        $scope = (string) ($context['scope'] ?? 'general');
        $previewScope = (string) ($context['preview_scope'] ?? 'global');
        $userId = isset($context['user_id']) ? (int) $context['user_id'] : 0;
        $network = $this->networks->get($networkKey);
        if (!$network) {
            $this->log(sprintf('Test connection [%s/%s] failed: unknown network.', $scope, $networkKey));
            return [
                'type' => 'error',
                'message' => __('Unknown PostCaster network selected for test post.', 'postcaster'),
            ];
        }

        $this->log(sprintf('Test connection [%s/%s] started.', $scope, $network->getKey()));
        $text = $this->publisher->buildTestStatusText($network->getKey(), $options, $previewScope, $userId);
        $publishContext = $this->publisher->buildTestPublishContext($network->getKey(), $options, $previewScope, $userId);
        $result = $network->publishTest(
            $options,
            $text,
            $publishContext
        );
        if (is_wp_error($result)) {
            $this->log(sprintf(
                'Test connection [%s/%s] failed: %s',
                $scope,
                $network->getKey(),
                $result->get_error_message()
            ));
            return [
                'type' => 'error',
                'message' => sprintf(
                    /* translators: 1: social network label, 2: error message. */
                    __('PostCaster test post to %1$s failed: %2$s', 'postcaster'),
                    $network->getLabel(),
                    $result->get_error_message()
                ),
            ];
        }

        $this->log(sprintf('Test connection [%s/%s] succeeded.', $scope, $network->getKey()));
        return [
            'type' => 'success',
            'message' => sprintf(
                /* translators: %s: social network label. */
                __('PostCaster test post to %s succeeded.', 'postcaster'),
                $network->getLabel()
            ),
        ];
    }

    private function log(string $message): void
    {
        $this->debugLog->append($message);
    }
}
