<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\HttpService;

final class HttpServiceTest extends WP_UnitTestCase
{
    private HttpService $http;

    public function set_up(): void
    {
        parent::set_up();
        $this->http = new HttpService();
    }

    public function test_decodes_successful_json_response(): void
    {
        $decoded = $this->http->decodeJsonResponse([
            'response' => ['code' => 200],
            'body' => '{"id":"abc","url":"https://example.test/post/1"}',
        ], 'Unused');

        $this->assertIsArray($decoded);
        $this->assertSame('abc', $decoded['id']);
    }

    public function test_passes_existing_wp_error_through_unchanged(): void
    {
        $original = new WP_Error('transport', 'Connection refused');
        $result = $this->http->decodeJsonResponse($original, 'Should not be prepended');
        $this->assertSame($original, $result);
    }

    public function test_non_2xx_response_becomes_http_error(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 401],
            'body' => '{"error":"unauthorized"}',
        ], 'Auth failed.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('justbee_postcaster_http', $result->get_error_code());
        $this->assertStringContainsString('HTTP 401', $result->get_error_message());
        $this->assertStringContainsString('Auth failed.', $result->get_error_message());
    }

    public function test_5xx_response_becomes_http_error(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 503],
            'body' => 'upstream down',
        ], 'Upload failed.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('justbee_postcaster_http', $result->get_error_code());
    }

    public function test_invalid_json_in_2xx_becomes_json_error(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 200],
            'body' => 'not json at all',
        ], 'Unexpected body.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('justbee_postcaster_json', $result->get_error_code());
    }

    public function test_non_array_json_in_2xx_becomes_json_error(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 200],
            'body' => 'true',
        ], 'Unexpected body.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('justbee_postcaster_json', $result->get_error_code());
    }

    public function test_invalid_json_marks_error_as_non_retryable(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 200],
            'body' => 'not json',
        ], 'Bad body.');

        $this->assertInstanceOf(WP_Error::class, $result);
        $data = $result->get_error_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('retryable', $data);
        $this->assertFalse($data['retryable']);
    }

    public function test_http_error_data_carries_status_and_truncated_body(): void
    {
        $body = str_repeat('A', 800);
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 500],
            'body' => $body,
        ], 'Failed.');

        $data = $result->get_error_data();
        $this->assertSame(500, $data['status']);
        $excerpt = (string) $data['body_excerpt'];
        $this->assertStringEndsWith('…', $excerpt, 'truncated body must signal it was cut');
        $this->assertLessThan(
            mb_strlen($body),
            mb_strlen($excerpt),
            'truncated body must be shorter than the input it summarizes'
        );
    }

    public function test_http_error_body_under_limit_is_not_truncated(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 500],
            'body' => 'short error',
        ], 'Failed.');

        $excerpt = (string) $result->get_error_data()['body_excerpt'];
        $this->assertSame('short error', $excerpt);
        $this->assertStringEndsNotWith('…', $excerpt);
    }

    public function test_retry_after_header_in_seconds_is_exposed_on_error_data(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 429],
            'body' => 'rate limited',
            'headers' => ['retry-after' => '42'],
        ], 'Rate limited.');

        $this->assertSame(42, $result->get_error_data()['retry_after']);
    }

    public function test_retry_after_header_with_http_date_is_clamped_to_one_hour(): void
    {
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 7200);

        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 503],
            'body' => '',
            'headers' => ['retry-after' => $future],
        ], 'Upstream down.');

        $retryAfter = (int) $result->get_error_data()['retry_after'];
        $this->assertSame(3600, $retryAfter, 'http-date Retry-After must be clamped to one hour');
    }

    public function test_retry_after_header_in_the_past_resolves_to_zero(): void
    {
        $past = gmdate('D, d M Y H:i:s \G\M\T', time() - 60);

        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 503],
            'body' => '',
            'headers' => ['retry-after' => $past],
        ], 'Upstream down.');

        $this->assertSame(0, $result->get_error_data()['retry_after']);
    }

    public function test_retry_after_header_absent_yields_zero(): void
    {
        $result = $this->http->decodeJsonResponse([
            'response' => ['code' => 500],
            'body' => '',
        ], 'Failed.');

        $this->assertSame(0, $result->get_error_data()['retry_after']);
    }
}
