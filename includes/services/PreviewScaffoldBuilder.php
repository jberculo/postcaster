<?php

namespace Justbee\PostCaster\Services;

use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the visual scaffolding around a rendered preview text:
 * featured-image data, link-card data and the avatar/header block.
 *
 * Extracted from PreviewBuilder so the orchestration there stays focused
 * on text generation; this class owns the WordPress lookups and the
 * presentation shape that views/card-renderers consume.
 */
final class PreviewScaffoldBuilder
{
    private NetworkRegistry $networks;
    private MediaService $media;
    private TargetContextResolver $context;

    public function __construct(
        NetworkRegistry $networks,
        MediaService $media,
        TargetContextResolver $context
    ) {
        $this->networks = $networks;
        $this->media = $media;
        $this->context = $context;
    }

    public function shouldPreviewFeaturedImageForContext(
        WP_Post $post,
        string $context,
        ?bool $includeFeaturedImageOverride
    ): bool {
        if ($includeFeaturedImageOverride !== null) {
            return $includeFeaturedImageOverride;
        }

        foreach ($this->context->getContextTargets($post, $context) as $target) {
            if ($this->shouldPreviewFeaturedImageForNetwork((string) $target['network_key'], (array) $target['options'])) {
                return true;
            }
        }

        return false;
    }

    public function shouldPreviewFeaturedImageForNetwork(string $networkKey, array $options): bool
    {
        $network = $this->networks->get($networkKey);
        if ($network === null) {
            return false;
        }

        return ($options[$network->optionKey('include_featured_image')] ?? '0') === '1';
    }

    public function buildImageData(int $postId): ?array
    {
        $attachmentId = (int) apply_filters(
            'justbee_postcaster_post_image_attachment_id',
            get_post_thumbnail_id($postId),
            $postId,
            'preview'
        );

        if ($attachmentId > 0) {
            $url = wp_get_attachment_image_url($attachmentId, 'large');
            if (!is_string($url) || $url === '') {
                $url = wp_get_attachment_url($attachmentId);
            }

            if (is_string($url) && $url !== '') {
                $alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
                if ($alt === '') {
                    $attachment = get_post($attachmentId);
                    $alt = trim((string) ($attachment->post_excerpt ?? ''));
                    if ($alt === '') {
                        $alt = trim((string) ($attachment->post_title ?? ''));
                    }
                }

                return [
                    'url' => $url,
                    'alt' => $alt,
                ];
            }
        }

        $asset = $this->media->getPostImageAsset($postId, 'preview');
        if (!is_array($asset)) {
            return null;
        }

        $url = $this->media->getPreviewImageUrl($asset);
        if (!is_string($url) || $url === '') {
            return null;
        }

        return [
            'url' => $url,
            'alt' => (string) ($asset['alt'] ?? ''),
        ];
    }

    public function buildCardData(
        string $networkKey,
        WP_Post $post,
        string $text,
        ?array $image,
        array $targetOptions = [],
        bool $includeFeaturedImage = false
    ): ?array {
        $url = get_permalink($post);
        if (!is_string($url) || $url === '') {
            return null;
        }

        if ($networkKey === '') {
            // Combined preview: show card whenever the URL is in the text.
            if (!str_contains($text, $url)) {
                return null;
            }
        } else {
            $network = $this->networks->get($networkKey);
            if ($network === null || !$network->shouldRenderPreviewCard($post, $text, $targetOptions, $includeFeaturedImage)) {
                return null;
            }
        }

        $title = wp_strip_all_tags(get_the_title($post));
        $description = has_excerpt($post)
            ? get_the_excerpt($post)
            : wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)), 30, '...');
        $description = trim(wp_strip_all_tags($description));
        $domain = (string) wp_parse_url($url, PHP_URL_HOST);

        $imageUrl = null;
        $imageAlt = null;
        if (is_array($image)) {
            $imageUrl = (string) ($image['url'] ?? '');
            $imageAlt = (string) ($image['alt'] ?? '');
        }

        return [
            'network' => $networkKey,
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'domain' => $domain,
            'image_url' => $imageUrl,
            'image_alt' => $imageAlt,
        ];
    }

    public function buildHeaderData(
        string $networkKey,
        string $networkLabel,
        array $values,
        bool $preferAuthor = false
    ): array {
        $nameCandidates = $preferAuthor
            ? [
                (string) ($values['displayname'] ?? ''),
                (string) ($values['author'] ?? ''),
                (string) ($values['site'] ?? ''),
            ]
            : [
                (string) ($values['site'] ?? ''),
                (string) ($values['displayname'] ?? ''),
                (string) ($values['author'] ?? ''),
            ];

        $name = '';
        foreach ($nameCandidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $name = $candidate;
                break;
            }
        }

        if ($name === '') {
            $name = $networkLabel;
        }

        $referenceCandidates = $preferAuthor
            ? [
                (string) ($values['@author'] ?? ''),
                (string) ($values['account'] ?? ''),
                (string) ($values['@site'] ?? ''),
            ]
            : [
                (string) ($values['account'] ?? ''),
                (string) ($values['@site'] ?? ''),
                (string) ($values['@author'] ?? ''),
            ];

        $reference = '';
        foreach ($referenceCandidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $reference = $candidate;
                break;
            }
        }

        $metaParts = [];
        if ($reference !== '' && $reference !== $name) {
            $metaParts[] = $reference;
        }
        $metaParts[] = $networkLabel;
        $metaParts[] = __('Preview', 'postcaster');

        $avatarText = ltrim($name, '@');
        $avatarText = $avatarText !== '' ? mb_substr($avatarText, 0, 1, 'UTF-8') : mb_substr($networkLabel, 0, 1, 'UTF-8');

        return [
            'network' => $networkKey,
            'name' => $name,
            'meta' => implode(' · ', array_filter($metaParts)),
            'avatar_text' => mb_strtoupper($avatarText, 'UTF-8'),
        ];
    }
}
