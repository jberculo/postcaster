<?php

declare(strict_types=1);

use Justbee\PostCaster\Controllers\PublishController;
use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PostRenderer;
use Justbee\PostCaster\Services\PostTemplateContextBuilder;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\PublishTargetResolver;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateParser;
use Justbee\PostCaster\Templates\TemplateRenderer;

/**
 * Exercises the manual-publish scope resolution when BOTH global and personal
 * contexts are available — the only case where the form toggles matter.
 */
final class PublishScopeTest extends WP_UnitTestCase
{
    private FakeNetworkPublisher $fake;
    private PublisherService $publisher;
    private PostMetaModel $postMeta;
    private PublishController $controller;

    public function set_up(): void
    {
        parent::set_up();

        $this->fake = new FakeNetworkPublisher('fake');
        $networks = new NetworkRegistry([$this->fake]);

        $settings = new SettingsModel($networks);
        update_option(SettingsModel::OPTION_NAME, [
            'enabled' => '1',
            'personal_networks_enabled' => '1',
            'debug' => '0',
            'post_types' => ['post'],
            'template_enabled' => '1',
            'template' => '{title} {url}',
            $this->fake->optionKey('enabled') => '1',
            $this->fake->optionKey('template_enabled') => '0',
            $this->fake->optionKey('template') => '',
            $this->fake->optionKey('include_featured_image') => '0',
            $this->fake->optionKey('character_limit') => '500',
        ]);

        $profiles = new UserProfileModel($settings, $networks);
        $this->postMeta = new PostMetaModel();
        $media = new MediaService();
        $targets = new PublishTargetResolver($profiles, $networks, $settings);
        $contextBuilder = new PostTemplateContextBuilder($settings, $profiles, $networks);
        $descriptions = new TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $renderer = new PostRenderer(new TemplateRenderer(new TemplateParser(), new TemplateFitter()));

        $this->publisher = new PublisherService(
            $settings,
            $this->postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $descriptions,
            $renderer
        );

        $this->controller = new PublishController($this->publisher, $this->postMeta);
    }

    private function createPostWithPersonalEnabledAuthor(): WP_Post
    {
        $userId = self::factory()->user->create();
        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        return get_post(self::factory()->post->create([
            'post_status' => 'publish',
            'post_author' => $userId,
        ]));
    }

    private function invokeResolveScope(array $postData): array
    {
        $contexts = [
            'global' => ['scope' => 'global'],
            'personal' => ['scope' => 'personal'],
        ];

        $reflection = new ReflectionMethod($this->controller, 'resolveManualPublishScope');
        $reflection->setAccessible(true);

        return $reflection->invoke($this->controller, $contexts, $postData);
    }

    public function test_scope_resolves_to_global_only_when_scope_global(): void
    {
        $scope = $this->invokeResolveScope(['justbee_postcaster_publish_scope' => 'global']);

        $this->assertTrue($scope['include_global_networks']);
        $this->assertFalse($scope['include_personal_networks']);
    }

    public function test_scope_resolves_to_personal_only_when_scope_personal(): void
    {
        $scope = $this->invokeResolveScope(['justbee_postcaster_publish_scope' => 'personal']);

        $this->assertFalse($scope['include_global_networks']);
        $this->assertTrue($scope['include_personal_networks']);
    }

    public function test_scope_resolves_to_both_when_no_scope_specified(): void
    {
        $scope = $this->invokeResolveScope([]);

        $this->assertTrue($scope['include_global_networks']);
        $this->assertTrue($scope['include_personal_networks']);
    }

    public function test_scope_resolves_only_network_key_when_specified(): void
    {
        $scope = $this->invokeResolveScope([
            'justbee_postcaster_publish_scope' => 'global',
            'justbee_postcaster_publish_network' => 'fake',
        ]);

        $this->assertSame('fake', $scope['only_network_key'] ?? '');
    }

    public function test_publishing_with_global_only_skips_personal_target(): void
    {
        $post = $this->createPostWithPersonalEnabledAuthor();

        $this->publisher->publishPost($post, [
            'include_global_networks' => true,
            'include_personal_networks' => false,
            'allow_repost' => true,
        ]);

        $this->assertCount(1, $this->fake->publishedCalls, 'Only the global target should be hit.');
    }

    public function test_publishing_with_personal_only_skips_global_target(): void
    {
        $post = $this->createPostWithPersonalEnabledAuthor();

        $this->publisher->publishPost($post, [
            'include_global_networks' => false,
            'include_personal_networks' => true,
            'allow_repost' => true,
        ]);

        $this->assertCount(1, $this->fake->publishedCalls, 'Only the personal target should be hit.');
    }

    public function test_publishing_with_both_hits_global_and_personal(): void
    {
        $post = $this->createPostWithPersonalEnabledAuthor();

        $this->publisher->publishPost($post, [
            'include_global_networks' => true,
            'include_personal_networks' => true,
            'allow_repost' => true,
        ]);

        $this->assertCount(2, $this->fake->publishedCalls, 'Both targets should be hit when both flags are true.');
    }
}
