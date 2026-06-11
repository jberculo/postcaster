<?php

declare(strict_types=1);

use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Support\TemplateEditorFieldDecorator;

final class TemplateDescriptionServiceTest extends WP_UnitTestCase
{
    private TemplateDescriptionService $descriptions;
    private TemplateEditorFieldDecorator $decorator;

    public function set_up(): void
    {
        parent::set_up();

        $registry = new NetworkRegistry([new FakeNetworkPublisher('mastodon')]);
        $this->descriptions = new TemplateDescriptionService($registry, 'DEFAULT');
        $this->decorator = new TemplateEditorFieldDecorator();
    }

    public function test_general_template_falls_back_to_plugin_default(): void
    {
        $result = $this->descriptions->describeGeneralTemplate([]);
        $this->assertSame('Inherited from plugin default template', $result['label']);
        $this->assertSame('DEFAULT', $result['template']);
    }

    public function test_general_override_wins_over_plugin_default(): void
    {
        $result = $this->descriptions->describeGeneralTemplate([
            'template_enabled' => '1',
            'template' => 'GENERAL',
        ]);
        $this->assertSame('Own general template', $result['label']);
        $this->assertSame('GENERAL', $result['template']);
    }

    public function test_profile_general_template_wins_over_global(): void
    {
        $result = $this->descriptions->describeGeneralTemplate(
            ['template_enabled' => '1', 'template' => 'GENERAL'],
            ['profile_template_enabled' => '1', 'profile_template' => 'PROFILE']
        );
        $this->assertSame('Own personal general template', $result['label']);
        $this->assertSame('PROFILE', $result['template']);
    }

    public function test_network_template_wins_over_general(): void
    {
        $result = $this->descriptions->describeNetworkTemplate('mastodon', [
            'template_enabled' => '1',
            'template' => 'GENERAL',
            'mastodon_template_enabled' => '1',
            'mastodon_template' => 'NETWORK',
        ]);
        $this->assertSame('Own network template', $result['label']);
        $this->assertSame('NETWORK', $result['template']);
    }

    public function test_personal_network_template_wins_over_all(): void
    {
        $result = $this->descriptions->describeNetworkTemplate(
            'mastodon',
            [
                'template_enabled' => '1',
                'template' => 'GENERAL',
                'mastodon_template_enabled' => '1',
                'mastodon_template' => 'NETWORK',
            ],
            [
                'profile_template_enabled' => '1',
                'profile_template' => 'PROFILE',
                'mastodon_template_enabled' => '1',
                'mastodon_template' => 'PERSONAL_NETWORK',
            ]
        );
        $this->assertSame('Own personal network template', $result['label']);
        $this->assertSame('PERSONAL_NETWORK', $result['template']);
    }

    public function test_network_fallback_skips_personal_network_and_uses_personal_general(): void
    {
        $result = $this->descriptions->describeNetworkFallbackTemplate(
            'mastodon',
            ['template_enabled' => '1', 'template' => 'GENERAL'],
            ['profile_template_enabled' => '1', 'profile_template' => 'PROFILE', 'mastodon_template_enabled' => '1', 'mastodon_template' => 'PERSONAL_NETWORK']
        );
        $this->assertSame('Own personal general template', $result['label']);
        $this->assertSame('PROFILE', $result['template']);
    }

    public function test_collapse_returns_shared_template_when_all_targets_resolve_equally(): void
    {
        $result = $this->descriptions->collapseDescriptions([
            ['label' => 'Own network template', 'template' => 'NETWORK'],
            ['label' => 'Own network template', 'template' => 'NETWORK'],
        ], ['label' => 'fallback', 'template' => 'FALLBACK']);
        $this->assertSame('NETWORK', $result['template']);
    }

    public function test_collapse_returns_fallback_when_targets_differ(): void
    {
        $result = $this->descriptions->collapseDescriptions([
            ['label' => 'Own network template', 'template' => 'NETWORK_A'],
            ['label' => 'Own network template', 'template' => 'NETWORK_B'],
        ], ['label' => 'fallback', 'template' => 'FALLBACK']);
        $this->assertSame('FALLBACK', $result['template']);
    }

    public function test_decorator_enriches_template_editor_fields(): void
    {
        $fields = $this->decorator->decorate(
            [
                ['key' => 'template', 'template_help' => true],
                ['key' => 'description', 'type' => 'textarea'],
            ],
            ['template' => 'CURRENT'],
            ['template' => 'FALLBACK'],
            'mastodon',
            'global',
            0,
            'PREVIEW'
        );

        $this->assertSame('CURRENT', $fields[0]['current_template']['template']);
        $this->assertSame('mastodon', $fields[0]['preview_network_key']);
        $this->assertSame('PREVIEW', $fields[0]['preview_initial_text']);
        $this->assertArrayNotHasKey('current_template', $fields[1]);
    }
}
