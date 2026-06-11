<?php

declare(strict_types=1);

use Justbee\PostCaster\Controllers\AdminController;
use Justbee\PostCaster\Models\DebugLogModel;
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
use Justbee\PostCaster\Services\TestPostService;
use Justbee\PostCaster\Support\TemplateEditorFieldDecorator;
use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateParser;
use Justbee\PostCaster\Templates\TemplateRenderer;

final class AdminQueueStatusTest extends WP_UnitTestCase
{
    private PostMetaModel $postMeta;
    private PublishQueueService $queue;
    private AdminController $controller;

    public function set_up(): void
    {
        parent::set_up();

        $fake = new FakeNetworkPublisher('fake');
        $networks = new NetworkRegistry([$fake]);
        $settings = new SettingsModel($networks);
        update_option(SettingsModel::OPTION_NAME, [
            'enabled' => '1',
            'personal_networks_enabled' => '0',
            'debug' => '0',
            'post_types' => ['post'],
            'template_enabled' => '1',
            'template' => $settings->getDefaultTemplate(),
            $fake->optionKey('enabled') => '1',
            $fake->optionKey('template_enabled') => '0',
            $fake->optionKey('template') => '',
            $fake->optionKey('include_featured_image') => '0',
            $fake->optionKey('character_limit') => '500',
        ]);

        $profiles = new UserProfileModel($settings, $networks);
        $this->postMeta = new PostMetaModel();
        $media = new MediaService();
        $targets = new PublishTargetResolver($profiles, $networks, $settings);
        $contextBuilder = new PostTemplateContextBuilder($settings, $profiles, $networks);
        $templates = new TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $renderer = new PostRenderer(new TemplateRenderer(new TemplateParser(), new TemplateFitter()));
        $publisher = new PublisherService(
            $settings,
            $this->postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $templates,
            $renderer
        );
        $this->queue = new PublishQueueService($publisher, $this->postMeta);

        $this->controller = new AdminController(
            $settings,
            $networks,
            $this->postMeta,
            new DebugLogModel(),
            $publisher,
            $this->queue,
            $profiles,
            $templates,
            new TemplateEditorFieldDecorator(),
            new TestPostService($networks, new DebugLogModel(), $publisher),
            dirname(__DIR__, 2) . '/views'
        );
    }

    public function tear_down(): void
    {
        as_unschedule_all_actions(PublishQueueService::ACTION_HOOK);
        parent::tear_down();
    }

    public function test_failed_scheduler_row_with_saved_publication_is_rendered_as_published(): void
    {
        $postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Queued post',
        ]);

        as_schedule_single_action(time() - 60, PublishQueueService::ACTION_HOOK, [[
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => 1,
            'trigger' => 'auto_publish',
        ]], 'postcaster-post-' . $postId);

        $actionIds = as_get_scheduled_actions([
            'hook' => PublishQueueService::ACTION_HOOK,
            'group' => 'postcaster-post-' . $postId,
            'status' => \Justbee_PostCaster_ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 10,
        ], 'ids');

        $this->assertNotEmpty($actionIds);
        \Justbee_PostCaster_ActionScheduler::store()->mark_failure((int) $actionIds[0]);
        $this->postMeta->saveSuccess($postId, 'fake', 'global', [
            'id' => 'remote-id',
            'url' => 'https://example.test/post/1',
        ]);

        $method = new ReflectionMethod(AdminController::class, 'buildQueueRows');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller, 20);

        $this->assertCount(1, $rows);
        $this->assertSame('Published', $rows[0]['status']);
        $this->assertSame('', $rows[0]['error_message']);
    }
}
