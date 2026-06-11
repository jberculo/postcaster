<?php

declare(strict_types=1);

final class PublisherServiceTest extends WP_UnitTestCase
{
    use BuildsPublisherStack;

    public function set_up(): void
    {
        parent::set_up();
        $this->buildPublisherStack();
    }

    public function test_publish_post_calls_network_and_saves_remote_id(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Hello world',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertFalse($hadFailures, 'A happy-path publish should report no failures.');
        $this->assertCount(1, $this->fake->publishedCalls, 'Publish() should fire once per target.');
        $this->assertStringContainsString('Hello world', $this->fake->publishedCalls[0]['text']);
        $this->assertTrue($this->postMeta->hasRemoteId($postId, 'fake', 'global'), 'Remote id must be persisted after success.');
    }

    public function test_publish_post_records_error_when_network_returns_wp_error(): void
    {
        $this->fake->nextResult = new WP_Error('boom', 'Upstream rejected the request');

        $postId = self::factory()->post->create([
            'post_title' => 'Fails',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertTrue($hadFailures, 'WP_Error from the network should be reported as a failure.');
        $this->assertFalse($this->postMeta->hasRemoteId($postId, 'fake', 'global'), 'Remote id must not be saved on failure.');
        $errors = $this->postMeta->getErrors($postId);
        $this->assertNotEmpty($errors, 'Errors must be persisted for failed publishes.');
        $this->assertSame('Upstream rejected the request', $errors[0]['message']);
    }

    public function test_publish_post_skips_when_remote_id_already_exists(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->publisher->publishPost($post);
        $this->fake->publishedCalls = [];

        $this->publisher->publishPost($post);

        $this->assertCount(0, $this->fake->publishedCalls, 'Second publish without allow_repost must be a no-op.');
    }

    public function test_publish_post_skips_when_target_lock_is_already_held(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $lockToken = $this->postMeta->acquirePublishLock($postId, 'fake', 'global');

        $this->assertNotNull($lockToken);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertFalse($hadFailures);
        $this->assertCount(0, $this->fake->publishedCalls);
        $this->assertTrue($this->postMeta->hasPublishLock($postId, 'fake', 'global'));

        $this->postMeta->releasePublishLock($postId, 'fake', 'global', $lockToken);
    }

    public function test_publish_post_reposts_when_allow_repost_is_true(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->publisher->publishPost($post);
        $this->fake->publishedCalls = [];

        $this->publisher->publishPost($post, ['allow_repost' => true]);

        $this->assertCount(1, $this->fake->publishedCalls, 'allow_repost should force a second publish call.');
    }

    public function test_publish_post_skips_when_article_is_disabled_for_automatic_postcaster(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Do not publish me',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertFalse($hadFailures);
        $this->assertCount(0, $this->fake->publishedCalls);
        $this->assertFalse($this->postMeta->hasRemoteId($postId, 'fake', 'global'));
    }

    public function test_publish_post_can_ignore_article_disable_for_manual_publish(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Publish me manually',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        $hadFailures = $this->publisher->publishPost($post, ['ignore_post_disable' => true]);

        $this->assertFalse($hadFailures);
        $this->assertCount(1, $this->fake->publishedCalls);
        $this->assertTrue($this->postMeta->hasRemoteId($postId, 'fake', 'global'));
    }

    public function test_other_articles_use_general_template_for_opted_in_personal_accounts(): void
    {
        $this->buildPublisherStack([
            'personal_networks_enabled' => '1',
            $this->fake->optionKey('enabled') => '0',
            'template_enabled' => '1',
            'template' => 'GENERAL {title}',
        ]);

        $userId = self::factory()->user->create();
        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_publish_other_posts', '1');
        update_user_meta($userId, '_justbee_postcaster_user_profile_template_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_profile_template', 'PERSONAL {title}');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $postId = self::factory()->post->create([
            'post_title' => 'Shared article',
            'post_status' => 'publish',
            'post_author' => self::factory()->user->create(),
        ]);
        $post = get_post($postId);

        $this->publisher->publishPost($post);

        $this->assertCount(1, $this->fake->publishedCalls);
        $this->assertSame('GENERAL Shared article', $this->fake->publishedCalls[0]['text']);
        $this->assertTrue($this->postMeta->hasRemoteId($postId, 'fake', 'user_' . $userId));
    }

    public function test_publish_other_posts_opt_in_does_not_duplicate_users_own_articles(): void
    {
        $this->buildPublisherStack([
            'personal_networks_enabled' => '1',
            $this->fake->optionKey('enabled') => '0',
            'template_enabled' => '1',
            'template' => 'GENERAL {title}',
        ]);

        $userId = self::factory()->user->create();
        update_user_meta($userId, '_justbee_postcaster_user_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_publish_other_posts', '1');
        update_user_meta($userId, '_justbee_postcaster_user_profile_template_enabled', '1');
        update_user_meta($userId, '_justbee_postcaster_user_profile_template', 'PERSONAL {title}');
        update_user_meta($userId, '_justbee_postcaster_user_' . $this->fake->optionKey('enabled'), '1');

        $postId = self::factory()->post->create([
            'post_title' => 'Own article',
            'post_status' => 'publish',
            'post_author' => $userId,
        ]);
        $post = get_post($postId);

        $this->publisher->publishPost($post);

        $this->assertCount(1, $this->fake->publishedCalls, 'Own articles should still resolve to a single personal target.');
        $this->assertSame('PERSONAL Own article', $this->fake->publishedCalls[0]['text']);
        $this->assertTrue($this->postMeta->hasRemoteId($postId, 'fake', 'user_' . $userId));
    }

    public function test_publish_post_skips_when_network_specific_text_prep_pushes_message_over_limit(): void
    {
        $this->buildPublisherStack([
            'template_enabled' => '1',
            'template' => '12345',
            $this->fake->optionKey('character_limit') => '5',
        ]);
        $this->fake->preparePostTextCallback = static function (WP_Post $post, array $options, string $text): string {
            return $text . 'X';
        };

        $postId = self::factory()->post->create([
            'post_title' => 'Ignored title',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertTrue($hadFailures);
        $this->assertCount(0, $this->fake->publishedCalls);
        $errors = $this->postMeta->getErrors($postId);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('6 characters long while 5 is the maximum', $errors[0]['message']);
    }

    public function test_publish_post_succeeds_when_network_specific_text_prep_shortens_message_to_fit(): void
    {
        $this->buildPublisherStack([
            'template_enabled' => '1',
            'template' => '123456',
            $this->fake->optionKey('character_limit') => '5',
        ]);
        $this->fake->preparePostTextCallback = static function (WP_Post $post, array $options, string $text): string {
            return substr($text, 0, 5);
        };

        $postId = self::factory()->post->create([
            'post_title' => 'Ignored title',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertFalse($hadFailures);
        $this->assertCount(1, $this->fake->publishedCalls);
        $this->assertSame('12...', $this->fake->publishedCalls[0]['text']);
        $this->assertTrue($this->postMeta->hasRemoteId($postId, 'fake', 'global'));
    }

    public function test_build_test_status_text_applies_network_specific_text_prep(): void
    {
        $this->buildPublisherStack([
            'template_enabled' => '1',
            'template' => '{title}',
        ]);
        $this->fake->preparePostTextCallback = static function (WP_Post $post, array $options, string $text): string {
            return $text . ' ::prepared';
        };

        self::factory()->post->create([
            'post_title' => 'Latest article',
            'post_status' => 'publish',
        ]);

        $text = $this->publisher->buildTestStatusText('fake', $this->publisher->getGlobalOptions());

        $this->assertSame('Test: Latest article ::prepared', $text);
    }

    public function test_build_example_status_text_falls_back_when_network_text_prep_returns_empty(): void
    {
        $this->buildPublisherStack([
            'template_enabled' => '1',
            'template' => '{title}',
        ]);
        $this->fake->preparePostTextCallback = static function (WP_Post $post, array $options, string $text): string {
            return '';
        };

        self::factory()->post->create([
            'post_title' => 'Latest article',
            'post_status' => 'publish',
        ]);

        $text = $this->publisher->buildExampleStatusText('fake', '{title}');

        $this->assertSame('Latest article', $text);
    }

    /**
     * @dataProvider retryClassificationProvider
     */
    public function test_last_publish_outcome_classifies_failures_correctly(
        WP_Error $networkError,
        bool $expectedRetryable,
        int $expectedRetryAfter
    ): void {
        $this->fake->nextResult = $networkError;

        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);

        $this->publisher->publishPost($post);
        $outcome = $this->publisher->getLastPublishOutcome();

        $this->assertSame($expectedRetryable, $outcome['retryable']);
        $this->assertSame($expectedRetryAfter, $outcome['retry_after']);
    }

    /** @return array<string, array{0: WP_Error, 1: bool, 2: int}> */
    public function retryClassificationProvider(): array
    {
        $http = static fn(int $status, int $retryAfter = 0): WP_Error => new WP_Error(
            'justbee_postcaster_http',
            sprintf('HTTP %d', $status),
            ['status' => $status, 'retry_after' => $retryAfter]
        );

        return [
            'permanent 401' => [$http(401), false, 0],
            'permanent 403' => [$http(403), false, 0],
            'permanent 422' => [$http(422), false, 0],
            'transient 408 timeout' => [$http(408), true, 0],
            'transient 429 with retry-after' => [$http(429, 90), true, 90],
            'transient 500' => [$http(500), true, 0],
            'transient 503' => [$http(503), true, 0],
            'transport-level wp_remote failure' => [
                new WP_Error('http_request_failed', 'Connection refused'),
                true,
                0,
            ],
            'invalid JSON marked non-retryable' => [
                new WP_Error('justbee_postcaster_json', 'Bad body', ['retryable' => false]),
                false,
                0,
            ],
            // Real publishers emit data-less WP_Errors for config issues.
            // The classifier must treat unknown error codes without a
            // status as permanent — otherwise a missing access_token would
            // retry forever.
            'publisher config error without data' => [
                new WP_Error('justbee_postcaster_mastodon_config', 'Mastodon credentials are missing.'),
                false,
                0,
            ],
        ];
    }

    public function test_publish_post_falls_back_when_network_text_prep_returns_empty(): void
    {
        $this->buildPublisherStack([
            'template_enabled' => '1',
            'template' => '{title}',
        ]);
        $this->fake->preparePostTextCallback = static function (WP_Post $post, array $options, string $text): string {
            return '';
        };

        $postId = self::factory()->post->create([
            'post_title' => 'Fallback publish title',
            'post_status' => 'publish',
        ]);
        $post = get_post($postId);

        $hadFailures = $this->publisher->publishPost($post);

        $this->assertFalse($hadFailures);
        $this->assertCount(1, $this->fake->publishedCalls);
        $this->assertSame('Fallback publish title', $this->fake->publishedCalls[0]['text']);
    }
}
