<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Support\Migrations\MigrationInterface;
use Justbee\PostCaster\Support\Migrations\MigrationRunner;
use Justbee\PostCaster\Support\LegacyMigration;
use Justbee\PostCaster\Support\SecretsCipher;

final class LegacyMigrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        delete_option(MigrationRunner::STATE_OPTION);
        delete_option(MigrationRunner::LOCK_OPTION);
        delete_option('postcaster_options');
        delete_option(SettingsModel::OPTION_NAME);
        delete_option('postcaster_post_logs');
        delete_option('justbee_postcaster_post_logs');
        delete_option('postcaster_subscribed_other_posts_users');
        delete_option('justbee_postcaster_subscribed_other_posts_users');
        delete_option('postcaster_encryption_key');
        delete_option(SecretsCipher::KEY_OPTION);
    }

    public function test_run_migrates_master_options(): void
    {
        update_option('postcaster_options', ['enabled' => '1', 'template' => '{title}']);
        update_option('postcaster_post_logs', [123 => ['line']]);
        update_option('postcaster_subscribed_other_posts_users', [5, 7]);
        update_option('postcaster_encryption_key', 'legacy-key');

        LegacyMigration::run();

        $this->assertSame(['enabled' => '1', 'template' => '{title}'], get_option(SettingsModel::OPTION_NAME));
        $this->assertSame([123 => ['line']], get_option('justbee_postcaster_post_logs'));
        $this->assertSame([5, 7], get_option('justbee_postcaster_subscribed_other_posts_users'));
        $this->assertSame('legacy-key', get_option(SecretsCipher::KEY_OPTION));
        $state = MigrationRunner::getState();

        $this->assertFalse(get_option('postcaster_options', false));
        $this->assertArrayHasKey('2026_05_master_prefixes', $state['completed']);
    }

    public function test_run_merges_master_options_into_existing_current_options(): void
    {
        update_option('postcaster_options', [
            'enabled' => '1',
            'template_enabled' => '1',
            'template' => '{title}',
        ]);
        update_option(SettingsModel::OPTION_NAME, [
            'debug' => '1',
            'template' => '{url}',
        ]);

        LegacyMigration::run();

        $this->assertSame([
            'enabled' => '1',
            'template_enabled' => '1',
            'template' => '{url}',
            'debug' => '1',
        ], get_option(SettingsModel::OPTION_NAME));
        $this->assertFalse(get_option('postcaster_options', false));
    }

    public function test_run_migrates_master_postmeta_and_usermeta_keys(): void
    {
        $postId = self::factory()->post->create();
        $userId = self::factory()->user->create();

        add_post_meta($postId, '_postcaster_disable_publish', '1');
        add_user_meta($userId, '_postcaster_user_enabled', '1');
        add_user_meta($userId, '_postcaster_admin_notice_42', 'queued');
        add_user_meta($userId, '_postcaster_settings_notice', 'settings');

        LegacyMigration::run();

        $this->assertSame('1', get_post_meta($postId, '_justbee_postcaster_disable_publish', true));
        $this->assertFalse(metadata_exists('post', $postId, '_postcaster_disable_publish'));
        $this->assertSame('1', get_user_meta($userId, '_justbee_postcaster_user_enabled', true));
        $this->assertSame('queued', get_user_meta($userId, '_justbee_postcaster_admin_notice_42', true));
        $this->assertSame('settings', get_user_meta($userId, '_justbee_postcaster_settings_notice', true));
    }

    public function test_run_leaves_conflicting_legacy_meta_row_in_place_during_half_update(): void
    {
        $postId = self::factory()->post->create();

        add_post_meta($postId, '_postcaster_disable_publish', '1');
        add_post_meta($postId, '_justbee_postcaster_disable_publish', '0');

        LegacyMigration::run();

        $this->assertSame('0', get_post_meta($postId, '_justbee_postcaster_disable_publish', true));
        $this->assertTrue(metadata_exists('post', $postId, '_postcaster_disable_publish'));
    }

    public function test_run_migrates_master_action_scheduler_hook_when_table_exists(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture setup.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            $this->markTestSkipped('Action Scheduler table is not available in this test environment.');
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture setup.
        $wpdb->insert($table, [
            'hook' => 'postcaster_publish_target',
            'status' => 'pending',
            'scheduled_date_gmt' => gmdate('Y-m-d H:i:s'),
            'scheduled_date_local' => gmdate('Y-m-d H:i:s'),
            'priority' => 10,
            'args' => '[]',
            'schedule' => 'O:30:"ActionScheduler_NullSchedule":0:{}',
            'group_id' => 0,
            'attempts' => 0,
            'last_attempt_gmt' => '0000-00-00 00:00:00',
            'last_attempt_local' => '0000-00-00 00:00:00',
            'claim_id' => 0,
            'extended_args' => null,
        ]);
        $actionId = (int) $wpdb->insert_id;

        LegacyMigration::run();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- assertion query for migration test.
        $hook = $wpdb->get_var($wpdb->prepare("SELECT hook FROM {$table} WHERE action_id = %d", $actionId));
        $this->assertSame('justbee_postcaster_publish_target', $hook);
    }

    public function test_runner_only_marks_migration_complete_after_success(): void
    {
        $tracker = (object) ['attempts' => 0];
        $migration = new class($tracker) implements MigrationInterface {
            private object $tracker;

            public function __construct(object $tracker)
            {
                $this->tracker = $tracker;
            }

            public function id(): string
            {
                return 'test_flaky_migration';
            }

            public function migrate(): void
            {
                $this->tracker->attempts++;
                if ($this->tracker->attempts === 1) {
                    throw new RuntimeException('boom');
                }
            }
        };

        $this->assertFalse(MigrationRunner::runMigrations([$migration]));
        $failedState = MigrationRunner::getState();
        $this->assertArrayNotHasKey('test_flaky_migration', $failedState['completed']);
        $this->assertArrayHasKey('test_flaky_migration', $failedState['failed']);

        $this->assertTrue(MigrationRunner::runMigrations([$migration]));
        $completedState = MigrationRunner::getState();
        $this->assertArrayHasKey('test_flaky_migration', $completedState['completed']);
        $this->assertArrayNotHasKey('test_flaky_migration', $completedState['failed']);
        $this->assertSame(2, $tracker->attempts);
    }

    public function test_runner_takes_over_stale_lock(): void
    {
        update_option(MigrationRunner::LOCK_OPTION, [
            'acquired_at' => time() - 600,
        ], false);

        $tracker = (object) ['attempts' => 0];
        $migration = new class($tracker) implements MigrationInterface {
            private object $tracker;

            public function __construct(object $tracker)
            {
                $this->tracker = $tracker;
            }

            public function id(): string
            {
                return 'test_stale_lock_migration';
            }

            public function migrate(): void
            {
                $this->tracker->attempts++;
            }
        };

        $this->assertTrue(MigrationRunner::runMigrations([$migration]));
        $this->assertSame(1, $tracker->attempts);
        $this->assertFalse(get_option(MigrationRunner::LOCK_OPTION, false));
    }
}
