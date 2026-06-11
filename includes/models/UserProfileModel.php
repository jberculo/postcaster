<?php

namespace Justbee\PostCaster\Models;

use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Support\NormalizesTemplate;
use Justbee\PostCaster\Support\SecretsCipher;

if (!defined('ABSPATH')) {
    exit;
}

final class UserProfileModel
{
    use NormalizesTemplate;

    private const META_PREFIX = '_justbee_postcaster_user_';
    private const SUBSCRIBED_OTHER_POSTS_OPTION = 'justbee_postcaster_subscribed_other_posts_users';

    private SettingsModel $settings;
    private NetworkRegistry $networks;

    public function __construct(SettingsModel $settings, NetworkRegistry $networks)
    {
        $this->settings = $settings;
        $this->networks = $networks;
    }

    public function defaults(): array
    {
        return array_merge([
            'enabled' => '0',
            'publish_other_posts' => '0',
            'profile_template_enabled' => '0',
            'profile_template' => '',
        ], $this->networks->getProfileDefaults());
    }

    public function get(int $userId): array
    {
        $defaults = $this->defaults();
        foreach ($this->getStored($userId) as $key => $value) {
            if ($value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $this->decryptSecrets($defaults);
    }

    public function getExplicit(int $userId): array
    {
        $defaults = $this->defaults();
        $explicit = [];

        foreach ($this->getStored($userId) as $key => $value) {
            if ($value !== '' && $value !== (string) ($defaults[$key] ?? '')) {
                $explicit[$key] = $value;
            }
        }

        return $this->decryptSecrets($explicit);
    }

    private function decryptSecrets(array $values): array
    {
        foreach ($this->networks->getSecretOptionKeys() as $secretKey) {
            if (isset($values[$secretKey]) && is_string($values[$secretKey])) {
                $decrypted = SecretsCipher::tryDecrypt($values[$secretKey]);
                $values[$secretKey] = $decrypted ?? '';
            }
        }

        return $values;
    }

    public function save(int $userId, array $input): void
    {
        $defaults = $this->defaults();
        $profile = $this->sanitize($input, array_merge($defaults, $this->getStored($userId)));

        foreach ($profile as $key => $value) {
            $metaKey = $this->metaKey($key);

            if ((string) $value === (string) ($defaults[$key] ?? '')) {
                delete_user_meta($userId, $metaKey);
                continue;
            }

            update_user_meta($userId, $metaKey, $value);
        }

        $this->syncSubscribedOtherPostsIndex($userId, $profile);
    }

    public function sanitize(array $input, ?array $defaults = null): array
    {
        $defaults = $defaults ?? $this->defaults();
        $globalOptions = $this->settings->get();
        $output = $defaults;
        $output['enabled'] = !empty($input['enabled']) ? '1' : '0';
        $output['publish_other_posts'] = !empty($input['publish_other_posts']) ? '1' : '0';
        $output['profile_template_enabled'] = !empty($input['profile_template_enabled']) ? '1' : '0';
        $output['profile_template'] = $this->normalizeTemplate(sanitize_textarea_field((string) ($input['profile_template'] ?? $defaults['profile_template'])));

        if ($output['profile_template_enabled'] === '1') {
            $effectiveGeneral = $this->normalizeTemplate($this->settings->getEffectiveGeneralTemplate($globalOptions));
            $profileTemplate = $this->normalizeTemplate(trim((string) $output['profile_template']));

            if ($profileTemplate === '' || $profileTemplate === $effectiveGeneral) {
                $output['profile_template_enabled'] = '0';
                $output['profile_template'] = '';
            }
        }

        $profileTemplateOptions = $this->mergeIntoOptions($globalOptions, $output);

        foreach ($this->networks->all() as $publisher) {
            $publisherDefaults = array_merge($defaults, [
                'template_effective' => $this->settings->getEffectiveNetworkTemplate($publisher->getKey(), $profileTemplateOptions),
            ]);
            $output = array_merge($output, $publisher->sanitizeProfile($input, $publisherDefaults));
        }

        return $output;
    }

    public function mergeIntoOptions(array $globalOptions, array $profile): array
    {
        if (($profile['profile_template_enabled'] ?? '0') === '1') {
            $template = trim((string) ($profile['profile_template'] ?? ''));
            if ($template !== '') {
                $globalOptions['template_enabled'] = '1';
                $globalOptions['template'] = $template;
            }
        }

        return $globalOptions;
    }

    /**
     * @return int[]
     */
    public function getSubscribedUserIdsForOtherPosts(): array
    {
        $stored = get_option(self::SUBSCRIBED_OTHER_POSTS_OPTION, null);
        if (!is_array($stored)) {
            $stored = $this->rebuildSubscribedOtherPostsIndex();
        }

        return array_values(array_unique(array_filter(array_map('intval', $stored))));
    }

    public function removeFromSubscribedOtherPostsIndex(int $userId): void
    {
        $userId = max(0, $userId);
        if ($userId <= 0) {
            return;
        }

        $this->saveSubscribedOtherPostsIndex(
            array_diff($this->getSubscribedUserIdsForOtherPosts(), [$userId])
        );
    }

    private function getStored(int $userId): array
    {
        $stored = [];

        foreach (array_keys($this->defaults()) as $key) {
            $metaKey = $this->metaKey($key);
            if (!metadata_exists('user', $userId, $metaKey)) {
                continue;
            }

            $stored[$key] = (string) get_user_meta($userId, $metaKey, true);
        }

        return $stored;
    }

    private function syncSubscribedOtherPostsIndex(int $userId, array $profile): void
    {
        $subscribedUsers = $this->getSubscribedUserIdsForOtherPosts();
        $isSubscribed = ($profile['enabled'] ?? '0') === '1' && ($profile['publish_other_posts'] ?? '0') === '1';
        $userId = max(0, $userId);

        if ($userId <= 0) {
            return;
        }

        if ($isSubscribed) {
            $subscribedUsers[] = $userId;
        } else {
            $subscribedUsers = array_diff($subscribedUsers, [$userId]);
        }

        $this->saveSubscribedOtherPostsIndex($subscribedUsers);
    }

    /**
     * @return int[]
     */
    private function rebuildSubscribedOtherPostsIndex(): array
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one-time rebuild for a denormalized option index.
        $candidateUserIds = get_users([
            'fields' => 'ids',
            'meta_key' => $this->metaKey('publish_other_posts'),
            'meta_value' => '1',
        ]);
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        $subscribedUsers = [];

        foreach (array_map('intval', $candidateUserIds) as $userId) {
            if ($userId <= 0) {
                continue;
            }

            if ((string) get_user_meta($userId, $this->metaKey('enabled'), true) !== '1') {
                continue;
            }

            $subscribedUsers[] = $userId;
        }

        $this->saveSubscribedOtherPostsIndex($subscribedUsers);

        return $subscribedUsers;
    }

    /**
     * @param int[] $userIds
     */
    private function saveSubscribedOtherPostsIndex(array $userIds): void
    {
        $normalizedUserIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($normalizedUserIds === []) {
            delete_option(self::SUBSCRIBED_OTHER_POSTS_OPTION);
            return;
        }

        update_option(self::SUBSCRIBED_OTHER_POSTS_OPTION, $normalizedUserIds, false);
    }

    private function metaKey(string $key): string
    {
        return self::META_PREFIX . $key;
    }

}
