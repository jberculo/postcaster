<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Models\SettingsModel;
use WP_Post;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

final class PublishTargetResolver
{
    private const TARGET_TEMPLATE_CONTEXT = 'justbee_postcaster_template_context';

    private UserProfileModel $profiles;
    private NetworkRegistry $networks;
    private SettingsModel $settings;

    public function __construct(UserProfileModel $profiles, NetworkRegistry $networks, SettingsModel $settings)
    {
        $this->profiles = $profiles;
        $this->networks = $networks;
        $this->settings = $settings;
    }

    public function getTargets(
        WP_Post $post,
        array $globalOptions,
        bool $includePersonalNetworks = true,
        bool $includeGlobalNetworks = true
    ): array
    {
        $targets = [];

        if ($includeGlobalNetworks) {
            foreach ($this->networks->keys() as $networkKey) {
                $targets[$networkKey] = [];
                $publisher = $this->networks->get($networkKey);
                if (
                    $publisher
                    && ($globalOptions[$publisher->optionKey('enabled')] ?? '0') === '1'
                    && $publisher->isConfigured($globalOptions)
                ) {
                    $baseTargetOptions = $this->withTemplateContext($globalOptions, 'global');
                    $targetOptions = $this->mergeHookOverrides(
                        $baseTargetOptions,
                        $publisher,
                        'global',
                        $post,
                        $globalOptions
                    );
                    if ($publisher->isConfigured($targetOptions)) {
                        $targets[$networkKey]['global'] = $targetOptions;
                    }
                }
            }
        }

        if (!$includePersonalNetworks || ($globalOptions['personal_networks_enabled'] ?? '1') !== '1') {
            return array_filter($targets);
        }

        $publishingUserIds = $this->getPublishingUserIds($post);

        foreach ($publishingUserIds as $userId) {
            $this->addPersonalTargetsForUser($targets, $post, $userId, $globalOptions, 'personal');
        }

        foreach (array_values(array_diff($this->profiles->getSubscribedUserIdsForOtherPosts(), $publishingUserIds)) as $userId) {
            $this->addPersonalTargetsForUser($targets, $post, $userId, $globalOptions, 'global');
        }

        return array_filter($targets);
    }

    public function hasConfiguredGlobalTargets(array $globalOptions): bool
    {
        foreach ($this->networks->all() as $publisher) {
            if (
                ($globalOptions[$publisher->optionKey('enabled')] ?? '0') === '1'
                && $publisher->isConfigured($globalOptions)
            ) {
                return true;
            }
        }

        return false;
    }

    public function getPersonalEditorContext(int $userId, array $globalOptions): ?array
    {
        if ($userId <= 0 || ($globalOptions['personal_networks_enabled'] ?? '1') !== '1') {
            return null;
        }

        $profile = $this->profiles->getExplicit($userId);
        if (($profile['enabled'] ?? '0') !== '1') {
            return null;
        }

        $profileOptions = $this->profiles->mergeIntoOptions($globalOptions, $profile);

        foreach ($this->networks->all() as $publisher) {
            if (!$this->settings->isPersonalNetworkAvailable($publisher->getKey(), $globalOptions)) {
                continue;
            }

            if (($profile[$publisher->optionKey('enabled')] ?? '0') !== '1') {
                continue;
            }

            $targetOptions = $publisher->mergeProfileIntoOptions($profileOptions, $profile);
            if ($publisher->isConfigured($targetOptions)) {
                return [
                    'profile' => $profile,
                    'options' => $profileOptions,
                ];
            }
        }

        return null;
    }

    public function getFirstPersonalEditorContextForPost(WP_Post $post, array $globalOptions): ?array
    {
        if (($globalOptions['personal_networks_enabled'] ?? '1') !== '1') {
            return null;
        }

        foreach ($this->getPublishingUserIds($post) as $userId) {
            $context = $this->getPersonalEditorContext($userId, $globalOptions);
            if ($context !== null) {
                return $context;
            }
        }

        return null;
    }

    public function isPublishingUserForPost(WP_Post $post, int $userId): bool
    {
        return $userId > 0 && in_array($userId, $this->getPublishingUserIds($post), true);
    }

    private function getPublishingUserIds(WP_Post $post): array
    {
        $userIds = [];

        if ((int) $post->post_author > 0) {
            $userIds[] = (int) $post->post_author;
        }

        if (function_exists('get_coauthors')) {
            foreach ((array) get_coauthors($post->ID) as $coauthor) {
                if ($coauthor instanceof WP_User && !empty($coauthor->ID)) {
                    $userIds[] = (int) $coauthor->ID;
                    continue;
                }

                if (
                    is_object($coauthor)
                    && !empty($coauthor->wp_user)
                    && $coauthor->wp_user instanceof WP_User
                    && !empty($coauthor->wp_user->ID)
                ) {
                    $userIds[] = (int) $coauthor->wp_user->ID;
                    continue;
                }

                if (is_object($coauthor) && !empty($coauthor->linked_account)) {
                    $linkedUser = get_user_by('login', $coauthor->linked_account);
                    if ($linkedUser instanceof WP_User && !empty($linkedUser->ID)) {
                        $userIds[] = (int) $linkedUser->ID;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $userIds))));
    }

    private function addPersonalTargetsForUser(array &$targets, WP_Post $post, int $userId, array $globalOptions, string $templateContext): void
    {
        $profile = $this->profiles->getExplicit($userId);
        if (($profile['enabled'] ?? '0') !== '1') {
            return;
        }

        $profileOptions = $this->profiles->mergeIntoOptions($globalOptions, $profile);

        foreach ($this->networks->all() as $publisher) {
            $networkKey = $publisher->getKey();
            if (!$this->settings->isPersonalNetworkAvailable($networkKey, $globalOptions)) {
                continue;
            }

            if (($profile[$publisher->optionKey('enabled')] ?? '0') !== '1') {
                continue;
            }

            $baseTargetOptions = $publisher->mergeProfileIntoOptions($profileOptions, $profile);
            $targetOptions = $this->mergeHookOverrides(
                $baseTargetOptions,
                $publisher,
                'user_' . $userId,
                $post,
                $globalOptions
            );

            if (!$publisher->isConfigured($targetOptions)) {
                continue;
            }

            $targets[$networkKey]['user_' . $userId] = $this->withTemplateContext($targetOptions, $templateContext);
        }
    }

    private function withTemplateContext(array $targetOptions, string $templateContext): array
    {
        $targetOptions[self::TARGET_TEMPLATE_CONTEXT] = $templateContext;

        return $targetOptions;
    }

    private function mergeHookOverrides(
        array $baseTargetOptions,
        $publisher,
        string $targetKey,
        WP_Post $post,
        array $globalOptions
    ): array {
        $overrides = apply_filters(
            'justbee_postcaster_network_target_options',
            [],
            $publisher,
            $targetKey,
            $post,
            $globalOptions,
            $baseTargetOptions
        );

        return is_array($overrides) ? array_merge($baseTargetOptions, $overrides) : $baseTargetOptions;
    }
}
