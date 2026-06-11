<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\PostMetaModel;

final class PostMetaModelTest extends WP_UnitTestCase
{
    private PostMetaModel $meta;
    private int $postId;

    public function set_up(): void
    {
        parent::set_up();
        $this->meta = new PostMetaModel();
        $this->postId = self::factory()->post->create();
    }

    public function test_save_success_stores_remote_id_and_url_and_clears_error(): void
    {
        $this->meta->saveError($this->postId, 'mastodon', 'global', 'previous failure');
        $this->meta->saveSuccess($this->postId, 'mastodon', 'global', [
            'id' => 'abc',
            'url' => 'https://example.test/a',
        ]);

        $this->assertTrue($this->meta->hasRemoteId($this->postId, 'mastodon', 'global'));
        $this->assertEmpty($this->meta->getErrors($this->postId), 'A successful save must clear a pre-existing error.');
    }

    public function test_has_remote_id_returns_false_when_nothing_saved(): void
    {
        $this->assertFalse($this->meta->hasRemoteId($this->postId, 'mastodon', 'global'));
    }

    public function test_get_errors_extracts_network_and_target_from_meta_keys(): void
    {
        $this->meta->saveError($this->postId, 'mastodon', 'global', 'boom');
        $this->meta->saveError($this->postId, 'bluesky', 'user_42', 'bang');

        $errors = $this->meta->getErrors($this->postId);
        $this->assertCount(2, $errors);

        $byNetwork = array_column($errors, null, 'network');
        $this->assertSame('global', $byNetwork['mastodon']['target_key']);
        $this->assertSame('user_42', $byNetwork['bluesky']['target_key']);
    }

    public function test_has_any_remote_ids_detects_existing_publications(): void
    {
        $this->assertFalse($this->meta->hasAnyRemoteIds($this->postId));
        $this->meta->saveSuccess($this->postId, 'mastodon', 'global', ['id' => 'abc', 'url' => 'u']);
        $this->assertTrue($this->meta->hasAnyRemoteIds($this->postId));
    }

    public function test_get_posts_with_errors_lists_only_posts_with_open_errors(): void
    {
        $other = self::factory()->post->create();

        $this->meta->saveError($this->postId, 'mastodon', 'global', 'boom');
        $this->meta->saveError($other, 'bluesky', 'global', 'bang');

        $ids = $this->meta->getPostsWithErrors();

        $this->assertContains($this->postId, $ids);
        $this->assertContains($other, $ids);
    }

    public function test_save_success_invalidates_failures_cache(): void
    {
        $this->meta->saveError($this->postId, 'mastodon', 'global', 'boom');
        $this->assertContains($this->postId, $this->meta->getPostsWithErrors());

        $this->meta->saveSuccess($this->postId, 'mastodon', 'global', ['id' => 'a', 'url' => 'b']);

        $this->assertNotContains(
            $this->postId,
            $this->meta->getPostsWithErrors(),
            'A successful publish must drop the post from the failures listing on the next read.'
        );
    }

    public function test_save_error_invalidates_failures_cache(): void
    {
        $other = self::factory()->post->create();

        $this->meta->saveError($this->postId, 'mastodon', 'global', 'first');
        $this->assertSame([$this->postId], $this->meta->getPostsWithErrors());

        $this->meta->saveError($other, 'bluesky', 'global', 'second');

        $second = $this->meta->getPostsWithErrors();
        $this->assertContains(
            $other,
            $second,
            'A new error on a different post must show up immediately, not after the cache TTL.'
        );
        $this->assertContains(
            $this->postId,
            $second,
            'Existing errored posts must stay in the listing after invalidation refills the cache.'
        );
    }

    public function test_save_post_template_deletes_when_matches_default(): void
    {
        $this->meta->savePostTemplate($this->postId, 'custom', 'default', 'global');
        $this->assertSame('custom', $this->meta->getPostTemplate($this->postId));

        $this->meta->savePostTemplate($this->postId, 'default', 'default', 'global');
        $this->assertSame('', $this->meta->getPostTemplate($this->postId), 'Template equal to default must be deleted, not persisted.');
    }

    public function test_save_post_template_stores_personal_context_under_separate_key(): void
    {
        $this->meta->savePostTemplate($this->postId, 'global-template', 'default', 'global');
        $this->meta->savePostTemplate($this->postId, 'personal-template', 'default', 'personal');

        $this->assertSame('global-template', $this->meta->getPostTemplate($this->postId, 'global'));
        $this->assertSame('personal-template', $this->meta->getPostTemplate($this->postId, 'personal'));
    }

    /**
     * @dataProvider overrideDeletesWhenMatchingDefaultProvider
     */
    public function test_override_is_deleted_when_matching_default(
        string $saveMethod,
        string $getMethod,
        string $nonDefaultValue,
        string $defaultValue
    ): void {
        $this->meta->{$saveMethod}($this->postId, $nonDefaultValue, $defaultValue);
        $this->assertSame($nonDefaultValue, $this->meta->{$getMethod}($this->postId));

        $this->meta->{$saveMethod}($this->postId, $defaultValue, $defaultValue);
        $this->assertNull(
            $this->meta->{$getMethod}($this->postId),
            'Override equal to default must be removed so future default-changes propagate.'
        );
    }

    /** @return array<string, array{0:string,1:string,2:string,3:string}> */
    public function overrideDeletesWhenMatchingDefaultProvider(): array
    {
        return [
            'include featured image override' => [
                'saveIncludeFeaturedImageOverride',
                'getIncludeFeaturedImageOverride',
                '1',
                '0',
            ],
            'disable publish override' => [
                'saveDisablePublishOverride',
                'getDisablePublishOverride',
                '1',
                '0',
            ],
        ];
    }

    public function test_disable_publish_override_drives_is_publish_disabled(): void
    {
        $this->assertFalse($this->meta->isPublishDisabled($this->postId));

        $this->meta->saveDisablePublishOverride($this->postId, '1', '0');
        $this->assertTrue($this->meta->isPublishDisabled($this->postId));

        $this->meta->saveDisablePublishOverride($this->postId, '0', '0');
        $this->assertFalse($this->meta->isPublishDisabled($this->postId));
    }

    public function test_include_personal_networks_returns_default_when_no_override(): void
    {
        $this->assertSame('1', $this->meta->getIncludePersonalNetworks($this->postId, '1'));
        $this->assertSame('0', $this->meta->getIncludePersonalNetworks($this->postId, '0'));
    }

    public function test_retry_counter_increments_and_resets(): void
    {
        $this->assertSame(0, $this->meta->getRetryCount($this->postId));
        $this->assertSame(1, $this->meta->incrementRetryCount($this->postId));
        $this->assertSame(2, $this->meta->incrementRetryCount($this->postId));
        $this->meta->resetRetryCount($this->postId);
        $this->assertSame(0, $this->meta->getRetryCount($this->postId));
    }

    public function test_append_log_and_clear_log(): void
    {
        $this->meta->appendLog($this->postId, 'first entry');
        $this->meta->appendLog($this->postId, 'second entry');

        $lines = $this->meta->getLog($this->postId);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('first entry', $lines[0]);
        $this->assertStringContainsString('second entry', $lines[1]);

        $this->meta->clearLog($this->postId);
        $this->assertSame([], $this->meta->getLog($this->postId));
    }
}
