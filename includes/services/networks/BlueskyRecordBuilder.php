<?php

namespace Justbee\PostCaster\Services\Networks;

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class BlueskyRecordBuilder
{
    private HttpService $http;
    private MediaService $media;

    public function __construct(HttpService $http, MediaService $media)
    {
        $this->http = $http;
        $this->media = $media;
    }

    /**
     * @param bool $renderCard When true, attach a Bluesky link-card embed.
     *                         The asset (if any) is used as the optional
     *                         thumb on that card; absence of an asset
     *                         still produces a card (title + description
     *                         + url, no thumb).
     */
    public function buildRecord(WP_Post $post, string $serviceUrl, string $accessJwt, string $text, bool $renderCard, ?array $asset = null, array $mentionCandidates = [])
    {
        $normalizedText = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $normalizedText,
            'createdAt' => gmdate('c'),
        ];

        $facets = $this->buildMentionFacets($serviceUrl, $normalizedText, $mentionCandidates);
        if ($facets !== []) {
            $record['facets'] = $facets;
        }

        if (!$renderCard) {
            return $record;
        }

        $externalEmbed = $this->buildExternalEmbed($post, $serviceUrl, $accessJwt, $asset);
        if (is_wp_error($externalEmbed)) {
            return $externalEmbed;
        }
        if ($externalEmbed !== null) {
            $record['embed'] = $externalEmbed;
        }

        return $record;
    }

    /**
     * @return array<int, array{index: array{byteStart: int, byteEnd: int}, features: array<int, array{$type: string, did: string}>}>
     */
    private function buildMentionFacets(string $serviceUrl, string $text, array $mentionCandidates): array
    {
        if ($text === '' || $mentionCandidates === []) {
            return [];
        }

        $facets = [];
        $resolvedHandles = [];
        $serviceUrl = untrailingslashit($serviceUrl);
        $cursor = 0;

        foreach ($mentionCandidates as $candidate) {
            $mention = trim((string) $candidate);
            if ($mention === '' || !str_starts_with($mention, '@')) {
                continue;
            }

            $byteStart = strpos($text, $mention, $cursor);
            if ($byteStart === false) {
                continue;
            }

            $handle = strtolower(ltrim($mention, '@'));
            if ($handle === '') {
                continue;
            }

            if (!array_key_exists($handle, $resolvedHandles)) {
                $resolvedHandles[$handle] = $this->resolveHandleDid($serviceUrl, $handle);
            }

            $did = $resolvedHandles[$handle];
            if ($did === '') {
                continue;
            }

            $facets[] = [
                'index' => [
                    'byteStart' => $byteStart,
                    'byteEnd' => $byteStart + strlen($mention),
                ],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#mention',
                    'did' => $did,
                ]],
            ];

            $cursor = $byteStart + strlen($mention);
        }

        return $facets;
    }

    private function resolveHandleDid(string $serviceUrl, string $handle): string
    {
        $result = $this->http->jsonGet(
            $serviceUrl . '/xrpc/com.atproto.identity.resolveHandle',
            ['handle' => $handle]
        );

        if (is_wp_error($result)) {
            return '';
        }

        return (string) ($result['did'] ?? '');
    }

    private function buildExternalEmbed(WP_Post $post, string $serviceUrl, string $accessJwt, ?array $asset)
    {
        $uri = get_permalink($post);
        if (!is_string($uri) || $uri === '') {
            return null;
        }

        $external = [
            'uri' => $uri,
            'title' => $this->decodeText(wp_strip_all_tags(get_the_title($post))),
            'description' => $this->buildExternalDescription($post),
        ];

        $thumbBlob = $asset ? $this->uploadPreparedBlueskyImage($serviceUrl, $accessJwt, $asset) : null;
        if (is_wp_error($thumbBlob)) {
            return $thumbBlob;
        }
        if (is_array($thumbBlob) && isset($thumbBlob['blob'])) {
            $external['thumb'] = $thumbBlob['blob'];
        }

        return [
            '$type' => 'app.bsky.embed.external',
            'external' => $external,
        ];
    }

    private function uploadPreparedBlueskyImage(string $serviceUrl, string $accessJwt, array $asset)
    {
        $prepared = $this->media->prepareImageForUpload($asset, [
            'max_bytes' => 1000000,
            'max_width' => 1600,
            'quality' => 82,
            'output_mime' => 'image/jpeg',
        ],                                              [
                                                            'read_code' => 'justbee_postcaster_bluesky_read',
                                                            'read_message' => __(
                                                                'Could not read Bluesky image.',
                                                                'postcaster'
                                                            ),
                                                            'temp_code' => 'justbee_postcaster_bluesky_temp',
                                                            'temp_message' => __(
                                                                'Could not create a temporary file for Bluesky.',
                                                                'postcaster'
                                                            ),
                                                            'temp_read_code' => 'justbee_postcaster_bluesky_temp_read',
                                                            'temp_read_message' => __(
                                                                'Could not read temporary Bluesky image.',
                                                                'postcaster'
                                                            ),
                                                            'size_code' => 'justbee_postcaster_bluesky_size',
                                                            'size_message' => __(
                                                                'Bluesky image still exceeds 1,000,000 bytes.',
                                                                'postcaster'
                                                            ),
                                                        ]);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $blobResponse = wp_remote_post($serviceUrl . '/xrpc/com.atproto.repo.uploadBlob', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $accessJwt,
                'Content-Type' => $prepared['mime'],
            ],
            'body' => $prepared['bytes'],
        ]);

        if (isset($prepared['temp']) && is_file($prepared['temp'])) {
            wp_delete_file($prepared['temp']);
        }

        $blobResult = $this->http->decodeJsonResponse($blobResponse, __('Bluesky blob upload failed.', 'postcaster'));

        return $blobResult;
    }

    private function buildExternalDescription(WP_Post $post): string
    {
        $description = has_excerpt($post)
            ? get_the_excerpt($post)
            : wp_trim_words(wp_strip_all_tags(strip_shortcodes((string)$post->post_content)), 30, '...');

        return $this->decodeText(trim((string)$description));
    }

    private function decodeText(string $text): string
    {
        $decoded = $text;

        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode(wp_specialchars_decode($decoded, ENT_QUOTES), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $decoded;
    }
}
