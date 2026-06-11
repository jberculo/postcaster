<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;

final class PublisherServiceTooLongTest extends WP_UnitTestCase
{
    use BuildsPublisherStack;

    public function set_up(): void
    {
        parent::set_up();

        $this->buildPublisherStack([
            // Force the rendered message to exceed the network's limit by setting it very low.
            $this->makeOptionKey('fake', 'character_limit') => '5',
            // Use a simple {url} template so the URL (forbidden from shrinking) alone overflows.
            'template' => '{title} {url}',
        ]);
    }

    private function makeOptionKey(string $network, string $suffix): string
    {
        return $network . '_' . $suffix;
    }

    public function test_too_long_message_persists_error_and_reports_failure(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'A long enough title that cannot be shrunk below the five-character limit',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertTrue($hadFailures, 'A too-long message must be reported as a failure.');
        $this->assertFalse($this->postMeta->hasRemoteId($postId, 'fake', 'global'), 'Nothing should be persisted as published.');
        $this->assertCount(0, $this->fake->publishedCalls, 'The network must NOT be called when the rendered message still exceeds the limit.');

        $errors = $this->postMeta->getErrors($postId);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('5', $errors[0]['message'], 'Error message must mention the enforced limit.');
        $this->assertStringContainsString('fake', $errors[0]['message']);
    }
}
