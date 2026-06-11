<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\PublishQueueService;

final class HandleAsyncPublishTest extends WP_UnitTestCase
{
    use BuildsPublisherStack;

    private const ACTION_HOOK = PublishQueueService::ACTION_HOOK;
    private const RETRY_BASE = 30;

    public function set_up(): void
    {
        parent::set_up();
        $this->buildPublisherStack();
        as_unschedule_all_actions(self::ACTION_HOOK);
    }

    public function tear_down(): void
    {
        as_unschedule_all_actions(self::ACTION_HOOK);
        parent::tear_down();
    }

    public function test_successful_publish_does_not_queue_retry(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->queue->handlePublishTargetAction($this->buildJob($postId));

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertSame(0, $summary['retry_count']);
        $this->assertNull($summary['next_retry']);
    }

    public function test_postcaster_raises_action_scheduler_concurrent_batches_floor(): void
    {
        $this->assertSame(3, (int) apply_filters('action_scheduler_queue_runner_concurrent_batches', 1));
        $this->assertSame(5, (int) apply_filters('action_scheduler_queue_runner_concurrent_batches', 5));
    }

    public function test_failure_schedules_retry_with_exponential_backoff(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected', ['retryable' => true]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $before = time();
        $this->queue->handlePublishTargetAction($this->buildJob($postId, 1));
        $after = time();

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertSame(1, $summary['retry_count']);
        $this->assertIsInt($summary['next_retry']);
        $this->assertGreaterThanOrEqual($before + self::RETRY_BASE, $summary['next_retry']);
        $this->assertLessThanOrEqual($after + self::RETRY_BASE, $summary['next_retry']);
    }

    public function test_second_failure_doubles_backoff(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected', ['retryable' => true]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $before = time();
        $this->queue->handlePublishTargetAction($this->buildJob($postId, 2));
        $after = time();

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertSame(2, $summary['retry_count']);
        $this->assertIsInt($summary['next_retry']);
        $this->assertGreaterThanOrEqual($before + (self::RETRY_BASE * 2), $summary['next_retry']);
        $this->assertLessThanOrEqual($after + (self::RETRY_BASE * 2), $summary['next_retry']);
    }

    public function test_third_failure_quadruples_backoff(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected', ['retryable' => true]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $before = time();
        $this->queue->handlePublishTargetAction($this->buildJob($postId, 3));
        $after = time();

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertSame(3, $summary['retry_count']);
        $this->assertIsInt($summary['next_retry']);
        $this->assertGreaterThanOrEqual($before + (self::RETRY_BASE * 4), $summary['next_retry']);
        $this->assertLessThanOrEqual($after + (self::RETRY_BASE * 4), $summary['next_retry']);
    }

    public function test_retry_limit_logs_failure_message(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected', ['retryable' => true]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->queue->handlePublishTargetAction($this->buildJob($postId, 4));

        $logs = $this->postMeta->getLog($postId);
        $this->assertNotEmpty($logs);
        $combined = implode(' ', array_map(static fn($entry) => (string) $entry, $logs));
        $this->assertStringContainsString('Retry limit reached', $combined);
    }

    public function test_retry_limit_stops_scheduling_further_retries(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected', ['retryable' => true]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->queue->handlePublishTargetAction($this->buildJob($postId, 4));

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertNull($summary['next_retry']);
    }

    public function test_non_published_post_is_skipped(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);

        $this->queue->handlePublishTargetAction($this->buildJob($postId));

        $this->assertCount(0, $this->fake->publishedCalls);
    }

    public function test_missing_post_is_silently_ignored(): void
    {
        $this->queue->handlePublishTargetAction($this->buildJob(999999));

        $this->assertCount(0, $this->fake->publishedCalls);
        $this->addToAssertionCount(1);
    }

    public function test_disabled_article_is_skipped_without_retry(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        $this->queue->handlePublishTargetAction($this->buildJob($postId));

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertCount(0, $this->fake->publishedCalls);
        $this->assertSame(0, $summary['retry_count']);
        $this->assertNull($summary['next_retry']);
    }

    public function test_permanent_failure_does_not_schedule_retry(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected');
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $this->queue->handlePublishTargetAction($this->buildJob($postId));

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertSame(0, $summary['retry_count']);
        $this->assertNull($summary['next_retry']);
    }

    public function test_existing_target_lock_schedules_short_retry_for_async_publish(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $lockToken = $this->postMeta->acquirePublishLock($postId, 'fake', 'global');

        $this->assertNotNull($lockToken);

        $before = time();
        $this->queue->handlePublishTargetAction($this->buildJob($postId, 1));
        $after = time();

        $summary = $this->queue->getRetrySummaryForPost($postId);
        $this->assertCount(0, $this->fake->publishedCalls);
        $this->assertSame(1, $summary['retry_count']);
        $this->assertIsInt($summary['next_retry']);
        $this->assertGreaterThanOrEqual($before + 5, $summary['next_retry']);
        $this->assertLessThanOrEqual($after + 5, $summary['next_retry']);

        $this->postMeta->releasePublishLock($postId, 'fake', 'global', $lockToken);
    }

    public function test_queue_rows_show_only_latest_action_per_target(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        as_schedule_single_action(time() - 120, self::ACTION_HOOK, [$this->buildJob($postId, 1)], 'postcaster-post-' . $postId);
        as_schedule_single_action(time() - 60, self::ACTION_HOOK, [$this->buildJob($postId, 2)], 'postcaster-post-' . $postId);

        $actionIds = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 20,
            'orderby' => 'date',
            'order' => 'ASC',
        ], 'ids');

        $store = \Justbee_PostCaster_ActionScheduler::store();
        $store->mark_failure((int) $actionIds[0]);
        $store->mark_complete((int) $actionIds[1]);

        $rows = $this->queue->getQueueRows();

        $this->assertCount(1, $rows);
        $this->assertSame('complete', $rows[0]['status']);
        $this->assertSame(2, $rows[0]['attempt']);
    }

    public function test_failed_execution_hook_logs_exception_details_for_justbee_postcaster_actions(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        as_schedule_single_action(time() - 60, self::ACTION_HOOK, [$this->buildJob($postId, 1)], 'postcaster-post-' . $postId);
        $actionIds = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 10,
        ], 'ids');

        $this->queue->handleSchedulerFailedExecution((int) $actionIds[0], new RuntimeException('Socket closed'), 'WP Cron');

        $logs = $this->postMeta->getLog($postId);
        $combined = implode(' ', $logs);
        $this->assertStringContainsString('queue action', $combined);
        $this->assertStringContainsString('Socket closed', $combined);
        $this->assertStringContainsString('WP Cron', $combined);
    }

    public function test_failed_action_hook_logs_timeout_details_for_justbee_postcaster_actions(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        as_schedule_single_action(time() - 60, self::ACTION_HOOK, [$this->buildJob($postId, 1)], 'postcaster-post-' . $postId);
        $actionIds = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 10,
        ], 'ids');

        $this->queue->handleSchedulerTimedOutAction((int) $actionIds[0], 300);

        $logs = $this->postMeta->getLog($postId);
        $combined = implode(' ', $logs);
        $this->assertStringContainsString('timed out after 300 seconds', $combined);
    }

    private function buildJob(int $postId, int $attempt = 1): array
    {
        return [
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => $attempt,
            'trigger' => $attempt > 1 ? 'retry' : 'auto_publish',
        ];
    }
}
