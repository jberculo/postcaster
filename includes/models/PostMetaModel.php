<?php

namespace Justbee\PostCaster\Models;

if (!defined('ABSPATH')) {
    exit;
}

final class PostMetaModel
{
    private const META_PREFIX = '_justbee_postcaster_';
    private const LOG_OPTION_NAME = 'justbee_postcaster_post_logs';
    private const POSTS_WITH_ERRORS_CACHE_GROUP = 'postcaster';
    private const MAX_LOG_LINES = 50;
    private const RETRY_COUNT_KEY = self::META_PREFIX . 'retry_count';
    private const POST_TEMPLATE_KEY = self::META_PREFIX . 'post_template';
    private const PERSONAL_POST_TEMPLATE_KEY = self::META_PREFIX . 'personal_post_template';
    private const POST_TEMPLATE_NETWORK_PREFIX = self::META_PREFIX . 'post_template__';
    private const INCLUDE_FEATURED_IMAGE_KEY = self::META_PREFIX . 'include_featured_image';
    private const INCLUDE_FEATURED_IMAGE_SCOPE_PREFIX = self::META_PREFIX . 'include_featured_image_scope__';
    private const INCLUDE_FEATURED_IMAGE_NETWORK_PREFIX = self::META_PREFIX . 'include_featured_image_network__';
    private const INCLUDE_PERSONAL_NETWORKS_KEY = self::META_PREFIX . 'include_personal_networks';
    private const DISABLE_PUBLISH_KEY = self::META_PREFIX . 'disable_publish';
    private const PUBLISH_LOCK_PREFIX = self::META_PREFIX . 'publish_lock__';

    public function hasRemoteId(int $postId, string $network, string $targetKey): bool
    {
        return (string) get_post_meta($postId, $this->metaKey($network, 'remote_id', $targetKey), true) !== '';
    }

    public function saveSuccess(int $postId, string $network, string $targetKey, array $result): void
    {
        update_post_meta($postId, $this->metaKey($network, 'remote_id', $targetKey), (string) ($result['id'] ?? ''));
        update_post_meta($postId, $this->metaKey($network, 'remote_url', $targetKey), (string) ($result['url'] ?? ''));
        delete_post_meta($postId, $this->metaKey($network, 'error', $targetKey));
        $this->invalidatePostsWithErrorsCache();
    }

    public function saveError(int $postId, string $network, string $targetKey, string $message): void
    {
        update_post_meta($postId, $this->metaKey($network, 'error', $targetKey), $message);
        $this->invalidatePostsWithErrorsCache();
    }

    private function invalidatePostsWithErrorsCache(): void
    {
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::POSTS_WITH_ERRORS_CACHE_GROUP);
        }
    }

    /**
     * Return up to $limit post IDs that currently have at least one
     * non-empty PostCaster error meta entry, newest first.
     *
     * @return int[]
     */
    public function getPostsWithErrors(int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, $limit);
        $cacheKey = 'posts_with_errors_' . $limit;
        $cachedRows = wp_cache_get($cacheKey, self::POSTS_WITH_ERRORS_CACHE_GROUP);
        if (is_array($cachedRows)) {
            return array_map('intval', $cachedRows);
        }

        $pattern = $wpdb->esc_like(self::META_PREFIX) . '%' . $wpdb->esc_like('_error');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- wildcard meta-key lookup for admin diagnostics requires a direct query.
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                WHERE meta_key LIKE %s AND meta_value <> ''
                ORDER BY post_id DESC
                LIMIT %d",
                $pattern,
                $limit
            )
        );

        $rows = is_array($rows) ? array_map('intval', $rows) : [];
        wp_cache_set($cacheKey, $rows, self::POSTS_WITH_ERRORS_CACHE_GROUP, MINUTE_IN_SECONDS);

        return $rows;
    }

    public function getErrors(int $postId): array
    {
        $errors = [];

        foreach (get_post_meta($postId) as $metaKey => $values) {
            $metaKey = (string) $metaKey;
            if (!str_starts_with($metaKey, self::META_PREFIX) || !str_ends_with($metaKey, '_error')) {
                continue;
            }

            $keyBody = substr($metaKey, strlen(self::META_PREFIX), -strlen('_error'));
            if ($keyBody === false || $keyBody === '') {
                continue;
            }

            $parts = explode('_', $keyBody, 2);
            $network = $parts[0] ?? '';
            $targetKey = $parts[1] ?? '';
            $message = (string) (($values[0] ?? ''));

            if ($network === '' || $message === '') {
                continue;
            }

            $errors[] = [
                'network' => $network,
                'target_key' => $targetKey,
                'message' => $message,
            ];
        }

        return $errors;
    }

    public function hasAnyRemoteIds(int $postId): bool
    {
        foreach (get_post_meta($postId) as $metaKey => $values) {
            if (!str_starts_with((string) $metaKey, self::META_PREFIX)) {
                continue;
            }

            if (!str_ends_with((string) $metaKey, '_remote_id')) {
                continue;
            }

            foreach ((array) $values as $value) {
                if ((string) $value !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return one entry per network/target combination that has been
     * successfully published, including the remote URL when known.
     *
     * @return array<int, array{network:string, target_key:string, remote_id:string, remote_url:string}>
     */
    public function getRemotePublications(int $postId): array
    {
        $publications = [];

        foreach (get_post_meta($postId) as $metaKey => $values) {
            $metaKey = (string) $metaKey;
            if (!str_starts_with($metaKey, self::META_PREFIX) || !str_ends_with($metaKey, '_remote_id')) {
                continue;
            }

            $remoteId = (string) ($values[0] ?? '');
            if ($remoteId === '') {
                continue;
            }

            $keyBody = substr($metaKey, strlen(self::META_PREFIX), -strlen('_remote_id'));
            if ($keyBody === false || $keyBody === '') {
                continue;
            }

            $parts = explode('_', $keyBody, 2);
            $network = (string) ($parts[0] ?? '');
            $targetKey = (string) ($parts[1] ?? '');
            if ($network === '') {
                continue;
            }

            $remoteUrl = (string) get_post_meta(
                $postId,
                $this->metaKey($network, 'remote_url', $targetKey),
                true
            );

            $publications[] = [
                'network' => $network,
                'target_key' => $targetKey,
                'remote_id' => $remoteId,
                'remote_url' => $remoteUrl,
            ];
        }

        return $publications;
    }

    public function appendLog(int $postId, string $message): void
    {
        $logs = $this->getStoredLogs();
        $lines = isset($logs[$postId]) && is_array($logs[$postId]) ? $logs[$postId] : [];
        $lines[] = gmdate('c') . ' ' . $message;
        $logs[$postId] = array_slice($lines, -self::MAX_LOG_LINES);

        $this->saveStoredLogs($logs);
    }

    public function getLog(int $postId): array
    {
        $logs = $this->getStoredLogs();

        if (!isset($logs[$postId]) || !is_array($logs[$postId])) {
            return [];
        }

        return $logs[$postId];
    }

    public function clearLog(int $postId): void
    {
        $logs = $this->getStoredLogs();
        unset($logs[$postId]);

        $this->saveStoredLogs($logs);
    }

    public function getAllLogs(): array
    {
        $entries = [];

        foreach ($this->getStoredLogs() as $postId => $lines) {
            $postId = (int) $postId;
            if ($lines === []) {
                continue;
            }

            $entries[] = [
                'post_id' => $postId,
                'title' => get_the_title($postId) ?: sprintf('#%d', $postId),
                'edit_url' => get_edit_post_link($postId, ''),
                'lines' => array_reverse($lines),
                'latest_timestamp' => $this->extractTimestamp((string) end($lines)),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($right['latest_timestamp'] ?? ''), (string) ($left['latest_timestamp'] ?? ''));
        });

        return $entries;
    }

    public function clearAllLogs(): void
    {
        delete_option(self::LOG_OPTION_NAME);
    }

    public function getPostTemplate(int $postId, string $context = 'global'): string
    {
        $metaKey = $this->getPostTemplateMetaKey($context);
        if (!metadata_exists('post', $postId, $metaKey)) {
            return '';
        }

        return (string) get_post_meta($postId, $metaKey, true);
    }

    public function savePostTemplate(int $postId, string $template, string $defaultTemplate, string $context = 'global'): void
    {
        $normalizedText = trim($template);
        $normalizedDefault = trim($defaultTemplate);
        $metaKey = $this->getPostTemplateMetaKey($context);

        if ($normalizedText === '' || $normalizedText === $normalizedDefault) {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $normalizedText);
    }

    public function getNetworkPostTemplate(int $postId, string $scope, string $network): string
    {
        $metaKey = $this->getNetworkPostTemplateMetaKey($scope, $network);
        if (!metadata_exists('post', $postId, $metaKey)) {
            return '';
        }

        return (string) get_post_meta($postId, $metaKey, true);
    }

    public function saveNetworkPostTemplate(int $postId, string $scope, string $network, string $template, string $defaultTemplate): void
    {
        $normalizedText = trim($template);
        $normalizedDefault = trim($defaultTemplate);
        $metaKey = $this->getNetworkPostTemplateMetaKey($scope, $network);

        if ($normalizedText === '' || $normalizedText === $normalizedDefault) {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $normalizedText);
    }

    /**
     * Resolve a post-level template override across (scope, network) and falls back to scope-level.
     *
     * Returns an empty string when no override is set at any level.
     */
    public function resolvePostTemplate(int $postId, string $scope, ?string $network): string
    {
        if ($network !== null && $network !== '') {
            $networkTemplate = $this->getNetworkPostTemplate($postId, $scope, $network);
            if (trim($networkTemplate) !== '') {
                return $networkTemplate;
            }
        }

        return $this->getPostTemplate($postId, $scope);
    }

    public function getIncludeFeaturedImageOverride(int $postId): ?string
    {
        return $this->getBoolOverride($postId, self::INCLUDE_FEATURED_IMAGE_KEY);
    }

    public function saveIncludeFeaturedImageOverride(int $postId, string $value, string $defaultValue): void
    {
        $this->saveBoolOverride($postId, self::INCLUDE_FEATURED_IMAGE_KEY, $value, $defaultValue);
    }

    /**
     * Scope-level featured-image override on a post (tri-state).
     *
     * Returns '0', '1' or null (= inherit). For scope=global the legacy
     * INCLUDE_FEATURED_IMAGE_KEY is reused so existing data keeps working.
     */
    public function getIncludeFeaturedImageScopeOverride(int $postId, string $scope): ?string
    {
        return $this->getBoolOverride($postId, $this->getIncludeFeaturedImageScopeMetaKey($scope));
    }

    public function saveIncludeFeaturedImageScopeOverride(int $postId, string $scope, string $value): void
    {
        $metaKey = $this->getIncludeFeaturedImageScopeMetaKey($scope);

        if ($value !== '0' && $value !== '1') {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $value);
    }

    public function getIncludeFeaturedImageNetworkOverride(int $postId, string $scope, string $network): ?string
    {
        return $this->getBoolOverride($postId, $this->getIncludeFeaturedImageNetworkMetaKey($scope, $network));
    }

    public function saveIncludeFeaturedImageNetworkOverride(int $postId, string $scope, string $network, string $value): void
    {
        $metaKey = $this->getIncludeFeaturedImageNetworkMetaKey($scope, $network);

        if ($value !== '0' && $value !== '1') {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $value);
    }

    /**
     * Resolve the effective featured-image flag for a publish target.
     *
     * Order: per-(scope,network) override → scope override → plugin/network default.
     */
    public function resolveIncludeFeaturedImage(int $postId, string $scope, string $network, bool $networkDefault): bool
    {
        $networkOverride = $this->getIncludeFeaturedImageNetworkOverride($postId, $scope, $network);
        if ($networkOverride !== null) {
            return $networkOverride === '1';
        }

        $scopeOverride = $this->getIncludeFeaturedImageScopeOverride($postId, $scope);
        if ($scopeOverride !== null) {
            return $scopeOverride === '1';
        }

        return $networkDefault;
    }

    public function getIncludePersonalNetworksOverride(int $postId): ?string
    {
        return $this->getBoolOverride($postId, self::INCLUDE_PERSONAL_NETWORKS_KEY);
    }

    public function getIncludePersonalNetworks(int $postId, string $defaultValue = '1'): string
    {
        $override = $this->getIncludePersonalNetworksOverride($postId);
        $value = $override ?? $defaultValue;

        return $value === '1' ? '1' : '0';
    }

    public function saveIncludePersonalNetworks(int $postId, string $value, string $defaultValue = '1'): void
    {
        $this->saveBoolOverride($postId, self::INCLUDE_PERSONAL_NETWORKS_KEY, $value, $defaultValue);
    }

    public function getDisablePublishOverride(int $postId): ?string
    {
        return $this->getBoolOverride($postId, self::DISABLE_PUBLISH_KEY);
    }

    public function isPublishDisabled(int $postId, string $defaultValue = '0'): bool
    {
        $override = $this->getDisablePublishOverride($postId);
        $value = $override ?? $defaultValue;

        return $value === '1';
    }

    public function saveDisablePublishOverride(int $postId, string $value, string $defaultValue = '0'): void
    {
        $this->saveBoolOverride($postId, self::DISABLE_PUBLISH_KEY, $value, $defaultValue);
    }

    private function getBoolOverride(int $postId, string $metaKey): ?string
    {
        if (!metadata_exists('post', $postId, $metaKey)) {
            return null;
        }

        return (string) get_post_meta($postId, $metaKey, true);
    }

    private function saveBoolOverride(int $postId, string $metaKey, string $value, string $defaultValue): void
    {
        $normalizedValue = $value === '1' ? '1' : '0';
        $normalizedDefault = $defaultValue === '1' ? '1' : '0';

        if ($normalizedValue === $normalizedDefault) {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $normalizedValue);
    }

    public function getRetryCount(int $postId): int
    {
        return max(0, (int) get_post_meta($postId, self::RETRY_COUNT_KEY, true));
    }

    public function incrementRetryCount(int $postId): int
    {
        $count = $this->getRetryCount($postId) + 1;
        update_post_meta($postId, self::RETRY_COUNT_KEY, $count);
        return $count;
    }

    public function resetRetryCount(int $postId): void
    {
        delete_post_meta($postId, self::RETRY_COUNT_KEY);
    }

    public function acquirePublishLock(int $postId, string $network, string $targetKey, int $ttl = 900): ?string
    {
        $metaKey = $this->getPublishLockMetaKey($network, $targetKey);
        $now = time();
        $token = wp_generate_password(32, false, false);
        $payload = $now . ':' . $token;

        $existing = (string) get_post_meta($postId, $metaKey, true);
        if ($existing !== '' && $this->isPublishLockStale($existing, $ttl, $now)) {
            delete_post_meta($postId, $metaKey);
        }

        if (add_post_meta($postId, $metaKey, $payload, true)) {
            return $token;
        }

        $existing = (string) get_post_meta($postId, $metaKey, true);
        if ($existing !== '' && $this->isPublishLockStale($existing, $ttl, $now)) {
            delete_post_meta($postId, $metaKey);
            if (add_post_meta($postId, $metaKey, $payload, true)) {
                return $token;
            }
        }

        return null;
    }

    public function releasePublishLock(int $postId, string $network, string $targetKey, ?string $token = null): void
    {
        $metaKey = $this->getPublishLockMetaKey($network, $targetKey);
        if ($token === null) {
            delete_post_meta($postId, $metaKey);
            return;
        }

        $existing = (string) get_post_meta($postId, $metaKey, true);
        if ($existing === '') {
            return;
        }

        $parts = explode(':', $existing, 2);
        if (($parts[1] ?? '') === $token) {
            delete_post_meta($postId, $metaKey);
        }
    }

    public function hasPublishLock(int $postId, string $network, string $targetKey): bool
    {
        return (string) get_post_meta($postId, $this->getPublishLockMetaKey($network, $targetKey), true) !== '';
    }

    private function metaKey(string $network, string $suffix, string $targetKey): string
    {
        return self::META_PREFIX . $network . '_' . $targetKey . '_' . $suffix;
    }

    private function getPostTemplateMetaKey(string $context): string
    {
        return $context === 'personal' ? self::PERSONAL_POST_TEMPLATE_KEY : self::POST_TEMPLATE_KEY;
    }

    private function getNetworkPostTemplateMetaKey(string $scope, string $network): string
    {
        return self::POST_TEMPLATE_NETWORK_PREFIX . sanitize_key($scope) . '__' . sanitize_key($network);
    }

    private function getIncludeFeaturedImageScopeMetaKey(string $scope): string
    {
        if (sanitize_key($scope) === 'global') {
            return self::INCLUDE_FEATURED_IMAGE_KEY;
        }

        return self::INCLUDE_FEATURED_IMAGE_SCOPE_PREFIX . sanitize_key($scope);
    }

    private function getIncludeFeaturedImageNetworkMetaKey(string $scope, string $network): string
    {
        return self::INCLUDE_FEATURED_IMAGE_NETWORK_PREFIX . sanitize_key($scope) . '__' . sanitize_key($network);
    }

    private function getPublishLockMetaKey(string $network, string $targetKey): string
    {
        return self::PUBLISH_LOCK_PREFIX . sanitize_key($network) . '__' . sanitize_key($targetKey);
    }

    private function isPublishLockStale(string $payload, int $ttl, int $now): bool
    {
        $parts = explode(':', $payload, 2);
        $createdAt = isset($parts[0]) ? (int) $parts[0] : 0;

        return $createdAt <= 0 || ($createdAt + max(1, $ttl)) < $now;
    }

    private function extractTimestamp(string $line): string
    {
        $parts = explode(' ', $line, 2);
        return $parts[0] ?? '';
    }

    private function getStoredLogs(): array
    {
        $logs = get_option(self::LOG_OPTION_NAME, []);

        return is_array($logs) ? $logs : [];
    }

    private function saveStoredLogs(array $logs): void
    {
        if ($logs === []) {
            delete_option(self::LOG_OPTION_NAME);
            return;
        }

        update_option(self::LOG_OPTION_NAME, $logs, false);
    }
}
