<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\BlueskyRecordBuilder;

final class BlueskyRecordBuilderTest extends WP_UnitTestCase
{
    private BlueskyRecordBuilder $builder;
    /** @var array<string, string> */
    private array $resolvedHandles = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->builder = new BlueskyRecordBuilder(new HttpService(), new MediaService());
        add_filter('pre_http_request', [$this, 'mockResolveHandleRequests'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'mockResolveHandleRequests'], 10);
        $this->resolvedHandles = [];
        parent::tear_down();
    }

    public function test_build_record_keeps_text_unchanged_and_skips_embed_when_render_card_is_false(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_excerpt' => 'Short summary for the Bluesky card.',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);
        $text = "Lead paragraph\n\n" . $permalink;

        $record = $this->builder->buildRecord($post, 'https://bsky.social', 'jwt-token', $text, false, null);

        $this->assertSame('app.bsky.feed.post', $record['$type']);
        $this->assertSame($text, $record['text']);
        $this->assertArrayNotHasKey('embed', $record, 'No embed should be attached when the publisher opts out of card rendering.');
    }

    public function test_build_record_adds_mention_facets_for_resolved_placeholder_candidates(): void
    {
        $this->resolvedHandles = [
            'yoast.bsky.social' => 'did:plc:yoast123',
            'author.example.com' => 'did:web:author.example.com',
        ];

        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $text = 'Via @yoast.bsky.social met dank aan @author.example.com.';

        $record = $this->builder->buildRecord(
            $post,
            'https://bsky.social',
            'jwt-token',
            $text,
            false,
            null,
            ['@yoast.bsky.social', '@author.example.com']
        );

        $this->assertArrayHasKey('facets', $record);
        $this->assertCount(2, $record['facets']);
        $this->assertSame('app.bsky.richtext.facet#mention', $record['facets'][0]['features'][0]['$type']);
        $this->assertSame('did:plc:yoast123', $record['facets'][0]['features'][0]['did']);
        $this->assertSame('did:web:author.example.com', $record['facets'][1]['features'][0]['did']);
    }

    public function test_build_record_skips_unresolvable_placeholder_candidates(): void
    {
        $this->resolvedHandles = [
            'yoast.bsky.social' => 'did:plc:yoast123',
        ];

        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $text = 'Bekend: @yoast.bsky.social onbekend: @missing.example.com';

        $record = $this->builder->buildRecord(
            $post,
            'https://bsky.social',
            'jwt-token',
            $text,
            false,
            null,
            ['@yoast.bsky.social', '@missing.example.com']
        );

        $this->assertArrayHasKey('facets', $record);
        $this->assertCount(1, $record['facets']);
        $this->assertSame('did:plc:yoast123', $record['facets'][0]['features'][0]['did']);
    }

    public function test_build_record_does_not_auto_mention_plain_handles_without_candidates(): void
    {
        $this->resolvedHandles = [
            'yoast.bsky.social' => 'did:plc:yoast123',
        ];

        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $text = 'Vrije tekst @yoast.bsky.social';

        $record = $this->builder->buildRecord($post, 'https://bsky.social', 'jwt-token', $text, false, null);

        $this->assertArrayNotHasKey('facets', $record);
    }

    public function test_build_record_attaches_thumbless_card_when_render_card_is_true_without_asset(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'No-thumb article',
            'post_excerpt' => 'Article without a featured image.',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $record = $this->builder->buildRecord($post, 'https://bsky.social', 'jwt-token', 'Lead', true, null);

        $this->assertArrayHasKey('embed', $record, 'A card embed must be attached when render_card is true, even without a featured image.');
        $this->assertSame('app.bsky.embed.external', $record['embed']['$type']);
        $this->assertSame(get_permalink($post), $record['embed']['external']['uri']);
        $this->assertSame('No-thumb article', $record['embed']['external']['title']);
        $this->assertArrayNotHasKey('thumb', $record['embed']['external'], 'No thumb when there is no asset.');
    }

    public function test_build_record_normalizes_line_endings_in_text(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $record = $this->builder->buildRecord($post, 'https://bsky.social', 'jwt-token', "Lead\r\nNext", false, null);

        $this->assertSame("Lead\nNext", $record['text']);
    }

    public function test_build_record_uses_utf8_byte_offsets_for_mentions(): void
    {
        $this->resolvedHandles = [
            'yoast.bsky.social' => 'did:plc:yoast123',
        ];

        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $text = "\u{00E9}\u{00E9}n @yoast.bsky.social";

        $record = $this->builder->buildRecord(
            $post,
            'https://bsky.social',
            'jwt-token',
            $text,
            false,
            null,
            ['@yoast.bsky.social']
        );

        $this->assertSame(6, $record['facets'][0]['index']['byteStart']);
        $this->assertSame(24, $record['facets'][0]['index']['byteEnd']);
    }

    public function test_build_record_returns_wp_error_when_asset_cannot_be_prepared(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Preview article',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $record = $this->builder->buildRecord($post, 'https://bsky.social', 'jwt-token', 'Lead', true, [
            'path' => 'Z:/missing-image.jpg',
            'mime' => 'image/jpeg',
            'alt' => 'Alt text',
            'width' => 1200,
            'height' => 630,
        ]);

        $this->assertInstanceOf(WP_Error::class, $record);
        $this->assertSame('justbee_postcaster_bluesky_read', $record->get_error_code());
    }

    public function mockResolveHandleRequests($preempt, array $parsedArgs, string $url)
    {
        if (!str_contains($url, '/xrpc/com.atproto.identity.resolveHandle')) {
            return $preempt;
        }

        $query = wp_parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $queryArgs);
        $handle = strtolower((string) ($queryArgs['handle'] ?? ''));

        if ($handle !== '' && isset($this->resolvedHandles[$handle])) {
            return [
                'response' => ['code' => 200, 'message' => 'OK'],
                'headers' => [],
                'body' => wp_json_encode(['did' => $this->resolvedHandles[$handle]]),
                'cookies' => [],
                'filename' => null,
            ];
        }

        return [
            'response' => ['code' => 400, 'message' => 'Bad Request'],
            'headers' => [],
            'body' => wp_json_encode(['error' => 'InvalidRequest', 'message' => 'Unknown handle']),
            'cookies' => [],
            'filename' => null,
        ];
    }
}
