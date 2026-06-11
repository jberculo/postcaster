<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\BlueskyPublisher;

final class BlueskyPublisherTest extends WP_UnitTestCase
{
    private BlueskyPublisher $publisher;

    /** @var array<int, array{url:string,args:array}> */
    private array $requests = [];

    /** @var string[] */
    private array $tempFiles = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->publisher = new BlueskyPublisher(new HttpService(), new MediaService());
        add_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10);

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }

        parent::tear_down();
    }

    public function test_publish_without_asset_keeps_url_in_text_and_omits_embed(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_excerpt' => 'Short summary for the Bluesky card.',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);
        $text = "Lead paragraph\n\n" . $permalink;

        $result = $this->publisher->publish($post, [
            'bluesky_service_url' => 'https://bsky.social',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'app-password',
        ], null, $text);

        $this->assertIsArray($result);
        $this->assertSame('at://did:plc:test/app.bsky.feed.post/abc123', $result['id']);

        $request = $this->findRequest('/xrpc/com.atproto.repo.createRecord');
        $body = json_decode((string) $request['args']['body'], true);
        $record = $body['record'] ?? [];

        $this->assertSame($text, $record['text'] ?? null, 'URL must remain in text when no card is attached.');
        $this->assertArrayNotHasKey('embed', $record, 'No embed when caller passes a null asset.');
    }

    public function test_publish_test_with_example_post_builds_card_and_strips_url(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_excerpt' => 'Short summary for the Bluesky card.',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);
        $text = "Lead paragraph\n\n" . $permalink;

        $result = $this->publisher->publishTest([
            'bluesky_service_url' => 'https://bsky.social',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'app-password',
            'bluesky_include_featured_image' => '1',
        ], $text, [
            'post' => $post,
            'asset' => $this->createAsset('card-image-bytes'),
            'include_featured_image' => true,
        ]);

        $this->assertIsArray($result);
        $this->assertSame('at://did:plc:test/app.bsky.feed.post/abc123', $result['id']);

        $request = $this->findRequest('/xrpc/com.atproto.repo.createRecord');
        $body = json_decode((string) $request['args']['body'], true);
        $record = $body['record'] ?? [];

        $this->assertSame('Lead paragraph', $record['text'] ?? null, 'Test posts should strip the URL when building a Bluesky card.');
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type'] ?? null, 'Test posts should attach a Bluesky card embed.');
        $this->assertSame($permalink, $record['embed']['external']['uri'] ?? null);
        $this->assertArrayHasKey('thumb', $record['embed']['external'] ?? [], 'Card embed should include the uploaded thumbnail blob.');
    }

    public function test_finalize_post_text_returns_text_unchanged_when_image_not_attached(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);
        $text = "Lead\n\n" . $url;

        $this->assertSame($text, $this->publisher->finalizePostText($post, [], $text, false));
    }

    public function test_finalize_post_text_returns_text_unchanged_when_post_has_no_url_match(): void
    {
        $post = $this->createPublishedPost();
        $text = 'Just a sentence';

        $this->assertSame($text, $this->publisher->finalizePostText($post, [], $text, true));
    }

    public function test_finalize_post_text_strips_url_and_keeps_paragraph_break(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);
        $text = "Lead\n\n" . $url . "\n\nMore text";

        $this->assertSame("Lead\n\nMore text", $this->publisher->finalizePostText($post, [], $text, true));
    }

    public function test_finalize_post_text_strips_url_and_keeps_soft_break(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);
        $text = "Lead\n" . $url . "\nMore text";

        $this->assertSame("Lead\nMore text", $this->publisher->finalizePostText($post, [], $text, true));
    }

    public function test_finalize_post_text_strips_trailing_url_and_removes_dangling_newlines(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);
        $text = "Lead\n\n" . $url;

        $this->assertSame('Lead', $this->publisher->finalizePostText($post, [], $text, true));
    }

    public function test_should_render_preview_card_only_requires_url_in_text(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);

        $this->assertTrue($this->publisher->shouldRenderPreviewCard($post, "Lead\n\n" . $url, [], true));
        $this->assertFalse(
            $this->publisher->shouldRenderPreviewCard($post, 'Lead without URL', [], true),
            'No card when the rendered text omits the URL.'
        );
        $this->assertTrue(
            $this->publisher->shouldRenderPreviewCard($post, 'Lead ' . $url, [], false),
            'Card is still previewed when the feature is off — Bluesky may auto-render it; getPreviewWarning() flags the uncertainty.'
        );
    }

    public function test_get_preview_warning_only_appears_when_feature_off_and_url_in_text(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);

        $this->assertNotNull(
            $this->publisher->getPreviewWarning($post, "Lead\n\n" . $url, [], false),
            'Warning expected when feature is off and the URL is in the text.'
        );
        $this->assertNull(
            $this->publisher->getPreviewWarning($post, "Lead\n\n" . $url, [], true),
            'No warning when the feature is on — PostCaster uploads the card itself.'
        );
        $this->assertNull(
            $this->publisher->getPreviewWarning($post, 'Lead without URL', [], false),
            'No warning when there is no URL to render a card from.'
        );
    }

    public function test_should_attach_asset_mirrors_card_decision(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);

        $this->assertTrue($this->publisher->shouldAttachAsset($post, $url, [], true));
        $this->assertFalse(
            $this->publisher->shouldAttachAsset($post, 'No URL here', [], true),
            'No asset when the user removed {url} even with the feature on.'
        );
        $this->assertFalse(
            $this->publisher->shouldAttachAsset($post, $url, [], false),
            'No asset when the feature is off.'
        );
    }

    public function test_finalize_post_text_strips_leading_url(): void
    {
        $post = $this->createPublishedPost();
        $url = (string) get_permalink($post);
        $text = $url . "\n\nMore";

        $this->assertSame('More', $this->publisher->finalizePostText($post, [], $text, true));
    }

    private function createPublishedPost(): WP_Post
    {
        $postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Hello world',
        ]);

        return get_post($postId);
    }

    /** @return array{url:string,args:array} */
    private function findRequest(string $needle): array
    {
        foreach ($this->requests as $request) {
            if (str_contains($request['url'], $needle)) {
                return $request;
            }
        }

        $this->fail('Expected HTTP request containing ' . $needle . '.');
    }

    public function interceptHttpRequest($preempt, array $parsedArgs, string $url)
    {
        $this->requests[] = [
            'url' => $url,
            'args' => $parsedArgs,
        ];

        if (str_contains($url, '/xrpc/com.atproto.server.createSession')) {
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'accessJwt' => 'jwt-token',
                    'did' => 'did:plc:test',
                ]),
            ];
        }

        if (str_contains($url, '/xrpc/com.atproto.repo.createRecord')) {
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'uri' => 'at://did:plc:test/app.bsky.feed.post/abc123',
                ]),
            ];
        }

        if (str_contains($url, '/xrpc/com.atproto.repo.uploadBlob')) {
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'blob' => [
                        '$type' => 'blob',
                        'ref' => ['$link' => 'bafkreiblobtest'],
                        'mimeType' => 'image/jpeg',
                        'size' => 16,
                    ],
                ]),
            ];
        }

        return $preempt;
    }

    public function test_publish_attaches_thumbless_card_when_post_has_no_featured_image(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'No featured image article',
            'post_excerpt' => 'Summary for the thumbless card.',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);

        // PublisherService strips {url} from the text when the option is on,
        // and passes a null asset because the post has no thumbnail. The
        // publisher must still build a clickable card from title +
        // description + permalink, no fallback needed.
        $result = $this->publisher->publish($post, [
            'bluesky_service_url' => 'https://bsky.social',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'app-password',
            'bluesky_include_featured_image' => '1',
        ], null, 'Lead paragraph');

        $this->assertIsArray($result);

        $request = $this->findRequest('/xrpc/com.atproto.repo.createRecord');
        $body = json_decode((string) $request['args']['body'], true);
        $record = $body['record'] ?? [];

        $this->assertSame('Lead paragraph', $record['text'] ?? null, 'URL stays stripped — the embed carries the link.');
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type'] ?? null);
        $this->assertSame($permalink, $record['embed']['external']['uri'] ?? null);
        $this->assertArrayNotHasKey('thumb', $record['embed']['external'] ?? [], 'No thumb when the post has no featured image.');
    }

    public function test_publish_restores_url_in_text_when_embed_thumb_upload_fails(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Article with broken image',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);

        // PublisherService strips {url} from the text before calling publish()
        // when include_featured_image is on. Simulate that pre-stripped state:
        $strippedText = 'Lead paragraph';
        $brokenAsset = [
            'path' => 'Z:/missing-image.jpg',
            'mime' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'alt' => 'Missing',
        ];

        $result = $this->publisher->publish($post, [
            'bluesky_service_url' => 'https://bsky.social',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'app-password',
            'bluesky_include_featured_image' => '1',
        ], $brokenAsset, $strippedText);

        $this->assertIsArray($result, 'Publish should still succeed by falling back to a card-less post.');

        $request = $this->findRequest('/xrpc/com.atproto.repo.createRecord');
        $body = json_decode((string) $request['args']['body'], true);
        $record = $body['record'] ?? [];

        $this->assertArrayNotHasKey('embed', $record, 'Embed must be omitted when the thumb upload fails.');
        $this->assertStringContainsString(
            $permalink,
            (string) ($record['text'] ?? ''),
            'URL must be restored to the text so the post still references the article.'
        );
    }

    public function test_publish_test_restores_url_in_text_when_embed_thumb_upload_fails(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Test article with broken image',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);

        $brokenAsset = [
            'path' => 'Z:/missing-image.jpg',
            'mime' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'alt' => 'Missing',
        ];

        $result = $this->publisher->publishTest([
            'bluesky_service_url' => 'https://bsky.social',
            'bluesky_identifier' => 'newsroom.bsky.social',
            'bluesky_app_password' => 'app-password',
            'bluesky_include_featured_image' => '1',
        ], 'Lead paragraph ' . $permalink, [
            'post' => $post,
            'asset' => $brokenAsset,
            'include_featured_image' => true,
        ]);

        $this->assertIsArray($result, 'Test publish should succeed by falling back to a card-less post.');

        $request = $this->findRequest('/xrpc/com.atproto.repo.createRecord');
        $body = json_decode((string) $request['args']['body'], true);
        $record = $body['record'] ?? [];

        $this->assertArrayNotHasKey('embed', $record);
        $this->assertStringContainsString($permalink, (string) ($record['text'] ?? ''));
    }

    private function createAsset(string $contents): array
    {
        $path = wp_tempnam('postcaster-bluesky-test.jpg');
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
