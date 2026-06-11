<?php

use Justbee\PostCaster\Controllers\PublishController;
use Justbee\PostCaster\Services\PublishQueueService;

final class PublishControllerMetaBoxTest extends WP_UnitTestCase
{
    use \BuildsPublisherStack;

    public function test_render_meta_box_outputs_article_specific_options_with_publishing_context(): void
    {
        $this->buildPublisherStack([
            'fake_include_featured_image' => '1',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Preview source',
            'post_status' => 'draft',
        ]);
        $post = get_post($postId);

        $this->assertInstanceOf(\WP_Post::class, $post);

        ob_start();
        $controller->renderMetaBox($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-postcaster-compose', $output);
        $this->assertStringContainsString('justbee_postcaster_post_drafts[global][combined]', $output);
        $this->assertStringContainsString('Preview source', $output);
        $this->assertStringContainsString('Skip automatic publishing for this article', $output);
        $this->assertStringNotContainsString('Send the featured image with this post', $output);
        $this->assertStringContainsString('data-postcaster-edit', $output);
    }

    public function test_register_meta_box_marks_justbee_postcaster_box_as_block_editor_compatible(): void
    {
        global $wp_meta_boxes;

        $wp_meta_boxes = [];
        $this->buildPublisherStack([
            'fake_include_featured_image' => '1',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Preview source',
            'post_status' => 'draft',
        ]);
        $post = get_post($postId);

        $this->assertInstanceOf(\WP_Post::class, $post);

        $controller->registerMetaBox('post', $post);

        $metaBox = $wp_meta_boxes['post']['side']['default']['postcaster-compose'] ?? null;
        $this->assertIsArray($metaBox);
        $this->assertTrue(
            (bool) ($metaBox['args']['__block_editor_compatible_meta_box'] ?? false),
            'The PostCaster metabox should explicitly opt into block editor compatibility so article-specific template changes persist on the post page.'
        );
    }

    public function test_save_meta_box_persists_article_specific_template_override_when_enabled(): void
    {
        $this->buildPublisherStack([
            'fake_include_featured_image' => '1',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Custom template source',
            'post_status' => 'draft',
        ]);
        $post = get_post($postId);

        $this->assertInstanceOf(\WP_Post::class, $post);

        $_POST = [
            'justbee_postcaster_post_nonce' => wp_create_nonce('justbee_postcaster_post_settings'),
            'justbee_postcaster_post_drafts' => [
                'global' => [
                    'combined' => '{title}' . "\n" . 'Custom CTA',
                ],
            ],
        ];

        $controller->saveMetaBox($postId, $post);

        $this->assertSame(
            '{title}' . "\n" . 'Custom CTA',
            $this->postMeta->getPostTemplate($postId, 'global')
        );

        unset($_POST);
    }

    public function test_render_meta_box_outputs_nothing_without_publishing_context(): void
    {
        $this->buildPublisherStack([
            'personal_networks_enabled' => '0',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $authorId = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($authorId);

        $postId = self::factory()->post->create([
            'post_title' => 'No context',
            'post_status' => 'draft',
            'post_author' => $authorId,
        ]);
        $post = get_post($postId);

        $this->assertInstanceOf(\WP_Post::class, $post);

        ob_start();
        $controller->renderMetaBox($post);
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_render_meta_box_shows_retry_notice_when_retry_is_scheduled(): void
    {
        $this->buildPublisherStack([
            'fake_include_featured_image' => '1',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Retry source',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        as_schedule_single_action(time() + 300, PublishQueueService::ACTION_HOOK, [[
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => 2,
            'trigger' => 'retry',
        ]], 'postcaster-post-' . $postId);

        ob_start();
        $controller->renderMetaBox($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('PostCaster will retry failed targets automatically.', $output);
        $this->assertStringContainsString('Retry attempt 1 is queued in the background scheduler.', $output);

        as_unschedule_all_actions(PublishQueueService::ACTION_HOOK, [], 'postcaster-post-' . $postId);
    }

    public function test_render_meta_box_shows_retry_limit_notice_when_errors_remain(): void
    {
        $this->buildPublisherStack([
            'fake_include_featured_image' => '1',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $postId = self::factory()->post->create([
            'post_title' => 'Retry limit source',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $this->postMeta->saveError($postId, 'fake', 'global', 'Upstream rejected the request');
        as_schedule_single_action(time() - 60, PublishQueueService::ACTION_HOOK, [[
            'post_id' => $postId,
            'network_key' => 'fake',
            'target_key' => 'global',
            'allow_repost' => false,
            'attempt' => 4,
            'trigger' => 'retry',
        ]], 'postcaster-post-' . $postId);

        ob_start();
        $controller->renderMetaBox($post);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('PostCaster stopped retrying automatically for this article.', $output);
        $this->assertStringContainsString('Fix the reported problem below, then publish manually', $output);
    }

    public function test_save_meta_box_ignores_article_specific_options_without_publishing_context(): void
    {
        $this->buildPublisherStack([
            'personal_networks_enabled' => '0',
        ]);
        $controller = new PublishController($this->publisher, $this->postMeta);
        $authorId = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($authorId);

        $postId = self::factory()->post->create([
            'post_status' => 'draft',
            'post_author' => $authorId,
        ]);
        $post = get_post($postId);

        $this->assertInstanceOf(\WP_Post::class, $post);

        $_POST = [
            'justbee_postcaster_post_nonce' => wp_create_nonce('justbee_postcaster_post_settings'),
            'justbee_postcaster_post_disable_publish' => '1',
            'justbee_postcaster_post_include_featured_image' => '1',
        ];

        $controller->saveMetaBox($postId, $post);

        $this->assertFalse($this->postMeta->isPublishDisabled($postId));
        $this->assertNull($this->postMeta->getIncludeFeaturedImageOverride($postId));

        unset($_POST);
    }
}
