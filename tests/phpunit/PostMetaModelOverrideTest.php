<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\PostMetaModel;

final class PostMetaModelOverrideTest extends WP_UnitTestCase
{
    private PostMetaModel $model;
    private int $postId;

    public function set_up(): void
    {
        parent::set_up();
        $this->model = new PostMetaModel();
        $this->postId = self::factory()->post->create(['post_status' => 'draft']);
    }

    public function test_resolve_post_template_falls_back_to_scope_when_network_override_absent(): void
    {
        $this->model->savePostTemplate($this->postId, 'Scope template', 'default-template', 'global');

        $this->assertSame('Scope template', $this->model->resolvePostTemplate($this->postId, 'global', 'bluesky'));
    }

    public function test_resolve_post_template_prefers_network_override(): void
    {
        $this->model->savePostTemplate($this->postId, 'Scope template', 'default-template', 'global');
        $this->model->saveNetworkPostTemplate($this->postId, 'global', 'bluesky', 'Bluesky-only', 'default-template');

        $this->assertSame('Bluesky-only', $this->model->resolvePostTemplate($this->postId, 'global', 'bluesky'));
        $this->assertSame('Scope template', $this->model->resolvePostTemplate($this->postId, 'global', 'mastodon'));
    }

    public function test_resolve_post_template_returns_empty_when_nothing_set(): void
    {
        $this->assertSame('', $this->model->resolvePostTemplate($this->postId, 'global', 'bluesky'));
        $this->assertSame('', $this->model->resolvePostTemplate($this->postId, 'personal', null));
    }

    public function test_save_network_post_template_deletes_when_equal_to_default(): void
    {
        $this->model->saveNetworkPostTemplate($this->postId, 'global', 'bluesky', 'Same', 'Same');

        $this->assertSame('', $this->model->getNetworkPostTemplate($this->postId, 'global', 'bluesky'));
    }

    public function test_resolve_include_featured_image_uses_network_override(): void
    {
        $this->model->saveIncludeFeaturedImageNetworkOverride($this->postId, 'global', 'bluesky', '1');

        $this->assertTrue($this->model->resolveIncludeFeaturedImage($this->postId, 'global', 'bluesky', false));
    }

    public function test_resolve_include_featured_image_falls_back_to_scope_then_default(): void
    {
        $this->assertFalse($this->model->resolveIncludeFeaturedImage($this->postId, 'global', 'bluesky', false));
        $this->assertTrue($this->model->resolveIncludeFeaturedImage($this->postId, 'global', 'bluesky', true));

        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'global', '0');
        $this->assertFalse($this->model->resolveIncludeFeaturedImage($this->postId, 'global', 'bluesky', true));

        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'global', '');
        $this->assertTrue($this->model->resolveIncludeFeaturedImage($this->postId, 'global', 'bluesky', true));
    }

    public function test_save_scope_override_clears_meta_when_inheriting(): void
    {
        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'global', '1');
        $this->assertSame('1', $this->model->getIncludeFeaturedImageScopeOverride($this->postId, 'global'));

        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'global', '');
        $this->assertNull($this->model->getIncludeFeaturedImageScopeOverride($this->postId, 'global'));
    }

    public function test_personal_scope_uses_separate_storage(): void
    {
        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'global', '1');
        $this->model->saveIncludeFeaturedImageScopeOverride($this->postId, 'personal', '0');

        $this->assertSame('1', $this->model->getIncludeFeaturedImageScopeOverride($this->postId, 'global'));
        $this->assertSame('0', $this->model->getIncludeFeaturedImageScopeOverride($this->postId, 'personal'));
    }
}
