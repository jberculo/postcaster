<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\MediaService;

final class MediaServiceTest extends WP_UnitTestCase
{
    private MediaService $media;

    /** @var string[] */
    private array $tempFiles = [];

    public function set_up(): void
    {
        parent::set_up();

        $this->media = new MediaService();
    }

    public function tear_down(): void
    {
        remove_all_filters('justbee_postcaster_post_image_attachment_id');
        remove_all_filters('justbee_postcaster_post_image_asset');

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }

        parent::tear_down();
    }

    public function test_read_image_bytes_returns_original_asset_data(): void
    {
        $asset = $this->createAsset('example-bytes');

        $prepared = $this->media->readImageBytes($asset, 'justbee_postcaster_media_read', 'Could not read image.');

        $this->assertIsArray($prepared);
        $this->assertSame('example-bytes', $prepared['bytes']);
        $this->assertSame('image/jpeg', $prepared['mime']);
        $this->assertSame(1200, $prepared['width']);
        $this->assertSame(630, $prepared['height']);
    }

    public function test_prepare_image_for_upload_returns_original_bytes_when_constraints_are_already_met(): void
    {
        $asset = $this->createAsset('small-image');

        $prepared = $this->media->prepareImageForUpload($asset, [
            'max_bytes' => 1000000,
            'max_width' => 1600,
            'quality' => 82,
            'output_mime' => 'image/jpeg',
        ]);

        $this->assertIsArray($prepared);
        $this->assertSame('small-image', $prepared['bytes']);
        $this->assertSame('image/jpeg', $prepared['mime']);
        $this->assertSame(1200, $prepared['width']);
        $this->assertSame(630, $prepared['height']);
        $this->assertArrayNotHasKey('temp', $prepared);
    }

    public function test_read_image_bytes_returns_wp_error_for_missing_file(): void
    {
        $prepared = $this->media->readImageBytes([
            'path' => WP_CONTENT_DIR . '/uploads/postcaster-missing-image.jpg',
            'mime' => 'image/jpeg',
            'width' => 0,
            'height' => 0,
        ], 'justbee_postcaster_media_read', 'Could not read image.');

        $this->assertWPError($prepared);
        $this->assertSame('justbee_postcaster_media_read', $prepared->get_error_code());
    }

    public function test_get_post_image_asset_allows_context_specific_attachment_override(): void
    {
        $postId = self::factory()->post->create();
        $expectedAsset = $this->createAsset('override-attachment');

        add_filter('justbee_postcaster_post_image_attachment_id', function (int $attachmentId, int $filteredPostId, string $context) use ($postId): int {
            $this->assertSame($postId, $filteredPostId);
            $this->assertSame('publish', $context);

            return 999;
        }, 10, 3);

        add_filter('justbee_postcaster_post_image_asset', function ($asset, int $filteredPostId, string $context, int $attachmentId) use ($postId, $expectedAsset) {
            $this->assertNull($asset);
            $this->assertSame($postId, $filteredPostId);
            $this->assertSame('publish', $context);
            $this->assertSame(999, $attachmentId);

            return $expectedAsset;
        }, 10, 4);

        $asset = $this->media->getPostImageAsset($postId, 'publish');

        $this->assertSame($expectedAsset, $asset);
    }

    public function test_get_post_image_asset_allows_full_asset_override_without_attachment(): void
    {
        $postId = self::factory()->post->create();
        $expectedAsset = $this->createAsset('direct-asset');

        add_filter('justbee_postcaster_post_image_asset', function ($asset, int $filteredPostId, string $context, int $attachmentId) use ($postId, $expectedAsset) {
            $this->assertNull($asset);
            $this->assertSame($postId, $filteredPostId);
            $this->assertSame('custom-context', $context);
            $this->assertSame(0, $attachmentId);

            $expectedAsset['alt'] = 'Theme fallback image';

            return $expectedAsset;
        }, 10, 4);

        $asset = $this->media->getPostImageAsset($postId, 'custom-context');

        $this->assertIsArray($asset);
        $this->assertSame('Theme fallback image', $asset['alt']);
        $this->assertSame($expectedAsset['path'], $asset['path']);
    }

    private function createAsset(string $contents): array
    {
        $path = wp_tempnam('postcaster-media-test.jpg');
        $this->assertNotFalse($path);

        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return [
            'path' => $path,
            'mime' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'alt' => 'Example image',
        ];
    }
}
