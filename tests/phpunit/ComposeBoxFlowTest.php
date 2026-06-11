<?php

declare(strict_types=1);

use Justbee\PostCaster\Controllers\PublishController;
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

final class ComposeBoxFlowTest extends WP_UnitTestCase
{
    use \BuildsPublisherStack;

    public function test_save_meta_box_persists_per_network_template_override(): void
    {
        $this->buildPublisherStack();
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create(['post_status' => 'draft']);
        $post = get_post($postId);

        $_POST = [
            'justbee_postcaster_post_nonce' => wp_create_nonce('justbee_postcaster_post_settings'),
            'justbee_postcaster_post_drafts' => [
                'global' => [
                    'combined' => '',
                    'networks' => [
                        'fake' => [
                            'template' => 'Custom fake-only text',
                        ],
                    ],
                ],
            ],
        ];

        $controller->saveMetaBox($postId, $post);

        $this->assertSame(
            'Custom fake-only text',
            $this->postMeta->getNetworkPostTemplate($postId, 'global', 'fake')
        );
        $this->assertSame('', $this->postMeta->getPostTemplate($postId, 'global'));

        unset($_POST);
    }

    public function test_save_meta_box_persists_scope_and_network_featured_image_overrides(): void
    {
        $this->buildPublisherStack();
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create(['post_status' => 'draft']);
        $post = get_post($postId);

        $_POST = [
            'justbee_postcaster_post_nonce' => wp_create_nonce('justbee_postcaster_post_settings'),
            'justbee_postcaster_post_drafts' => [
                'global' => [
                    'include_featured_image' => '1',
                    'networks' => [
                        'fake' => [
                            'include_featured_image' => '0',
                        ],
                    ],
                ],
            ],
        ];

        $controller->saveMetaBox($postId, $post);

        $this->assertSame('1', $this->postMeta->getIncludeFeaturedImageScopeOverride($postId, 'global'));
        $this->assertSame('0', $this->postMeta->getIncludeFeaturedImageNetworkOverride($postId, 'global', 'fake'));

        // Network override beats scope, scope beats default.
        $this->assertFalse($this->postMeta->resolveIncludeFeaturedImage($postId, 'global', 'fake', false));

        unset($_POST);
    }

    public function test_render_meta_box_exposes_per_network_preview_in_compose_data(): void
    {
        $this->buildPublisherStack();
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Network preview source',
            'post_status' => 'draft',
        ]);
        $post = get_post($postId);

        ob_start();
        $controller->renderMetaBox($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-postcaster-network-select', $output);
        $this->assertStringContainsString('data-postcaster-network="fake"', $output);
        $this->assertStringContainsString('justbee_postcaster_post_drafts[global][networks][fake][template]', $output);
    }

    public function test_publish_now_with_only_network_key_targets_just_that_network(): void
    {
        $this->buildPublisherStack();
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create(['post_status' => 'publish']);

        // Drive the inner resolver via reflection — we don't go through the full HTTP path here.
        $reflection = new ReflectionMethod($controller, 'resolveManualPublishScope');
        $reflection->setAccessible(true);

        $scope = $reflection->invoke($controller, [
            'global' => ['scope' => 'global'],
            'personal' => ['scope' => 'personal'],
        ], [
            'justbee_postcaster_publish_scope' => 'global',
            'justbee_postcaster_publish_network' => 'fake',
        ]);

        $this->assertTrue($scope['include_global_networks']);
        $this->assertFalse($scope['include_personal_networks']);
        $this->assertSame('fake', $scope['only_network_key'] ?? '');
    }

    public function test_publisher_service_only_network_key_skips_other_networks(): void
    {
        $this->buildPublisherStack();

        $secondFake = new FakeNetworkPublisher('other');
        // Replace the registry with two networks.
        $this->publisher = $this->buildTwoFakePublisher($secondFake);

        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->publisher->publishPost($post, [
            'only_network_key' => 'fake',
            'allow_repost' => true,
            'ignore_post_disable' => true,
        ]);

        $this->assertCount(1, $this->fake->publishedCalls, 'fake should be published to');
        $this->assertCount(0, $secondFake->publishedCalls, 'other network must be skipped');
    }

    private function buildTwoFakePublisher(FakeNetworkPublisher $second): PublisherService
    {
        $networks = new NetworkRegistry([$this->fake, $second]);
        $settings = new SettingsModel($networks);
        update_option(SettingsModel::OPTION_NAME, array_merge((array) get_option(SettingsModel::OPTION_NAME, []), [
            $second->optionKey('enabled') => '1',
        ]));
        $profiles = new UserProfileModel($settings, $networks);
        $media = new MediaService();
        $contextBuilder = new PostTemplateContextBuilder($settings, $profiles, $networks);
        $targets = new PublishTargetResolver($profiles, $networks, $settings);
        $templateDescriptions = new TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $templateRenderer = new TemplateRenderer(new TemplateParser(), new TemplateFitter());
        $postRenderer = new PostRenderer($templateRenderer);

        return new PublisherService(
            $settings,
            $this->postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $templateDescriptions,
            $postRenderer
        );
    }
}
