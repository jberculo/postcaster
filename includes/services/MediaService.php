<?php

namespace Justbee\PostCaster\Services;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaService
{
    public function getPostImageAsset(int $postId, string $context = 'publish'): ?array
    {
        $attachmentId = (int) apply_filters('justbee_postcaster_post_image_attachment_id', get_post_thumbnail_id($postId), $postId, $context);

        $asset = null;

        if ($attachmentId) {
            $asset = $this->buildAttachmentAsset($attachmentId);
        }

        $filteredAsset = apply_filters('justbee_postcaster_post_image_asset', $asset, $postId, $context, $attachmentId);
        if ($filteredAsset === null) {
            return null;
        }

        if (!is_array($filteredAsset)) {
            return null;
        }

        return $filteredAsset;
    }

    public function readImageBytes(array $asset, string $errorCode, string $errorMessage)
    {
        if (empty($asset['path']) || !is_string($asset['path']) || !is_readable($asset['path'])) {
            return new WP_Error($errorCode, $errorMessage);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- reading a local attachment's bytes; WP_Filesystem is for writes/remote paths.
        $bytes = file_get_contents($asset['path']);
        if ($bytes === false) {
            return new WP_Error($errorCode, $errorMessage);
        }

        return [
            'bytes' => $bytes,
            'mime' => $asset['mime'],
            'width' => $asset['width'],
            'height' => $asset['height'],
        ];
    }

    public function prepareImageForUpload(array $asset, array $constraints, array $errors = [])
    {
        $prepared = $this->readImageBytes(
            $asset,
            $errors['read_code'] ?? 'justbee_postcaster_media_read',
            $errors['read_message'] ?? __('Could not read the image.', 'postcaster')
        );
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        $maxBytes = isset($constraints['max_bytes']) ? (int) $constraints['max_bytes'] : 0;
        $maxWidth = isset($constraints['max_width']) ? (int) $constraints['max_width'] : 0;
        $quality = isset($constraints['quality']) ? (int) $constraints['quality'] : 0;
        $outputMime = (string) ($constraints['output_mime'] ?? $asset['mime']);

        $requiresResize = $maxWidth > 0 && (int) ($asset['width'] ?? 0) > $maxWidth;
        $requiresReencode = $outputMime !== '' && $outputMime !== (string) ($asset['mime'] ?? '');
        $requiresShrink = $maxBytes > 0 && strlen($prepared['bytes']) > $maxBytes;

        if (!$requiresResize && !$requiresReencode && !$requiresShrink) {
            return $prepared;
        }

        $editor = wp_get_image_editor($asset['path']);
        if (is_wp_error($editor)) {
            return $editor;
        }

        $size = $editor->get_size();
        if (!empty($size['width']) && $maxWidth > 0 && $size['width'] > $maxWidth) {
            $editor->resize($maxWidth, null);
        }
        if ($quality > 0 && method_exists($editor, 'set_quality')) {
            $editor->set_quality($quality);
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temp = wp_tempnam($asset['path']);
        if (!$temp) {
            return new WP_Error(
                $errors['temp_code'] ?? 'justbee_postcaster_media_temp',
                $errors['temp_message'] ?? __('Could not create a temporary image file.', 'postcaster')
            );
        }

        $saved = $editor->save($temp, $outputMime);
        if (is_wp_error($saved)) {
            return $saved;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- reading bytes of a temp file we just wrote locally.
        $tempBytes = file_get_contents($saved['path']);
        if ($tempBytes === false) {
            return new WP_Error(
                $errors['temp_read_code'] ?? 'justbee_postcaster_media_temp_read',
                $errors['temp_read_message'] ?? __('Could not read the temporary image file.', 'postcaster')
            );
        }
        if ($maxBytes > 0 && strlen($tempBytes) > $maxBytes) {
            return new WP_Error(
                $errors['size_code'] ?? 'justbee_postcaster_media_size',
                $errors['size_message'] ?? __('The processed image still exceeds the upload limit.', 'postcaster')
            );
        }

        $imageSize = @getimagesize($saved['path']);
        return [
            'bytes' => $tempBytes,
            'mime' => $outputMime,
            'width' => isset($imageSize[0]) ? (int) $imageSize[0] : $asset['width'],
            'height' => isset($imageSize[1]) ? (int) $imageSize[1] : $asset['height'],
            'temp' => $saved['path'],
        ];
    }

    public function getPreviewImageUrl(array $asset): ?string
    {
        $attachmentId = isset($asset['attachment_id']) ? (int) $asset['attachment_id'] : 0;
        if ($attachmentId > 0) {
            $url = wp_get_attachment_image_url($attachmentId, 'large');
            if (is_string($url) && $url !== '') {
                return $url;
            }

            $url = wp_get_attachment_url($attachmentId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if (empty($asset['path']) || !is_string($asset['path'])) {
            return null;
        }

        $uploads = wp_get_upload_dir();
        $baseDir = (string) ($uploads['basedir'] ?? '');
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') {
            return null;
        }

        $normalizedBaseDir = wp_normalize_path($baseDir);
        $normalizedPath = wp_normalize_path($asset['path']);
        if (!str_starts_with($normalizedPath, trailingslashit($normalizedBaseDir))) {
            return null;
        }

        $relativePath = ltrim(substr($normalizedPath, strlen($normalizedBaseDir)), '/');

        return trailingslashit($baseUrl) . str_replace('\\', '/', $relativePath);
    }

    private function getAttachmentFilePath(int $attachmentId): ?string
    {
        $preferred = wp_get_attachment_image_src($attachmentId, 'large');
        $basePath = get_attached_file($attachmentId);
        if (!$basePath) {
            return null;
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if ($preferred && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $preferredUrl = $preferred[0];
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file']) && str_ends_with($preferredUrl, $size['file'])) {
                    return trailingslashit(dirname($basePath)) . $size['file'];
                }
            }
        }

        return $basePath;
    }

    private function buildAttachmentAsset(int $attachmentId): ?array
    {
        $path = $this->getAttachmentFilePath($attachmentId);
        if (!$path || !is_readable($path)) {
            return null;
        }

        $mime = get_post_mime_type($attachmentId) ?: wp_check_filetype($path)['type'] ?: 'image/jpeg';
        $imageSize = @getimagesize($path);
        $alt = trim((string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true));
        if ($alt === '') {
            $attachment = get_post($attachmentId);
            $alt = trim((string) ($attachment->post_excerpt ?? ''));
            if ($alt === '') {
                $alt = trim((string) ($attachment->post_title ?? ''));
            }
        }

        return [
            'attachment_id' => $attachmentId,
            'path' => $path,
            'mime' => $mime,
            'alt' => $alt,
            'width' => isset($imageSize[0]) ? (int) $imageSize[0] : 0,
            'height' => isset($imageSize[1]) ? (int) $imageSize[1] : 0,
        ];
    }
}
