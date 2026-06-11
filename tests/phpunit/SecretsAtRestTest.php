<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\Networks\BlueskyPublisher;
use Justbee\PostCaster\Services\Networks\LinkedInPublisher;
use Justbee\PostCaster\Services\Networks\MastodonPublisher;
use Justbee\PostCaster\Support\SecretsCipher;

final class SecretsAtRestTest extends WP_UnitTestCase
{
    private NetworkRegistry $networks;
    private SettingsModel $settings;
    private UserProfileModel $profiles;

    public function set_up(): void
    {
        parent::set_up();

        if (!SecretsCipher::isAvailable()) {
            $this->markTestSkipped('libsodium not available in this PHP build.');
        }

        $http = new HttpService();
        $media = new MediaService();
        $this->networks = new NetworkRegistry([
            new BlueskyPublisher($http, $media),
            new MastodonPublisher($http, $media),
            new LinkedInPublisher($http, $media),
        ]);
        $this->settings = new SettingsModel($this->networks);
        $this->profiles = new UserProfileModel($this->settings, $this->networks);

        delete_option(SettingsModel::OPTION_NAME);
    }

    public function test_registry_lists_known_secret_keys(): void
    {
        $keys = $this->networks->getSecretOptionKeys();

        sort($keys);
        $this->assertSame(
            ['bluesky_app_password', 'linkedin_access_token', 'mastodon_access_token'],
            $keys
        );
    }

    public function test_global_secrets_are_encrypted_in_database_and_decrypted_on_read(): void
    {
        $sanitized = $this->settings->sanitize([
            'bluesky_app_password' => 'plain-bluesky-password',
            'linkedin_access_token' => 'plain-linkedin-token',
            'mastodon_access_token' => 'plain-mastodon-token',
        ]);

        update_option(SettingsModel::OPTION_NAME, $sanitized);
        $stored = get_option(SettingsModel::OPTION_NAME);

        foreach (['bluesky_app_password', 'linkedin_access_token', 'mastodon_access_token'] as $key) {
            $this->assertTrue(
                SecretsCipher::isEncrypted((string) $stored[$key]),
                "$key must be encrypted at rest in wp_options"
            );
        }

        $read = $this->settings->get();
        $this->assertSame('plain-bluesky-password', $read['bluesky_app_password']);
        $this->assertSame('plain-linkedin-token', $read['linkedin_access_token']);
        $this->assertSame('plain-mastodon-token', $read['mastodon_access_token']);
    }

    public function test_blank_input_keeps_existing_encrypted_secret(): void
    {
        $first = $this->settings->sanitize(['bluesky_app_password' => 'first-secret']);
        update_option(SettingsModel::OPTION_NAME, $first);
        $cipher = (string) get_option(SettingsModel::OPTION_NAME)['bluesky_app_password'];

        // Resave without the password field — should retain the existing value.
        $second = $this->settings->sanitize(['enabled' => '1']);
        update_option(SettingsModel::OPTION_NAME, $second);
        $stored = (string) get_option(SettingsModel::OPTION_NAME)['bluesky_app_password'];

        $this->assertSame($cipher, $stored, 'omitted secret input must not erase or rewrite the stored value');
        $this->assertSame('first-secret', $this->settings->get()['bluesky_app_password']);
    }

    public function test_legacy_plaintext_secret_is_migrated_to_ciphertext_on_next_save(): void
    {
        // Simulate a pre-encryption install: store a plaintext secret directly.
        update_option(SettingsModel::OPTION_NAME, [
            'enabled' => '1',
            'bluesky_app_password' => 'legacy-plain-password',
        ]);

        // Reading must transparently surface the plaintext.
        $this->assertSame('legacy-plain-password', $this->settings->get()['bluesky_app_password']);

        // Triggering a save without resubmitting the password must still upgrade it.
        $sanitized = $this->settings->sanitize(['enabled' => '1']);
        update_option(SettingsModel::OPTION_NAME, $sanitized);

        $stored = (string) get_option(SettingsModel::OPTION_NAME)['bluesky_app_password'];
        $this->assertTrue(SecretsCipher::isEncrypted($stored), 'legacy plaintext must be encrypted on next save');
        $this->assertSame('legacy-plain-password', $this->settings->get()['bluesky_app_password']);
    }

    public function test_invalid_ciphertext_is_cleared_and_marked_as_failure(): void
    {
        update_option(SettingsModel::OPTION_NAME, [
            'enabled' => '1',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'pcsec1:not-valid-ciphertext',
        ]);

        $options = $this->settings->get();

        $this->assertSame('', $options['bluesky_app_password']);
        $this->assertTrue($this->settings->hasSecretDecryptionFailures());
    }

    public function test_profile_secrets_are_encrypted_in_user_meta_and_decrypted_on_read(): void
    {
        $userId = self::factory()->user->create(['role' => 'editor']);

        $this->profiles->save($userId, [
            'enabled' => '1',
            'bluesky_app_password' => 'user-bluesky-password',
            'mastodon_access_token' => 'user-mastodon-token',
        ]);

        $rawBluesky = (string) get_user_meta($userId, '_justbee_postcaster_user_bluesky_app_password', true);
        $rawMastodon = (string) get_user_meta($userId, '_justbee_postcaster_user_mastodon_access_token', true);

        $this->assertTrue(SecretsCipher::isEncrypted($rawBluesky), 'profile bluesky secret must be ciphertext in user_meta');
        $this->assertTrue(SecretsCipher::isEncrypted($rawMastodon), 'profile mastodon secret must be ciphertext in user_meta');

        $profile = $this->profiles->get($userId);
        $this->assertSame('user-bluesky-password', $profile['bluesky_app_password']);
        $this->assertSame('user-mastodon-token', $profile['mastodon_access_token']);
    }
}
