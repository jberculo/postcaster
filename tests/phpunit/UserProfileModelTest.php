<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\NetworkRegistry;

final class UserProfileModelTest extends WP_UnitTestCase
{
    private UserProfileModel $profiles;
    private SettingsModel $settings;

    public function set_up(): void
    {
        parent::set_up();
        $networks = new NetworkRegistry([new FakeNetworkPublisher('fake')]);
        $this->settings = new SettingsModel($networks);
        $this->profiles = new UserProfileModel($this->settings, $networks);

        update_option(SettingsModel::OPTION_NAME, [
            'enabled' => '1',
            'personal_networks_enabled' => '1',
            'template_enabled' => '0',
            'template' => $this->settings->getDefaultTemplate(),
        ]);
    }

    public function test_profile_enabled_flag_normalized(): void
    {
        $sanitized = $this->profiles->sanitize(['enabled' => 'on']);
        $this->assertSame('1', $sanitized['enabled']);

        $sanitized = $this->profiles->sanitize([]);
        $this->assertSame('0', $sanitized['enabled']);
    }

    public function test_publish_other_posts_flag_normalized(): void
    {
        $sanitized = $this->profiles->sanitize(['publish_other_posts' => '1']);
        $this->assertSame('1', $sanitized['publish_other_posts']);

        $sanitized = $this->profiles->sanitize([]);
        $this->assertSame('0', $sanitized['publish_other_posts']);
    }

    public function test_profile_template_auto_disables_when_matching_effective_general(): void
    {
        $sanitized = $this->profiles->sanitize([
            'profile_template_enabled' => '1',
            'profile_template' => $this->settings->getEffectiveGeneralTemplate(),
        ]);

        $this->assertSame('0', $sanitized['profile_template_enabled']);
        $this->assertSame('', $sanitized['profile_template'], 'Redundant template must be cleared.');
    }

    public function test_profile_template_enabled_but_empty_clears_the_override(): void
    {
        // Empty input is first filled with the effective general template, which
        // then triggers the "equal to fallback" auto-disable branch. The profile
        // model clears the stored value (the global template takes over implicitly)
        // instead of persisting a redundant duplicate.
        $sanitized = $this->profiles->sanitize([
            'profile_template_enabled' => '1',
            'profile_template' => '',
        ]);

        $this->assertSame('0', $sanitized['profile_template_enabled']);
        $this->assertSame('', $sanitized['profile_template']);
    }

    public function test_profile_template_crlf_is_normalized_to_lf(): void
    {
        $sanitized = $this->profiles->sanitize([
            'profile_template_enabled' => '1',
            'profile_template' => "alpha\r\nbeta",
        ]);

        $this->assertSame("alpha\nbeta", $sanitized['profile_template']);
    }

    public function test_save_deletes_meta_when_value_equals_default(): void
    {
        $userId = self::factory()->user->create();

        $this->profiles->save($userId, ['enabled' => '1']);
        $this->assertSame('1', get_user_meta($userId, '_justbee_postcaster_user_enabled', true));

        $this->profiles->save($userId, ['enabled' => '0']);
        $this->assertSame('', (string) get_user_meta($userId, '_justbee_postcaster_user_enabled', true), 'Meta equal to default must be removed.');
    }

    public function test_get_returns_defaults_when_user_has_no_meta(): void
    {
        $userId = self::factory()->user->create();

        $profile = $this->profiles->get($userId);

        $this->assertSame('0', $profile['enabled']);
        $this->assertSame('0', $profile['publish_other_posts']);
        $this->assertSame('0', $profile['profile_template_enabled']);
    }

    public function test_get_explicit_returns_only_user_overrides(): void
    {
        $userId = self::factory()->user->create();
        $this->profiles->save($userId, ['enabled' => '1']);

        $explicit = $this->profiles->getExplicit($userId);

        $this->assertArrayHasKey('enabled', $explicit);
        $this->assertSame('1', $explicit['enabled']);
        // Defaults should not appear in the explicit set.
        $this->assertArrayNotHasKey('profile_template_enabled', $explicit);
    }

    public function test_merge_into_options_applies_profile_template(): void
    {
        $globalOptions = [
            'template_enabled' => '0',
            'template' => 'GLOBAL',
        ];
        $profile = [
            'profile_template_enabled' => '1',
            'profile_template' => 'PERSONAL',
        ];

        $merged = $this->profiles->mergeIntoOptions($globalOptions, $profile);

        $this->assertSame('1', $merged['template_enabled']);
        $this->assertSame('PERSONAL', $merged['template']);
    }

    public function test_merge_into_options_leaves_global_when_profile_template_disabled(): void
    {
        $globalOptions = ['template_enabled' => '1', 'template' => 'GLOBAL'];
        $profile = ['profile_template_enabled' => '0', 'profile_template' => 'IGNORED'];

        $merged = $this->profiles->mergeIntoOptions($globalOptions, $profile);

        $this->assertSame('1', $merged['template_enabled']);
        $this->assertSame('GLOBAL', $merged['template']);
    }
}
