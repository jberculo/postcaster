<?php

declare(strict_types=1);

use Justbee\PostCaster\Controllers\AdminController;
use Justbee\PostCaster\Controllers\PublishController;
use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PostRenderer;
use Justbee\PostCaster\Services\PostTemplateContextBuilder;
use Justbee\PostCaster\Services\PublishQueueService;
use Justbee\PostCaster\Services\PublishTargetResolver;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateParser;
use Justbee\PostCaster\Templates\TemplateRenderer;

final class PublishSchedulingTest extends WP_UnitTestCase
{
    use BuildsPublisherStack;

    private const ACTION_HOOK = PublishQueueService::ACTION_HOOK;

    private PublishController $controller;

    public function set_up(): void
    {
        parent::set_up();
        $this->buildPublisherStack();
        $this->controller = new PublishController($this->publisher, $this->postMeta, $this->queue);

        remove_action('wp_after_insert_post', [$this->controller, 'handleAfterInsertPost'], 10);
        remove_action('add_meta_boxes', [$this->controller, 'registerMetaBox'], 10);
        remove_action('save_post', [$this->controller, 'saveMetaBox'], 10);
        remove_action('admin_post_justbee_postcaster_publish_now', [$this->controller, 'handlePublishNow']);
        remove_action('wp_ajax_justbee_postcaster_preview_post_template', [$this->controller, 'handlePreviewPostTemplate']);
        remove_action('wp_ajax_justbee_postcaster_preview_template_example', [$this->controller, 'handlePreviewTemplateExample']);

        as_unschedule_all_actions(self::ACTION_HOOK);
    }

    public function tear_down(): void
    {
        as_unschedule_all_actions(self::ACTION_HOOK);
        parent::tear_down();
    }

    public function test_fresh_publish_queues_background_action(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->controller->handleAfterInsertPost($postId, $post, false, null);

        $this->assertTrue($this->hasQueuedActionForPost($postId));
    }

    public function test_draft_to_publish_transition_queues(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $previous = clone $post;
        $previous->post_status = 'draft';

        $this->controller->handleAfterInsertPost($postId, $post, true, $previous);

        $this->assertTrue($this->hasQueuedActionForPost($postId));
    }

    public function test_future_to_publish_transition_queues(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $previous = clone $post;
        $previous->post_status = 'future';

        $this->controller->handleAfterInsertPost($postId, $post, true, $previous);

        $this->assertTrue($this->hasQueuedActionForPost($postId));
    }

    public function test_existing_queue_is_not_duplicated(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        as_enqueue_async_action(self::ACTION_HOOK, [[
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => 1,
            'trigger' => 'auto_publish',
        ]], 'postcaster-post-' . $postId);

        $previous = clone $post;
        $previous->post_status = 'draft';
        $this->controller->handleAfterInsertPost($postId, $post, true, $previous);

        $actions = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => [\Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING, \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING],
            'per_page' => 20,
        ], 'ids');

        $this->assertCount(1, $actions);
    }

    public function test_publish_to_publish_does_not_queue(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $previous = clone $post;
        $previous->post_status = 'publish';

        $this->controller->handleAfterInsertPost($postId, $post, true, $previous);

        $this->assertFalse($this->hasQueuedActionForPost($postId));
    }

    public function test_non_publish_status_does_not_queue(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);
        $post = get_post($postId);

        $this->controller->handleAfterInsertPost($postId, $post, false, null);

        $this->assertFalse($this->hasQueuedActionForPost($postId));
    }

    public function test_revision_is_ignored(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $revisionId = wp_save_post_revision($postId);

        if (!$revisionId) {
            wp_update_post(['ID' => $postId, 'post_content' => 'initial']);
            wp_update_post(['ID' => $postId, 'post_content' => 'updated']);
            $revisions = wp_get_post_revisions($postId);
            $revisionId = array_key_first($revisions) ?: 0;
        }

        if (!$revisionId) {
            $this->markTestSkipped('Could not create a revision on this WordPress build.');
        }

        $revision = get_post($revisionId);
        $this->controller->handleAfterInsertPost($revisionId, $revision, true, null);

        $this->assertFalse($this->hasQueuedActionForPost($revisionId));
    }

    public function test_disabled_plugin_queues_warning_notice_instead_of_scheduling(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        update_option(\Justbee\PostCaster\Models\SettingsModel::OPTION_NAME, array_merge(
            get_option(\Justbee\PostCaster\Models\SettingsModel::OPTION_NAME),
            ['enabled' => '0']
        ));

        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->controller->handleAfterInsertPost($postId, $post, false, null);

        $this->assertFalse($this->hasQueuedActionForPost($postId));

        $notice = AdminController::consumePostNotice($postId, $userId);
        $this->assertIsArray($notice);
        $this->assertSame('warning', $notice['type']);
        $this->assertStringContainsString('PostCaster did not schedule this post:', (string) $notice['message']);
        $this->assertStringContainsString('disabled in the general settings', (string) $notice['message']);
    }

    public function test_post_level_disable_blocks_scheduling(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->controller->handleAfterInsertPost($postId, $post, false, null);

        $this->assertFalse($this->hasQueuedActionForPost($postId));
    }

    public function test_existing_job_only_dedupes_matching_target_and_keeps_other_targets(): void
    {
        $controller = $this->buildControllerWithNetworks(['fake', 'second']);

        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        as_enqueue_async_action(self::ACTION_HOOK, [[
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => 1,
            'trigger' => 'auto_publish',
        ]], 'postcaster-post-' . $postId);

        $previous = clone $post;
        $previous->post_status = 'draft';
        $controller->handleAfterInsertPost($postId, $post, true, $previous);

        $actionIds = as_get_scheduled_actions([
            'hook' => self::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => [\Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING, \Justbee_PostCaster_ActionScheduler_Store::STATUS_RUNNING],
            'per_page' => 20,
            'orderby' => 'date',
            'order' => 'ASC',
        ], 'ids');

        $this->assertCount(2, $actionIds);

        $jobsByNetwork = [];
        $store = \Justbee_PostCaster_ActionScheduler::store();
        foreach (array_map('intval', $actionIds) as $actionId) {
            $action = $store->fetch_action($actionId);
            $args = $action->get_args();
            $job = is_array($args[0] ?? null) ? $args[0] : [];
            $jobsByNetwork[(string) ($job['network_key'] ?? '')] = $job;
        }

        $this->assertArrayHasKey('fake', $jobsByNetwork);
        $this->assertArrayHasKey('second', $jobsByNetwork);
        $this->assertSame('global', $jobsByNetwork['fake']['target_key']);
        $this->assertSame('global', $jobsByNetwork['second']['target_key']);

        as_unschedule_all_actions(self::ACTION_HOOK, [], 'postcaster-post-' . $postId);
    }

    private function hasQueuedActionForPost(int $postId): bool
    {
        return as_has_scheduled_action(self::ACTION_HOOK, null, 'postcaster-post-' . $postId);
    }

    /**
     * @return PublishController
     */
    private function buildControllerWithNetworks(array $networkKeys): PublishController
    {
        $publishers = [];
        foreach ($networkKeys as $networkKey) {
            $publishers[] = new FakeNetworkPublisher($networkKey);
        }

        $networks = new NetworkRegistry($publishers);
        $settings = new SettingsModel($networks);
        $options = [
            'enabled' => '1',
            'personal_networks_enabled' => '0',
            'debug' => '0',
            'post_types' => ['post'],
            'template_enabled' => '1',
            'template' => $settings->getDefaultTemplate(),
        ];

        foreach ($publishers as $publisher) {
            $options[$publisher->optionKey('enabled')] = '1';
            $options[$publisher->optionKey('template_enabled')] = '0';
            $options[$publisher->optionKey('template')] = '';
            $options[$publisher->optionKey('include_featured_image')] = '0';
            $options[$publisher->optionKey('character_limit')] = '500';
        }

        update_option(SettingsModel::OPTION_NAME, $options);

        $profiles = new UserProfileModel($settings, $networks);
        $postMeta = new PostMetaModel();
        $media = new MediaService();
        $targets = new PublishTargetResolver($profiles, $networks, $settings);
        $contextBuilder = new PostTemplateContextBuilder($settings, $profiles, $networks);
        $descriptions = new TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $renderer = new PostRenderer(new TemplateRenderer(new TemplateParser(), new TemplateFitter()));
        $publisher = new PublisherService(
            $settings,
            $postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $descriptions,
            $renderer
        );
        $queue = new PublishQueueService($publisher, $postMeta);
        $controller = new PublishController($publisher, $postMeta, $queue);

        remove_action('wp_after_insert_post', [$controller, 'handleAfterInsertPost'], 10);
        remove_action('add_meta_boxes', [$controller, 'registerMetaBox'], 10);
        remove_action('save_post', [$controller, 'saveMetaBox'], 10);
        remove_action('admin_post_justbee_postcaster_publish_now', [$controller, 'handlePublishNow']);
        remove_action('wp_ajax_justbee_postcaster_preview_post_template', [$controller, 'handlePreviewPostTemplate']);
        remove_action('wp_ajax_justbee_postcaster_preview_template_example', [$controller, 'handlePreviewTemplateExample']);

        return $controller;
    }
}
