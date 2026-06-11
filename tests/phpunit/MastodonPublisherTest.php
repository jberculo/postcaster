<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\MastodonPublisher;

final class MastodonPublisherTest extends WP_UnitTestCase
{
    private MastodonPublisher $publisher;

    /** @var array<int, array{url:string,args:array}> */
    private array $requests = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->publisher = new MastodonPublisher(new HttpService(), new MediaService());
        add_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10);
        parent::tear_down();
    }

    public function test_publish_sends_text_verbatim_without_auto_url(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Mastodon preview',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $result = $this->publisher->publish($post, [
            'mastodon_base_url' => 'https://mastodon.example',
            'mastodon_access_token' => 'secret',
            'mastodon_visibility' => 'public',
        ], null, 'Lead paragraph');

        $this->assertIsArray($result);
        $this->assertSame('remote-status', $result['id']);

        $request = $this->findRequest('/api/v1/statuses');
        $this->assertSame('Lead paragraph', $request['args']['body']['status']);
    }

    public function test_publish_keeps_text_unchanged_for_mastodon(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Mastodon preview',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $permalink = get_permalink($post);
        $text = "Intro\n\n" . $permalink . "\n\nMore context";

        $this->publisher->publish($post, [
            'mastodon_base_url' => 'https://mastodon.example',
            'mastodon_access_token' => 'secret',
            'mastodon_visibility' => 'public',
        ], null, $text);

        $request = $this->findRequest('/api/v1/statuses');
        $status = (string) $request['args']['body']['status'];

        // Mastodon now respects template intent verbatim — no auto URL relocation.
        $this->assertSame($text, $status);
    }

    public function test_publish_keeps_status_url_free_when_template_omits_it(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Mastodon plain',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $this->publisher->publish($post, [
            'mastodon_base_url' => 'https://mastodon.example',
            'mastodon_access_token' => 'secret',
            'mastodon_visibility' => 'public',
        ], null, 'Just a thought');

        $request = $this->findRequest('/api/v1/statuses');
        $status = (string) $request['args']['body']['status'];

        $this->assertSame('Just a thought', $status);
        $this->assertStringNotContainsString(get_permalink($post), $status);
    }

    public function test_publish_waits_for_media_to_finish_processing_within_same_job(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Mastodon delayed media',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $statusChecks = 0;

        remove_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10);
        add_filter('pre_http_request', function ($preempt, array $parsedArgs, string $url) use (&$statusChecks) {
            $this->requests[] = [
                'url' => $url,
                'args' => $parsedArgs,
            ];

            if (str_contains($url, '/api/v2/media')) {
                return [
                    'response' => ['code' => 200],
                    'body' => wp_json_encode([
                        'id' => 'media-123',
                    ]),
                ];
            }

            if (str_contains($url, '/api/v1/media/media-123')) {
                $statusChecks++;

                return [
                    'response' => ['code' => 200],
                    'body' => wp_json_encode([
                        'processing' => $statusChecks >= 2 ? 'succeeded' : 'processing',
                    ]),
                ];
            }

            if (str_contains($url, '/api/v1/statuses')) {
                return [
                    'response' => ['code' => 200],
                    'body' => wp_json_encode([
                        'id' => 'remote-status',
                        'url' => 'https://mastodon.example/@newsroom/123',
                    ]),
                ];
            }

            return $preempt;
        }, 10, 3);

        $result = $this->publisher->publish($post, [
            'mastodon_base_url' => 'https://mastodon.example',
            'mastodon_access_token' => 'secret',
            'mastodon_visibility' => 'public',
        ], [
            'path' => __FILE__,
            'mime' => 'image/jpeg',
            'alt' => 'Example image',
            'width' => 100,
            'height' => 100,
        ], 'Lead paragraph');

        $this->assertIsArray($result);
        $this->assertSame('remote-status', $result['id']);
        $this->assertSame(2, $statusChecks);
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

        if (str_contains($url, '/api/v1/statuses')) {
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode([
                    'id' => 'remote-status',
                    'url' => 'https://mastodon.example/@newsroom/123',
                ]),
            ];
        }

        return $preempt;
    }
}
