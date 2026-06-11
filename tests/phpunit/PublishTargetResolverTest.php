<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PublishTargetResolver;

final class PublishTargetResolverTest extends WP_UnitTestCase
{
    private PublishTargetResolver $resolver;
    private UserProfileModel $profiles;
    private SettingsModel $settings;
    private FakeNetworkPublisher $fake;

    public function set_up(): void
    {
        parent::set_up();

        $this->fake = new FakeNetworkPublisher('fake');
        $networks = new NetworkRegistry([$this->fake]);
        $this->settings = new SettingsModel($networks);
        $this->profiles = new UserProfileModel($this->settings, $networks);
        $this->resolver = new PublishTargetResolver($this->profiles, $networks, $this->settings);
    }

    private function createPost(int $authorId): WP_Post
    {
        return get_post(self::factory()->post->create(['post_author' => $authorId]));
    }

    public function test_global_target_included_when_network_is_enabled_and_configured(): void
    {
        $post = $this->createPost(self::factory()->user->create());
        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '1',
        ];

        $targets = $this->resolver->getTargets($post, $options);

        $this->assertArrayHasKey('fake', $targets);
        $this->assertArrayHasKey('global', $targets['fake']);
    }

    public function test_global_target_omitted_when_network_disabled(): void
    {
        $post = $this->createPost(self::factory()->user->create());
        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '0',
        ];

        $this->assertSame([], $this->resolver->getTargets($post, $options));
    }

    public function test_global_target_omitted_when_network_reports_unconfigured(): void
    {
        $post = $this->createPost(self::factory()->user->create());
        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '1',
            'mark_as_unconfigured' => true,
        ];

        $unconfigured = new class ('fake') extends FakeNetworkPublisher {
            public function isConfigured(array $options): bool
            {
                return empty($options['mark_as_unconfigured']);
            }
        };
        $networks = new NetworkRegistry([$unconfigured]);
        $resolver = new PublishTargetResolver($this->profiles, $networks, $this->settings);

        $this->assertSame([], $resolver->getTargets($post, $options));
    }

    public function test_include_global_false_skips_global_targets(): void
    {
        $post = $this->createPost(self::factory()->user->create());
        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '1',
        ];

        $this->assertSame([], $this->resolver->getTargets($post, $options, true, false));
    }

    public function test_personal_target_requires_profile_enabled_flag(): void
    {
        $userId = self::factory()->user->create();
        $post = $this->createPost($userId);

        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $options = [
            'personal_networks_enabled' => '1',
            $this->fake->optionKey('enabled') => '0',
        ];

        $targets = $this->resolver->getTargets($post, $options);

        $this->assertArrayHasKey('fake', $targets);
        $this->assertArrayHasKey('user_' . $userId, $targets['fake']);
    }

    public function test_personal_networks_disabled_at_plugin_level_blocks_personal_targets(): void
    {
        $userId = self::factory()->user->create();
        $post = $this->createPost($userId);

        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '0',
        ];

        $this->assertSame([], $this->resolver->getTargets($post, $options));
    }

    public function test_personal_target_omitted_when_network_specific_flag_off(): void
    {
        $userId = self::factory()->user->create();
        $post = $this->createPost($userId);

        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        // Note: no network-specific enabled flag set.

        $options = [
            'personal_networks_enabled' => '1',
            $this->fake->optionKey('enabled') => '0',
        ];

        $this->assertSame([], $this->resolver->getTargets($post, $options));
    }

    public function test_personal_target_omitted_when_network_not_available_to_authors(): void
    {
        $userId = self::factory()->user->create();
        $post = $this->createPost($userId);

        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $options = [
            'personal_networks_enabled' => '1',
            'personal_network_available_fake' => '0',
        ];

        $this->assertSame([], $this->resolver->getTargets($post, $options, true, false));
    }

    public function test_has_configured_global_targets_true_when_network_enabled(): void
    {
        $this->assertTrue($this->resolver->hasConfiguredGlobalTargets([
            $this->fake->optionKey('enabled') => '1',
        ]));
    }

    public function test_has_configured_global_targets_false_when_no_network_enabled(): void
    {
        $this->assertFalse($this->resolver->hasConfiguredGlobalTargets([]));
    }

    public function test_is_publishing_user_for_post_matches_author(): void
    {
        $userId = self::factory()->user->create();
        $post = $this->createPost($userId);

        $this->assertTrue($this->resolver->isPublishingUserForPost($post, $userId));
        $this->assertFalse($this->resolver->isPublishingUserForPost($post, $userId + 999));
        $this->assertFalse($this->resolver->isPublishingUserForPost($post, 0));
    }

    public function test_get_personal_editor_context_returns_null_without_profile(): void
    {
        $userId = self::factory()->user->create();

        $this->assertNull($this->resolver->getPersonalEditorContext($userId, [
            'personal_networks_enabled' => '1',
        ]));
    }

    public function test_get_personal_editor_context_returns_options_when_configured(): void
    {
        $userId = self::factory()->user->create();
        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $context = $this->resolver->getPersonalEditorContext($userId, [
            'personal_networks_enabled' => '1',
        ]);

        $this->assertNotNull($context);
        $this->assertArrayHasKey('profile', $context);
        $this->assertArrayHasKey('options', $context);
    }

    public function test_get_personal_editor_context_respects_network_availability(): void
    {
        $userId = self::factory()->user->create();
        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $this->assertNull($this->resolver->getPersonalEditorContext($userId, [
            'personal_networks_enabled' => '1',
            'personal_network_available_fake' => '0',
        ]));
    }

    public function test_other_articles_target_uses_global_template_context_and_skips_author_duplicate(): void
    {
        $authorId = self::factory()->user->create();
        $otherUserId = self::factory()->user->create();
        $post = $this->createPost($authorId);

        update_user_meta($authorId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($authorId, '_justbee_postcaster_user_publish_other_posts', '1');
        update_user_meta($authorId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        update_user_meta($otherUserId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($otherUserId, '_justbee_postcaster_user_publish_other_posts', '1');
        update_user_meta($otherUserId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $targets = $this->resolver->getTargets($post, [
            'personal_networks_enabled' => '1',
        ], true, false);

        $this->assertArrayHasKey('fake', $targets);
        $this->assertArrayHasKey('user_' . $authorId, $targets['fake']);
        $this->assertArrayHasKey('user_' . $otherUserId, $targets['fake']);
        $this->assertSame('personal', $targets['fake']['user_' . $authorId]['justbee_postcaster_template_context']);
        $this->assertSame('global', $targets['fake']['user_' . $otherUserId]['justbee_postcaster_template_context']);
        $this->assertCount(2, $targets['fake']);
    }

    public function test_target_options_filter_only_needs_to_return_overrides(): void
    {
        $post = $this->createPost(self::factory()->user->create());
        $options = [
            'personal_networks_enabled' => '0',
            $this->fake->optionKey('enabled') => '1',
        ];

        add_filter('justbee_postcaster_network_target_options', function ($overrides, $publisher, string $targetKey, WP_Post $filteredPost, array $globalOptions, array $baseTargetOptions) use ($post): array {
            $this->assertSame([], $overrides);
            $this->assertSame('fake', $publisher->getKey());
            $this->assertSame('global', $targetKey);
            $this->assertSame($post->ID, $filteredPost->ID);
            $this->assertSame('1', $globalOptions['fake_enabled']);
            $this->assertSame('1', $baseTargetOptions['fake_enabled']);

            return ['custom_flag' => 'yes'];
        }, 10, 6);

        $targets = $this->resolver->getTargets($post, $options);

        remove_all_filters('justbee_postcaster_network_target_options');

        $this->assertSame('yes', $targets['fake']['global']['custom_flag']);
        $this->assertSame('1', $targets['fake']['global']['fake_enabled']);
    }
}
