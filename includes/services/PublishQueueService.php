<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\PostMetaModel;
use Throwable;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class PublishQueueService
{
    public const ACTION_HOOK = 'justbee_postcaster_publish_target';
    private const GROUP_PREFIX = 'postcaster-post-';
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_SECONDS = 30;
    private const MAX_CONCURRENT_BATCHES = 3;

    private PublisherService $publisher;
    private PostMetaModel $postMeta;

    public function __construct(PublisherService $publisher, PostMetaModel $postMeta)
    {
        $this->publisher = $publisher;
        $this->postMeta = $postMeta;

        add_action(self::ACTION_HOOK, [$this, 'handlePublishTargetAction'], 10, 1);
        add_action('action_scheduler_failed_execution', [$this, 'handleSchedulerFailedExecution'], 10, 3);
        add_action('action_scheduler_failed_action', [$this, 'handleSchedulerTimedOutAction'], 10, 2);
        add_filter('action_scheduler_queue_runner_concurrent_batches', [$this, 'allowConcurrentBatches']);
    }

    public function allowConcurrentBatches(int $batches): int
    {
        return max($batches, self::MAX_CONCURRENT_BATCHES);
    }

    public function enqueuePublish(WP_Post $post, array $context = []): int
    {
        $jobs = $this->publisher->buildTargetJobs($post, $context);
        $scheduled = 0;

        foreach ($jobs as $job) {
            if ($this->hasPendingOrRunningAction($job['post_id'], $job['network_key'], $job['target_key'])) {
                continue;
            }

            $scheduled += as_enqueue_async_action(
                self::ACTION_HOOK,
                [$job],
                $this->getGroup($job['post_id'])
            ) > 0 ? 1 : 0;
        }

        return $scheduled;
    }

    public function handlePublishTargetAction(array $job): void
    {
        $postId = (int) ($job['post_id'] ?? 0);
        $networkKey = sanitize_key((string) ($job['network_key'] ?? ''));
        $targetKey = sanitize_key((string) ($job['target_key'] ?? ''));
        $attempt = max(1, (int) ($job['attempt'] ?? 1));
        $allowRepost = !empty($job['allow_repost']);

        if ($postId <= 0 || $networkKey === '' || $targetKey === '') {
            return;
        }

        $result = $this->publisher->publishQueuedTarget($postId, $networkKey, $targetKey, $allowRepost);
        if ($result['status'] !== 'failed') {
            return;
        }

        if (!$result['retryable']) {
            $this->postMeta->appendLog(
                $postId,
                sprintf(
                    '%s publish failed permanently for target %s; not scheduling a retry.',
                    $networkKey,
                    $targetKey
                )
            );
            return;
        }

        if ($attempt > self::MAX_RETRIES) {
            $this->postMeta->appendLog($postId, sprintf(
                /* translators: %d: maximum number of retry attempts. */
                __('Retry limit reached after %d attempts.', 'postcaster'),
                self::MAX_RETRIES
            ));
            return;
        }

        $retryAfter = (int) ($result['retry_after'] ?? 0);
        $delay = !empty($result['exact_retry_after'])
            ? max(1, $retryAfter)
            : max(
                self::RETRY_DELAY_SECONDS * (2 ** ($attempt - 1)),
                $retryAfter
            );

        $retryJob = $job;
        $retryJob['attempt'] = $attempt + 1;
        $retryJob['trigger'] = 'retry';

        as_schedule_single_action(
            time() + $delay,
            self::ACTION_HOOK,
            [$retryJob],
            $this->getGroup($postId)
        );

        $this->postMeta->appendLog($postId, sprintf(
            /* translators: 1: current retry attempt, 2: maximum retries, 3: seconds until next attempt. */
            __('Scheduling retry %1$d of %2$d in %3$d seconds.', 'postcaster'),
            $attempt,
            self::MAX_RETRIES,
            $delay
        ));
    }

    /**
     * @return array{retry_count:int,next_retry:?int,retry_limit_reached:bool}
     */
    public function getRetrySummaryForPost(int $postId): array
    {
        $maxAttempt = 1;
        $nextRetry = null;

        foreach ($this->getActionsForPost($postId, [
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING,
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_FAILED,
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_COMPLETE,
        ]) as $action) {
            $job = $this->extractJob($action);
            if ($job === null) {
                continue;
            }

            $attempt = max(1, (int) ($job['attempt'] ?? 1));
            $maxAttempt = max($maxAttempt, $attempt);

            if ($attempt <= 1) {
                continue;
            }

            $date = $action->get_schedule()->get_date();
            if ($date === null) {
                continue;
            }

            $timestamp = (int) $date->format('U');
            if ($timestamp <= time()) {
                continue;
            }

            if ($nextRetry === null || $timestamp < $nextRetry) {
                $nextRetry = $timestamp;
            }
        }

        return [
            'retry_count' => max(0, $maxAttempt - 1),
            'next_retry' => $nextRetry,
            'retry_limit_reached' => $nextRetry === null && max(0, $maxAttempt - 1) >= self::MAX_RETRIES,
        ];
    }

    public function clearQueuedActionsForPost(int $postId): void
    {
        as_unschedule_all_actions(self::ACTION_HOOK, [], $this->getGroup($postId));
    }

    /**
     * @return array<int, array{
     *   action_id:int,
     *   status:string,
     *   scheduled_at:?int,
     *   post_id:int,
     *   network_key:string,
     *   target_key:string,
     *   attempt:int,
     *   trigger:string
     * }>
     */
    public function getQueueRows(int $limit = 200): array
    {
        $limit = max(1, $limit);
        $actionIds = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'status' => [
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING,
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_FAILED,
                \Justbee_PostCaster_ActionScheduler_Store::STATUS_COMPLETE,
            ],
            'per_page' => max($limit * 5, $limit),
            'orderby' => 'date',
            'order' => 'DESC',
        ], 'ids');

        $rows = [];
        $store = \Justbee_PostCaster_ActionScheduler::store();
        $seenTargets = [];

        foreach (array_map('intval', $actionIds) as $actionId) {
            if ($actionId <= 0) {
                continue;
            }

            $action = $store->fetch_action($actionId);
            $job = $this->extractJob($action);
            if ($job === null) {
                continue;
            }

            $postId = (int) ($job['post_id'] ?? 0);
            $networkKey = sanitize_key((string) ($job['network_key'] ?? ''));
            $targetKey = sanitize_key((string) ($job['target_key'] ?? ''));
            $targetId = $postId . ':' . $networkKey . ':' . $targetKey;
            if (isset($seenTargets[$targetId])) {
                continue;
            }

            $scheduledAt = null;
            $date = $action->get_schedule()->get_date();
            if ($date !== null) {
                $scheduledAt = (int) $date->format('U');
            } else {
                // Async actions return a NullSchedule with no date. Fall
                // back to the action store's own scheduled-date so the
                // queue listing still shows a timestamp.
                try {
                    $storeDate = $store->get_date($actionId);
                    if ($storeDate instanceof \DateTime) {
                        $scheduledAt = (int) $storeDate->format('U');
                    }
                } catch (\Throwable $e) {
                    $scheduledAt = null;
                }
            }

            $seenTargets[$targetId] = true;
            $rows[] = [
                'action_id' => $actionId,
                'status' => (string) $store->get_status($actionId),
                'scheduled_at' => $scheduledAt,
                'post_id' => $postId,
                'network_key' => $networkKey,
                'target_key' => $targetKey,
                'attempt' => max(1, (int) ($job['attempt'] ?? 1)),
                'trigger' => sanitize_key((string) ($job['trigger'] ?? '')),
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    public function handleSchedulerFailedExecution(int $actionId, Throwable $error, string $context = ''): void
    {
        $job = $this->getJobByActionId($actionId);
        if ($job === null) {
            return;
        }

        $postId = (int) ($job['post_id'] ?? 0);
        $networkKey = sanitize_key((string) ($job['network_key'] ?? ''));
        $targetKey = sanitize_key((string) ($job['target_key'] ?? ''));
        if ($postId <= 0 || $networkKey === '' || $targetKey === '') {
            return;
        }

        $message = sprintf(
            '%s queue action #%d failed for target %s%s: %s',
            $networkKey,
            $actionId,
            $targetKey,
            $context !== '' ? ' via ' . $context : '',
            $error->getMessage()
        );
        $this->postMeta->appendLog($postId, $message);
    }

    public function handleSchedulerTimedOutAction(int $actionId, int $timeout): void
    {
        $job = $this->getJobByActionId($actionId);
        if ($job === null) {
            return;
        }

        $postId = (int) ($job['post_id'] ?? 0);
        $networkKey = sanitize_key((string) ($job['network_key'] ?? ''));
        $targetKey = sanitize_key((string) ($job['target_key'] ?? ''));
        if ($postId <= 0 || $networkKey === '' || $targetKey === '') {
            return;
        }

        $this->postMeta->appendLog($postId, sprintf(
            '%s queue action #%d timed out after %d seconds for target %s.',
            $networkKey,
            $actionId,
            $timeout,
            $targetKey
        ));
    }

    private function hasPendingOrRunningAction(int $postId, string $networkKey, string $targetKey): bool
    {
        foreach ($this->getActionsForPost($postId, [
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING,
        ]) as $action) {
            $job = $this->extractJob($action);
            if ($job === null) {
                continue;
            }

            if (
                (int) ($job['post_id'] ?? 0) === $postId
                && (string) ($job['network_key'] ?? '') === $networkKey
                && (string) ($job['target_key'] ?? '') === $targetKey
            ) {
                return true;
            }
        }

        return false;
    }

    private function getGroup(int $postId): string
    {
        return self::GROUP_PREFIX . $postId;
    }

    private function getJobByActionId(int $actionId): ?array
    {
        if ($actionId <= 0) {
            return null;
        }

        try {
            $action = \Justbee_PostCaster_ActionScheduler::store()->fetch_action($actionId);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_object($action) || !method_exists($action, 'get_hook') || $action->get_hook() !== self::ACTION_HOOK) {
            return null;
        }

        return $this->extractJob($action);
    }

    private function getActionsForPost(int $postId, array $statuses): array
    {
        return as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => $this->getGroup($postId),
            'status' => $statuses,
            'per_page' => 100,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
    }

    private function extractJob($action): ?array
    {
        if (!is_object($action) || !method_exists($action, 'get_args')) {
            return null;
        }

        $args = $action->get_args();
        $job = $args[0] ?? null;

        return is_array($job) ? $job : null;
    }
}
