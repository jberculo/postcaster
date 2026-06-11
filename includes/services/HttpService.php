<?php

namespace Justbee\PostCaster\Services;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class HttpService
{
    private const BODY_EXCERPT_LIMIT = 500;

    /** @return array|WP_Error */
    public function jsonPost(string $url, array $body, array $headers = [])
    {
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
            'body' => wp_json_encode($body),
        ]);

        return $this->decodeJsonResponse($response, __('JSON request failed.', 'postcaster'));
    }

    /** @return array|WP_Error */
    public function jsonGet(string $url, array $query = [], array $headers = [])
    {
        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ]);

        return $this->decodeJsonResponse($response, __('JSON request failed.', 'postcaster'));
    }

    /** @return array|WP_Error */
    public function multipartPost(string $url, array $fields, array $headers = [])
    {
        $boundary = '--------------------------' . wp_generate_password(24, false, false);
        $body = '';

        foreach ($fields as $name => $field) {
            $body .= '--' . $boundary . "\r\n";
            if (is_array($field) && isset($field['filename'], $field['contents'])) {
                $body .= sprintf("Content-Disposition: form-data; name=\"%s\"; filename=\"%s\"\r\n", $name, $field['filename']);
                $body .= 'Content-Type: ' . ($field['type'] ?? 'application/octet-stream') . "\r\n\r\n";
                $body .= $field['contents'] . "\r\n";
            } else {
                $body .= sprintf("Content-Disposition: form-data; name=\"%s\"\r\n\r\n", $name);
                $body .= $field . "\r\n";
            }
        }
        $body .= '--' . $boundary . "--\r\n";

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => array_merge(['Content-Type' => 'multipart/form-data; boundary=' . $boundary], $headers),
            'body' => $body,
        ]);

        return $this->decodeJsonResponse($response, __('Multipart upload failed.', 'postcaster'));
    }

    /** @return array|WP_Error */
    public function decodeJsonResponse($response, string $errorMessage)
    {
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            $excerpt = $this->buildBodyExcerpt($body);
            return new WP_Error(
                'justbee_postcaster_http',
                trim(sprintf('%s HTTP %d %s', $errorMessage, $code, $excerpt)),
                [
                    'status' => $code,
                    'retry_after' => $this->parseRetryAfter(wp_remote_retrieve_header($response, 'retry-after')),
                    'body_excerpt' => $excerpt,
                ]
            );
        }

        if (!is_array($decoded)) {
            return new WP_Error(
                'justbee_postcaster_json',
                $errorMessage . ' ' . __('Invalid JSON response.', 'postcaster'),
                ['retryable' => false]
            );
        }

        return $decoded;
    }

    private function buildBodyExcerpt(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($body) > self::BODY_EXCERPT_LIMIT) {
            return mb_substr($body, 0, self::BODY_EXCERPT_LIMIT) . '…';
        }

        if (strlen($body) > self::BODY_EXCERPT_LIMIT) {
            return substr($body, 0, self::BODY_EXCERPT_LIMIT) . '…';
        }

        return $body;
    }

    /**
     * Parse a Retry-After header (seconds or HTTP date) into a positive
     * integer in seconds, capped at 1 hour. Returns 0 when missing or
     * unparseable.
     */
    private function parseRetryAfter($header): int
    {
        $value = is_array($header) ? (string) ($header[0] ?? '') : (string) $header;
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return min((int) $value, 3600);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 0;
        }

        $delta = $timestamp - time();
        if ($delta <= 0) {
            return 0;
        }

        return min($delta, 3600);
    }
}
