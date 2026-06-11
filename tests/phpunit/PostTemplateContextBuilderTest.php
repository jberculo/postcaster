<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PostTemplateContextBuilder;
use Justbee\PostCaster\Services\Networks\BlueskyPublisher;
use Justbee\PostCaster\Services\Networks\LinkedInPublisher;

final class PostTemplateContextBuilderTest extends WP_UnitTestCase
{
    private PostTemplateContextBuilder $builder;
    private FakeNetworkPublisher $fake;

    public function set_up(): void
    {
        parent::set_up();

        $this->fake = new FakeNetworkPublisher('fake');
        $http = new HttpService();
        $media = new MediaService();
        $networks = new NetworkRegistry([
            $this->fake,
            new BlueskyPublisher($http, $media),
            new LinkedInPublisher($http, $media),
        ]);
        $settings = new SettingsModel($networks);
        $profiles = new UserProfileModel($settings, $networks);
        $this->builder = new PostTemplateContextBuilder($settings, $profiles, $networks);
    }

    public function test_post_values_populate_title_url_and_excerpt(): void
    {
        $userId = self::factory()->user->create(['display_name' => 'Alice']);
        $postId = self::factory()->post->create([
            'post_title' => 'Hello &amp; goodbye',
            'post_excerpt' => 'Hand-written excerpt',
            'post_author' => $userId,
        ]);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $this->assertSame('Hello & goodbye', $values['title'], 'Title must be HTML-entity decoded.');
        $this->assertSame('Hand-written excerpt', $values['excerpt']);
        $this->assertSame('Hand-written excerpt', $values['post']);
        $this->assertSame(get_permalink($postId), $values['url']);
        $this->assertSame('Alice', $values['author']);
        $this->assertSame('Alice', $values['displayname']);
    }

    public function test_post_values_fall_back_to_trimmed_content_when_no_excerpt(): void
    {
        $content = str_repeat('word ', 50); // 50 words, trimmed to 30
        $postId = self::factory()->post->create([
            'post_content' => $content,
            'post_excerpt' => '',
        ]);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $this->assertStringEndsWith('...', $values['excerpt'], 'Trimmed excerpt must have an ellipsis.');
    }

    public function test_post_values_strip_shortcodes_from_content_excerpt(): void
    {
        $postId = self::factory()->post->create([
            'post_content' => '[gallery ids="1,2,3"] real content words here',
            'post_excerpt' => '',
        ]);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $this->assertStringNotContainsString('[gallery', $values['excerpt']);
        $this->assertStringContainsString('real content', $values['excerpt']);
    }

    public function test_post_values_primary_category_uses_first_category(): void
    {
        $categoryId = self::factory()->category->create(['name' => 'Science', 'description' => 'All about science.']);
        $postId = self::factory()->post->create();
        wp_set_post_categories($postId, [$categoryId]);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $this->assertSame('Science', $values['category']);
        $this->assertSame('All about science.', $values['cat_desc']);
    }

    public function test_post_values_tags_are_joined_hashtags_deduplicated(): void
    {
        $postId = self::factory()->post->create();
        wp_set_post_tags($postId, ['news', 'tech', 'news']);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $tags = explode(' ', $values['tags']);
        $this->assertContains('#news', $tags);
        $this->assertContains('#tech', $tags);
        $this->assertSame(count($tags), count(array_unique($tags)), 'Tags should be deduplicated.');
    }

    public function test_post_values_tag_with_spaces_is_collapsed(): void
    {
        $postId = self::factory()->post->create();
        wp_set_post_tags($postId, ['new york']);
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'global', []);

        $this->assertSame('#newyork', $values['tags']);
    }

    public function test_bluesky_account_reference_is_prefixed_with_at(): void
    {
        $postId = self::factory()->post->create();
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'bluesky', 'global', [
            'bluesky_identifier' => 'user.bsky.social',
        ]);

        $this->assertSame('@user.bsky.social', $values['account']);
    }

    public function test_bluesky_account_reference_preserves_existing_at(): void
    {
        $postId = self::factory()->post->create();
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'bluesky', 'global', [
            'bluesky_identifier' => '@user.bsky.social',
        ]);

        $this->assertSame('@user.bsky.social', $values['account']);
    }

    public function test_linkedin_author_urn_is_returned_verbatim(): void
    {
        $postId = self::factory()->post->create();
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'linkedin', 'global', [
            'linkedin_author_urn' => 'urn:li:person:abc123',
        ]);

        $this->assertSame('urn:li:person:abc123', $values['account']);
    }

    public function test_site_reference_uses_global_account_even_for_personal_target_options(): void
    {
        update_option(SettingsModel::OPTION_NAME, [
            'fake_account_reference' => '@site-account',
        ]);

        $postId = self::factory()->post->create();
        $post = get_post($postId);

        $values = $this->builder->buildPostValues($post, 'fake', 'user_123', [
            'fake_account_reference' => '@personal-account',
        ]);

        $this->assertSame('@site-account', $values['@site']);
        $this->assertSame('@personal-account', $values['account']);
    }

    public function test_test_values_use_static_placeholders(): void
    {
        $values = $this->builder->buildTestValues('fake', []);

        $this->assertSame('PostCaster test post', $values['title']);
        $this->assertNotSame('', $values['excerpt']);
        $this->assertStringEndsWith('/postcaster-test/', $values['url']);
    }

    public function test_example_values_use_lorem_ipsum(): void
    {
        $values = $this->builder->buildExampleValues('fake', []);

        $this->assertSame('Lorem ipsum dolor sit amet', $values['title']);
        $this->assertStringContainsString('Lorem ipsum', $values['excerpt']);
        $this->assertSame('#Lorem #Ipsum #Dolor', $values['tags']);
    }
}
