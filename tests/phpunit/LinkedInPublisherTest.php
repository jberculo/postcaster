<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\Networks\LinkedInPublisher;

final class LinkedInPublisherTest extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        remove_all_filters('pre_http_request');
        parent::tear_down();
    }

    public function test_publish_returns_upload_error_when_binary_upload_http_status_is_not_successful(): void
    {
        $publisher = new LinkedInPublisher(new HttpService(), new MediaService());
        $post = get_post(self::factory()->post->create([
            'post_title' => 'LinkedIn upload test',
            'post_status' => 'publish',
        ]));

        $calls = [];
        add_filter('pre_http_request', function ($preempt, array $parsedArgs, string $url) use (&$calls) {
            $calls[] = [$url, $parsedArgs['method'] ?? 'POST'];

            if (str_contains($url, 'initializeUpload')) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'value' => [
                            'uploadUrl' => 'https://upload.linkedin.test/image',
                            'image' => 'urn:li:image:123',
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://upload.linkedin.test/image') {
                return [
                    'headers' => [],
                    'body' => 'upload rejected',
                    'response' => ['code' => 403, 'message' => 'Forbidden'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return $preempt;
        }, 10, 3);

        $result = $publisher->publish($post, [
            'linkedin_access_token' => 'token',
            'linkedin_author_urn' => 'urn:li:organization:123',
            'linkedin_version' => '202604',
        ], [
            'path' => __FILE__,
            'mime' => 'image/jpeg',
            'alt' => 'Example image',
            'width' => 100,
            'height' => 100,
        ], 'Body text');

        $this->assertWPError($result);
        $this->assertSame('justbee_postcaster_linkedin_upload', $result->get_error_code());
        $this->assertStringContainsString('HTTP 403', $result->get_error_message());
        $this->assertCount(2, $calls);
    }

    public function test_publish_waits_for_media_to_become_available_within_same_job(): void
    {
        $publisher = new LinkedInPublisher(new HttpService(), new MediaService());
        $post = get_post(self::factory()->post->create([
            'post_title' => 'LinkedIn delayed media',
            'post_status' => 'publish',
        ]));

        $statusChecks = 0;
        add_filter('pre_http_request', function ($preempt, array $parsedArgs, string $url) use (&$statusChecks) {
            if (str_contains($url, 'initializeUpload')) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'value' => [
                            'uploadUrl' => 'https://upload.linkedin.test/image',
                            'image' => 'urn:li:image:123',
                        ],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://upload.linkedin.test/image') {
                return [
                    'headers' => [],
                    'body' => '',
                    'response' => ['code' => 201, 'message' => 'Created'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if (str_contains($url, '/rest/images/urn%3Ali%3Aimage%3A123')) {
                $statusChecks++;

                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'status' => $statusChecks >= 2 ? 'AVAILABLE' : 'PROCESSING',
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if ($url === 'https://api.linkedin.com/rest/posts') {
                return [
                    'headers' => ['x-restli-id' => 'urn:li:share:123'],
                    'body' => '',
                    'response' => ['code' => 201, 'message' => 'Created'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return $preempt;
        }, 10, 3);

        $result = $publisher->publish($post, [
            'linkedin_access_token' => 'token',
            'linkedin_author_urn' => 'urn:li:organization:123',
            'linkedin_version' => '202604',
        ], [
            'path' => __FILE__,
            'mime' => 'image/jpeg',
            'alt' => 'Example image',
            'width' => 100,
            'height' => 100,
        ], 'Body text');

        $this->assertIsArray($result);
        $this->assertSame('urn:li:share:123', $result['id']);
        $this->assertSame(2, $statusChecks);
    }
}
