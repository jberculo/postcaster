<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;

final class PublishDiagnosisTest extends WP_UnitTestCase
{
    use BuildsPublisherStack;

    public function set_up(): void
    {
        parent::set_up();
        $this->buildPublisherStack();
    }

    public function test_happy_path_reports_should_publish(): void
    {
        $post = get_post(self::factory()->post->create(['post_status' => 'publish']));

        $diagnosis = $this->publisher->getPublishDiagnosis($post);

        $this->assertTrue($diagnosis['should_publish']);
        $this->assertEmpty($diagnosis['reasons']);
    }

    public function test_plugin_disabled_blocks_publish(): void
    {
        update_option(SettingsModel::OPTION_NAME, array_merge(
            get_option(SettingsModel::OPTION_NAME),
            ['enabled' => '0']
        ));
        $post = get_post(self::factory()->post->create(['post_status' => 'publish']));

        $diagnosis = $this->publisher->getPublishDiagnosis($post);

        $this->assertFalse($diagnosis['should_publish']);
        $this->assertNotEmpty($diagnosis['reasons']);
        $this->assertStringContainsString('disabled', strtolower($diagnosis['reasons'][0]));
    }

    public function test_non_enabled_post_type_blocks_publish(): void
    {
        register_post_type('custom', ['public' => true]);
        $post = get_post(self::factory()->post->create(['post_status' => 'publish', 'post_type' => 'custom']));

        $diagnosis = $this->publisher->getPublishDiagnosis($post);

        $this->assertFalse($diagnosis['should_publish']);
        $this->assertStringContainsString('custom', $diagnosis['reasons'][0]);

        unregister_post_type('custom');
    }

    public function test_filter_can_block_publish(): void
    {
        $post = get_post(self::factory()->post->create(['post_status' => 'publish']));

        add_filter('justbee_postcaster_should_publish', '__return_false');
        $diagnosis = $this->publisher->getPublishDiagnosis($post);
        remove_filter('justbee_postcaster_should_publish', '__return_false');

        $this->assertFalse($diagnosis['should_publish']);
        $this->assertStringContainsString('filter', strtolower($diagnosis['reasons'][0]));
    }

    public function test_post_level_disable_blocks_publish(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        $diagnosis = $this->publisher->getPublishDiagnosis($post);

        $this->assertFalse($diagnosis['should_publish']);
        $this->assertStringContainsString('not publish', strtolower($diagnosis['reasons'][0]));
    }

    public function test_post_level_disable_can_be_ignored_for_manual_publish(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $post = get_post($postId);
        $this->postMeta->saveDisablePublishOverride($postId, '1', '0');

        $diagnosis = $this->publisher->getPublishDiagnosis($post, ['ignore_post_disable' => true]);

        $this->assertTrue($diagnosis['should_publish']);
        $this->assertEmpty($diagnosis['reasons']);
    }

    public function test_no_enabled_network_blocks_publish(): void
    {
        update_option(SettingsModel::OPTION_NAME, array_merge(
            get_option(SettingsModel::OPTION_NAME),
            [$this->fake->optionKey('enabled') => '0']
        ));
        $post = get_post(self::factory()->post->create(['post_status' => 'publish']));

        $diagnosis = $this->publisher->getPublishDiagnosis($post);

        $this->assertFalse($diagnosis['should_publish']);
        $this->assertStringContainsString('targets', strtolower($diagnosis['reasons'][0]));
    }
}
