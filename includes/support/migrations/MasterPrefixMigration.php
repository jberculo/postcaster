<?php

namespace Justbee\PostCaster\Support\Migrations;

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Services\PublishQueueService;
use Justbee\PostCaster\Support\SecretsCipher;
use wpdb;

if (!defined('ABSPATH')) {
    exit;
}

final class MasterPrefixMigration implements MigrationInterface
{
    private const POSTMETA_LEGACY_PREFIX = '_postcaster_';
    private const POSTMETA_CURRENT_PREFIX = '_justbee_postcaster_';
    private const USERMETA_LEGACY_PREFIX = '_postcaster_user_';
    private const USERMETA_CURRENT_PREFIX = '_justbee_postcaster_user_';
    private const NOTICE_LEGACY_PREFIX = '_postcaster_admin_notice_';
    private const NOTICE_CURRENT_PREFIX = '_justbee_postcaster_admin_notice_';
    private const SETTINGS_NOTICE_LEGACY_KEY = '_postcaster_settings_notice';
    private const SETTINGS_NOTICE_CURRENT_KEY = '_justbee_postcaster_settings_notice';
    private const OPTION_MAP = [
        'postcaster_options' => SettingsModel::OPTION_NAME,
        'postcaster_encryption_key' => SecretsCipher::KEY_OPTION,
        'postcaster_post_logs' => 'justbee_postcaster_post_logs',
        'postcaster_subscribed_other_posts_users' => 'justbee_postcaster_subscribed_other_posts_users',
    ];

    public function id(): string
    {
        return '2026_05_master_prefixes';
    }

    public function migrate(): void
    {
        $this->migrateOptions();
        $this->migratePostMeta();
        $this->migrateUserMeta();
        $this->migrateActionSchedulerHooks();
    }

    private function migrateOptions(): void
    {
        foreach (self::OPTION_MAP as $legacyOption => $currentOption) {
            $legacyRecord = $this->getOptionRecord($legacyOption);
            if ($legacyRecord === null) {
                continue;
            }

            $currentRecord = $this->getOptionRecord($currentOption);
            if ($currentRecord === null) {
                add_option($currentOption, $legacyRecord['value'], '', $legacyRecord['autoload']);
                delete_option($legacyOption);
                continue;
            }

            $mergedValue = $this->mergeOptionValues($legacyRecord['value'], $currentRecord['value']);
            if ($mergedValue !== $currentRecord['value']) {
                update_option($currentOption, $mergedValue, $currentRecord['autoload'] === 'yes');
            }

            if ($this->shouldDeleteLegacyOption($legacyRecord['value'], $mergedValue)) {
                delete_option($legacyOption);
            }
        }
    }

    private function migratePostMeta(): void
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return;
        }

        $touchedPostIds = [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration over legacy post meta keys.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key LIKE %s
                ORDER BY meta_id ASC",
                $wpdb->esc_like(self::POSTMETA_LEGACY_PREFIX) . '%'
            ),
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $metaId = (int) ($row['meta_id'] ?? 0);
            $postId = (int) ($row['post_id'] ?? 0);
            $legacyKey = (string) ($row['meta_key'] ?? '');
            $legacyValue = (string) ($row['meta_value'] ?? '');
            $currentKey = self::POSTMETA_CURRENT_PREFIX . substr($legacyKey, strlen(self::POSTMETA_LEGACY_PREFIX));

            $this->migrateSinglePostMeta($wpdb, $metaId, $postId, $legacyKey, $currentKey, $legacyValue);
            $touchedPostIds[$postId] = true;
        }

        foreach (array_keys($touchedPostIds) as $postId) {
            clean_post_cache((int) $postId);
        }
    }

    private function migrateUserMeta(): void
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return;
        }

        $patterns = [
            self::USERMETA_LEGACY_PREFIX => self::USERMETA_CURRENT_PREFIX,
            self::NOTICE_LEGACY_PREFIX => self::NOTICE_CURRENT_PREFIX,
        ];
        $touchedUserIds = [];

        foreach ($patterns as $legacyPrefix => $currentPrefix) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration over legacy user meta keys.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT umeta_id, user_id, meta_key, meta_value
                    FROM {$wpdb->usermeta}
                    WHERE meta_key LIKE %s
                    ORDER BY umeta_id ASC",
                    $wpdb->esc_like($legacyPrefix) . '%'
                ),
                ARRAY_A
            );

            foreach (is_array($rows) ? $rows : [] as $row) {
                $metaId = (int) ($row['umeta_id'] ?? 0);
                $userId = (int) ($row['user_id'] ?? 0);
                $legacyKey = (string) ($row['meta_key'] ?? '');
                $legacyValue = (string) ($row['meta_value'] ?? '');
                $currentKey = $currentPrefix . substr($legacyKey, strlen($legacyPrefix));

                $this->migrateSingleUserMeta($wpdb, $metaId, $userId, $legacyKey, $currentKey, $legacyValue);
                $touchedUserIds[$userId] = true;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- exact-key migration for settings notice.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT umeta_id, user_id, meta_value
                FROM {$wpdb->usermeta}
                WHERE meta_key = %s
                ORDER BY umeta_id ASC",
                self::SETTINGS_NOTICE_LEGACY_KEY
            ),
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $this->migrateSingleUserMeta(
                $wpdb,
                (int) ($row['umeta_id'] ?? 0),
                (int) ($row['user_id'] ?? 0),
                self::SETTINGS_NOTICE_LEGACY_KEY,
                self::SETTINGS_NOTICE_CURRENT_KEY,
                (string) ($row['meta_value'] ?? '')
            );
            $touchedUserIds[(int) ($row['user_id'] ?? 0)] = true;
        }

        foreach (array_keys($touchedUserIds) as $userId) {
            clean_user_cache((int) $userId);
        }
    }

    private function migrateActionSchedulerHooks(): void
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return;
        }

        $table = $wpdb->prefix . 'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time table presence check.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time hook rename for persisted Action Scheduler jobs.
        $wpdb->update(
            $table,
            ['hook' => PublishQueueService::ACTION_HOOK],
            ['hook' => 'postcaster_publish_target']
        );
    }

    /**
     * @return array{value:mixed,autoload:string}|null
     */
    private function getOptionRecord(string $optionName): ?array
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- migration needs exact existence and autoload state.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_value, autoload
                FROM {$wpdb->options}
                WHERE option_name = %s
                LIMIT 1",
                $optionName
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'value' => maybe_unserialize($row['option_value'] ?? null),
            'autoload' => ($row['autoload'] ?? 'yes') === 'no' ? 'no' : 'yes',
        ];
    }

    /**
     * @param mixed $legacyValue
     * @param mixed $currentValue
     * @return mixed
     */
    private function mergeOptionValues($legacyValue, $currentValue)
    {
        if (is_array($legacyValue) && is_array($currentValue)) {
            return array_replace_recursive($legacyValue, $currentValue);
        }

        return $currentValue;
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private function valuesMatch($left, $right): bool
    {
        return maybe_serialize($left) === maybe_serialize($right);
    }

    /**
     * @param mixed $legacyValue
     * @param mixed $currentValue
     */
    private function shouldDeleteLegacyOption($legacyValue, $currentValue): bool
    {
        if ($this->valuesMatch($legacyValue, $currentValue)) {
            return true;
        }

        if (!is_array($legacyValue) || !is_array($currentValue)) {
            return false;
        }

        return $this->valuesMatch(array_replace_recursive($legacyValue, $currentValue), $currentValue);
    }

    private function migrateSinglePostMeta(
        wpdb $wpdb,
        int $metaId,
        int $postId,
        string $legacyKey,
        string $currentKey,
        string $legacyValue
    ): void {
        if ($metaId <= 0 || $postId <= 0 || $legacyKey === '' || $currentKey === '') {
            return;
        }

        $currentValues = array_map('strval', get_post_meta($postId, $currentKey, false));
        if ($currentValues === []) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- direct row key rename avoids duplicate rows during migration.
            $wpdb->update($wpdb->postmeta, ['meta_key' => $currentKey], ['meta_id' => $metaId], ['%s'], ['%d']);
            return;
        }

        if (in_array($legacyValue, $currentValues, true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- delete exact duplicate legacy row after successful migration.
            $wpdb->delete($wpdb->postmeta, ['meta_id' => $metaId], ['%d']);
        }
    }

    private function migrateSingleUserMeta(
        wpdb $wpdb,
        int $metaId,
        int $userId,
        string $legacyKey,
        string $currentKey,
        string $legacyValue
    ): void {
        if ($metaId <= 0 || $userId <= 0 || $legacyKey === '' || $currentKey === '') {
            return;
        }

        $currentValues = array_map('strval', get_user_meta($userId, $currentKey, false));
        if ($currentValues === []) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- direct row key rename avoids duplicate rows during migration.
            $wpdb->update($wpdb->usermeta, ['meta_key' => $currentKey], ['umeta_id' => $metaId], ['%s'], ['%d']);
            return;
        }

        if (in_array($legacyValue, $currentValues, true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- delete exact duplicate legacy row after successful migration.
            $wpdb->delete($wpdb->usermeta, ['umeta_id' => $metaId], ['%d']);
        }
    }
}
