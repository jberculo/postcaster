<?php
/**
 * Uninstall handler — removes all PostCaster data from the database.
 *
 * Every table name referenced here is derived from $wpdb->prefix plus a
 * hard-coded Action Scheduler suffix, so the interpolation is safe; we
 * silence the prepared-SQL and direct-query warnings file-wide instead
 * of repeating the annotation on every line.
 *
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Plugin options.
delete_option('justbee_postcaster_options');
delete_option('justbee_postcaster_debug_log');
delete_option('justbee_postcaster_encryption_key');
delete_option('justbee_postcaster_post_logs');
delete_option('justbee_postcaster_subscribed_other_posts_users');

// Scheduled cron events.
wp_clear_scheduled_hook('justbee_postcaster_publish_post_async');

$justbee_postcaster_actions_table = $wpdb->prefix . 'actionscheduler_actions';
$justbee_postcaster_claims_table = $wpdb->prefix . 'actionscheduler_claims';
$justbee_postcaster_logs_table = $wpdb->prefix . 'actionscheduler_logs';
$justbee_postcaster_groups_table = $wpdb->prefix . 'actionscheduler_groups';

// Action Scheduler jobs owned by PostCaster.
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $justbee_postcaster_actions_table)) === $justbee_postcaster_actions_table) {
    $justbee_postcaster_claim_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT claim_id FROM {$justbee_postcaster_actions_table}
            WHERE hook = %s AND claim_id <> 0",
            'justbee_postcaster_publish_target'
        )
    );

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $justbee_postcaster_logs_table)) === $justbee_postcaster_logs_table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes plugin-owned Action Scheduler logs in bulk.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$justbee_postcaster_logs_table}
                WHERE action_id IN (
                    SELECT action_id FROM {$justbee_postcaster_actions_table} WHERE hook = %s
                )",
                'justbee_postcaster_publish_target'
            )
        );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes plugin-owned Action Scheduler jobs in bulk.
    $wpdb->delete($justbee_postcaster_actions_table, ['hook' => 'justbee_postcaster_publish_target'], ['%s']);

    if (
        $justbee_postcaster_claim_ids !== []
        && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $justbee_postcaster_claims_table)) === $justbee_postcaster_claims_table
    ) {
        $justbee_postcaster_claim_ids = array_values(array_unique(array_filter(array_map('intval', $justbee_postcaster_claim_ids))));

        foreach ($justbee_postcaster_claim_ids as $justbee_postcaster_claim_id) {
            $justbee_postcaster_remaining_claims = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$justbee_postcaster_actions_table} WHERE claim_id = %d",
                    $justbee_postcaster_claim_id
                )
            );

            if ($justbee_postcaster_remaining_claims > 0) {
                continue;
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes orphaned Action Scheduler claims owned by plugin jobs.
            $wpdb->delete($justbee_postcaster_claims_table, ['claim_id' => $justbee_postcaster_claim_id], ['%d']);
        }
    }
}

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $justbee_postcaster_groups_table)) === $justbee_postcaster_groups_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes plugin-owned Action Scheduler groups in bulk.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$justbee_postcaster_groups_table} WHERE slug LIKE %s",
            'postcaster-post-%'
        )
    );
}

// Post meta — current keys use `_justbee_postcaster_…`; older installs may still
// carry the legacy `_postcaster_…` prefix if the runtime migration never ran.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes plugin-owned records in bulk.
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_justbee\\_postcaster\\_%' OR meta_key LIKE '\\_postcaster\\_%'"
);

// User meta — profile data (`_justbee_postcaster_user_…`) and admin notices
// (`_justbee_postcaster_admin_notice_…`, `_justbee_postcaster_settings_notice`),
// plus any leftover legacy `_postcaster_…` keys from earlier versions.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup intentionally deletes plugin-owned records in bulk.
$wpdb->query(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_justbee\\_postcaster\\_%' OR meta_key LIKE '\\_postcaster\\_%'"
);
