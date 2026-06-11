<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;

/**
 * @group ajax
 */
final class PreviewAjaxTest extends WP_Ajax_UnitTestCase
{
    private int $adminId;
    private int $authorId;
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9p0p3L8AAAAASUVORK5CYII=';

    public function set_up(): void
    {
        parent::set_up();
        $this->adminId = self::factory()->user->create(['role' => 'administrator']);
        $this->authorId = self::factory()->user->create(['role' => 'author']);

        // Ensure the plugin is enabled so the controllers don't short-circuit early.
        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'enabled' => '1',
                'personal_networks_enabled' => '0',
                'post_types' => ['post'],
                'template_enabled' => '1',
                'template' => '{title} {url}',
            ]
        ));
    }

    private function dispatch(string $action): ?array
    {
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException $e) {
            // Expected — wp_send_json_* dies via our test-aware override.
        } catch (WPAjaxDieStopException $e) {
            // Also expected — some flows wp_die() directly.
        }

        if ($this->_last_response === '') {
            return null;
        }

        return json_decode($this->_last_response, true);
    }

    private function createImageAttachment(int $postId, string $filename): int
    {
        $uploadDir = wp_upload_dir();
        wp_mkdir_p($uploadDir['path']);

        $sourceFile = trailingslashit($uploadDir['path']) . $filename;
        file_put_contents($sourceFile, (string) base64_decode(self::TINY_PNG_BASE64));
        $attachmentId = wp_insert_attachment([
            'post_title'     => wp_basename($sourceFile),
            'post_content'   => '',
            'post_type'      => 'attachment',
            'post_parent'    => $postId,
            'post_mime_type' => 'image/png',
            'guid'           => trailingslashit($uploadDir['url']) . $filename,
        ], $sourceFile, $postId, true);

        $this->assertIsInt($attachmentId);
        update_attached_file($attachmentId, $sourceFile);
        wp_update_attachment_metadata(
            $attachmentId,
            wp_generate_attachment_metadata($attachmentId, $sourceFile)
        );

        return $attachmentId;
    }

    public function test_preview_post_template_rejects_missing_post_id(): void
    {
        wp_set_current_user($this->adminId);
        $_POST = ['post_id' => '0'];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
    }

    public function test_preview_post_template_rejects_unauthenticated_user(): void
    {
        wp_set_current_user(0);
        $postId = self::factory()->post->create();
        $_POST = ['post_id' => (string) $postId];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
    }

    public function test_preview_post_template_rejects_bad_nonce(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create();
        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => 'definitely-not-valid',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        // Bad nonce dies with -1 or an error; either way, no successful JSON payload.
        $this->assertTrue($response === null || (is_array($response) && ($response['success'] ?? false) === false));
    }

    public function test_preview_post_template_rejects_when_no_publishing_contexts_configured(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create();
        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertFalse($response['success'], 'Without any configured network, preview must be rejected.');
    }

    public function test_preview_template_example_rejects_anonymous_user(): void
    {
        wp_set_current_user(0);
        $_POST = ['network_key' => 'mastodon', 'template' => '{title}'];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
    }

    public function test_preview_template_example_returns_text_for_logged_in_user(): void
    {
        wp_set_current_user($this->adminId);
        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'mastodon',
            'template' => '{title} {url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('items', $response['data']);
        $this->assertArrayHasKey('text', $response['data']);
        $this->assertStringContainsString('Lorem ipsum', $response['data']['text']);
    }

    public function test_preview_template_example_rejects_non_admin_global_preview(): void
    {
        wp_set_current_user($this->authorId);
        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'mastodon',
            'template' => '{title} {url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
    }

    public function test_preview_template_example_allows_personal_preview_for_editable_user(): void
    {
        wp_set_current_user($this->authorId);
        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'mastodon',
            'scope' => 'personal',
            'user_id' => (string) $this->authorId,
            'template' => '{title} {url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('text', $response['data']);
    }

    public function test_preview_template_example_renders_mastodon_template_verbatim(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Latest preview source',
            'post_status' => 'publish',
        ]);
        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'mastodon_enabled' => '1',
                'mastodon_base_url' => 'https://mastodon.example',
                'mastodon_access_token' => 'secret',
                'mastodon_visibility' => 'public',
            ]
        ));

        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'mastodon',
            'template' => '{title}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        // Without {url} in the template, the URL is not auto-appended for Mastodon.
        $this->assertSame('Latest preview source', $response['data']['text']);
        $this->assertSame('Mastodon', $response['data']['items'][0]['label'] ?? null);
    }

    public function test_preview_post_template_applies_network_specific_text_processing(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Preview me',
            'post_status' => 'publish',
        ]);
        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'mastodon_enabled' => '1',
                'mastodon_base_url' => 'https://mastodon.example',
                'mastodon_access_token' => 'secret',
                'mastodon_visibility' => 'public',
            ]
        ));

        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
            'template_context' => 'global',
            'template' => '{title}' . "\n\n" . '{url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame('Preview me' . "\n\n" . get_permalink($postId), $response['data']['text']);
    }

    public function test_preview_post_template_returns_featured_image_when_requested(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Preview with image',
            'post_status' => 'publish',
        ]);
        $attachmentId = $this->createImageAttachment($postId, 'postcaster-preview-ajax.png');
        set_post_thumbnail($postId, $attachmentId);

        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'mastodon_enabled' => '1',
                'mastodon_base_url' => 'https://mastodon.example',
                'mastodon_access_token' => 'secret',
                'mastodon_visibility' => 'public',
            ]
        ));

        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
            'template_context' => 'global',
            'template' => '{title}' . "\n\n" . '{url}',
            'include_featured_image' => '1',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame('Preview with image' . "\n\n" . get_permalink($postId), $response['data']['text']);
        $this->assertStringContainsString('postcaster-preview-ajax.png', (string) ($response['data']['image_url'] ?? ''));
        $this->assertSame('Preview with image', $response['data']['card']['title'] ?? null);
    }

    public function test_preview_post_template_keeps_bluesky_card_with_override_off_and_warns(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Bluesky image override',
            'post_excerpt' => 'Override preview summary',
            'post_status' => 'publish',
        ]);
        $attachmentId = $this->createImageAttachment($postId, 'postcaster-preview-override.png');
        set_post_thumbnail($postId, $attachmentId);

        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'bluesky_enabled' => '1',
                'bluesky_service_url' => 'https://bsky.social',
                'bluesky_identifier' => 'newsroom.bsky.social',
                'bluesky_app_password' => 'app-password',
                'bluesky_include_featured_image' => '1',
            ]
        ));

        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
            'template_context' => 'global',
            'network_key' => 'bluesky',
            'template' => '{title} {url}',
            'include_featured_image' => '0',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertStringContainsString(
            get_permalink($postId),
            (string) ($response['data']['text'] ?? ''),
            'URL stays in the rendered text when the override turns the feature off.'
        );

        $items = $response['data']['items'] ?? [];
        $this->assertNotEmpty($items, 'Preview must still produce items so the card and warning render.');
        $this->assertSame(get_permalink($postId), $items[0]['card']['url'] ?? null);
        $this->assertNotEmpty($items[0]['warning'] ?? '', 'Warning must surface that Bluesky may not auto-render the card.');
    }

    public function test_preview_template_example_returns_bluesky_card_without_visible_permalink(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Bluesky example card',
            'post_excerpt' => 'Bluesky preview summary',
            'post_status' => 'publish',
        ]);
        $attachmentId = $this->createImageAttachment($postId, 'postcaster-preview-bluesky.png');
        set_post_thumbnail($postId, $attachmentId);

        // Bluesky only attaches a card (and strips the URL from the text)
        // when the include_featured_image flag is on.
        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            ['bluesky_include_featured_image' => '1']
        ));

        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'bluesky',
            'template' => '{title} {url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertStringNotContainsString(get_permalink($postId), (string) ($response['data']['text'] ?? ''));
        $this->assertSame('Bluesky example card', $response['data']['card']['title'] ?? null);
        $this->assertSame(get_permalink($postId), $response['data']['card']['url'] ?? null);
        $this->assertStringContainsString('postcaster-preview-bluesky.png', (string) ($response['data']['card']['image_url'] ?? ''));
        $this->assertSame('Bluesky', $response['data']['items'][0]['label'] ?? null);
    }

    public function test_preview_post_template_strips_url_for_mastodon_when_template_lacks_url(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Mastodon stripped',
            'post_status' => 'publish',
        ]);

        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'mastodon_enabled' => '1',
                'mastodon_base_url' => 'https://mastodon.example',
                'mastodon_access_token' => 'secret',
                'mastodon_visibility' => 'public',
            ]
        ));

        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
            'template_context' => 'global',
            'network_key' => 'mastodon',
            'template' => '{title}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame('Mastodon stripped', $response['data']['text'] ?? null);
        $this->assertNull($response['data']['card'] ?? null, 'No card when template omits {url}.');
        $this->assertStringNotContainsString(get_permalink($postId), (string) ($response['data']['text'] ?? ''));
    }

    public function test_preview_post_template_skips_bluesky_card_when_template_lacks_url_placeholder(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'Title only',
            'post_status' => 'publish',
        ]);

        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            [
                'bluesky_enabled' => '1',
                'bluesky_service_url' => 'https://bsky.social',
                'bluesky_identifier' => 'newsroom.bsky.social',
                'bluesky_app_password' => 'app-password',
                'bluesky_include_featured_image' => '1',
            ]
        ));

        $_POST = [
            'post_id' => (string) $postId,
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $postId),
            'template_context' => 'global',
            'network_key' => 'bluesky',
            'template' => '{title}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_post_template');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertSame('Title only', $response['data']['text'] ?? null);
        $this->assertNull($response['data']['card'] ?? null, 'No card when {url} is omitted from the template.');
    }

    public function test_preview_template_example_keeps_url_and_warns_when_bluesky_feature_off(): void
    {
        wp_set_current_user($this->adminId);
        $postId = self::factory()->post->create([
            'post_title' => 'No-card example',
            'post_status' => 'publish',
        ]);

        update_option(SettingsModel::OPTION_NAME, array_merge(
            (array) get_option(SettingsModel::OPTION_NAME, []),
            ['bluesky_include_featured_image' => '0']
        ));

        $_POST = [
            '_ajax_nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
            'network_key' => 'bluesky',
            'template' => '{title} {url}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertStringContainsString(
            get_permalink($postId),
            (string) ($response['data']['text'] ?? ''),
            'URL stays in the text when PostCaster will not upload a card itself.'
        );

        $items = $response['data']['items'] ?? [];
        $this->assertNotEmpty($items);
        $this->assertSame(get_permalink($postId), $items[0]['card']['url'] ?? null);
        $this->assertNotEmpty($items[0]['warning'] ?? '');
    }

    public function test_preview_template_example_rejects_bad_nonce(): void
    {
        wp_set_current_user($this->adminId);
        $_POST = [
            '_ajax_nonce' => 'nope',
            'network_key' => 'mastodon',
            'template' => '{title}',
        ];

        $response = $this->dispatch('justbee_postcaster_preview_template_example');

        $this->assertTrue($response === null || ($response['success'] ?? false) === false);
    }
}
