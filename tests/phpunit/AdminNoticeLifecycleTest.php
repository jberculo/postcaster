<?php

declare(strict_types=1);

use Justbee\PostCaster\Controllers\AdminController;

final class AdminNoticeLifecycleTest extends WP_UnitTestCase
{
    public function test_post_notice_roundtrips_through_user_meta(): void
    {
        $userId = self::factory()->user->create();
        $postId = self::factory()->post->create();
        wp_set_current_user($userId);

        AdminController::queuePostNotice($postId, 'Hello admin', 'warning');

        $notice = AdminController::consumePostNotice($postId, $userId);
        $this->assertNotNull($notice);
        $this->assertSame('warning', $notice['type']);
        $this->assertSame('Hello admin', $notice['message']);
    }

    public function test_post_notice_is_consumed_only_once(): void
    {
        $userId = self::factory()->user->create();
        $postId = self::factory()->post->create();
        wp_set_current_user($userId);

        AdminController::queuePostNotice($postId, 'One-shot', 'info');
        AdminController::consumePostNotice($postId, $userId);

        $this->assertNull(
            AdminController::consumePostNotice($postId, $userId),
            'Consuming a notice must delete it; a second read returns null.'
        );
    }

    public function test_queue_post_notice_ignores_anonymous_user(): void
    {
        $postId = self::factory()->post->create();
        wp_set_current_user(0);

        AdminController::queuePostNotice($postId, 'Should not persist', 'info');

        $this->assertNull(AdminController::consumePostNotice($postId, 0));
    }

    public function test_queue_post_notice_ignores_invalid_post_id(): void
    {
        $userId = self::factory()->user->create();
        wp_set_current_user($userId);

        AdminController::queuePostNotice(0, 'Ignored', 'info');

        $this->assertNull(AdminController::consumePostNotice(0, $userId));
    }

    public function test_consume_post_notice_requires_payload_with_message(): void
    {
        $userId = self::factory()->user->create();
        $postId = self::factory()->post->create();

        update_user_meta($userId, '_justbee_postcaster_admin_notice_' . $postId, ['type' => 'info']);

        $this->assertNull(
            AdminController::consumePostNotice($postId, $userId),
            'A stored payload without a message must be treated as absent.'
        );
    }

    public function test_settings_notice_includes_timestamp_and_expiry(): void
    {
        $userId = self::factory()->user->create();
        wp_set_current_user($userId);

        AdminController::queueSettingsNotice('Saved successfully', 'success');
        $notice = AdminController::consumeSettingsNotice($userId);

        $this->assertNotNull($notice);
        $this->assertSame('success', $notice['type']);
        $this->assertArrayHasKey('timestamp', $notice);
        $this->assertArrayHasKey('expires_at', $notice);
        $this->assertGreaterThan(time(), $notice['expires_at']);
    }

    public function test_expired_settings_notice_returns_null(): void
    {
        $userId = self::factory()->user->create();

        update_user_meta($userId, '_justbee_postcaster_settings_notice', [
            'type' => 'info',
            'message' => 'Stale',
            'timestamp' => gmdate('c', time() - 10000),
            'expires_at' => time() - 100,
        ]);

        $this->assertNull(
            AdminController::consumeSettingsNotice($userId),
            'Expired settings notices must be dropped silently.'
        );
    }

    public function test_settings_notice_is_consumed_only_once(): void
    {
        $userId = self::factory()->user->create();
        wp_set_current_user($userId);

        AdminController::queueSettingsNotice('One-shot', 'info');
        AdminController::consumeSettingsNotice($userId);

        $this->assertNull(AdminController::consumeSettingsNotice($userId));
    }
}
