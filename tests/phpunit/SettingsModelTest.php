<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Services\NetworkRegistry;

final class SettingsModelTest extends WP_UnitTestCase
{
    private SettingsModel $settings;

    public function set_up(): void
    {
        parent::set_up();
        $networks = new NetworkRegistry([new FakeNetworkPublisher('fake')]);
        $this->settings = new SettingsModel($networks);
    }

    public function test_boolean_flags_are_normalized_to_zero_or_one(): void
    {
        $sanitized = $this->settings->sanitize([
            'enabled' => 'on',
            'personal_networks_enabled' => '',
            'debug' => '1',
            'personal_network_available_fake' => '',
        ]);

        $this->assertSame('1', $sanitized['enabled']);
        $this->assertSame('0', $sanitized['personal_networks_enabled']);
        $this->assertSame('1', $sanitized['debug']);
        $this->assertSame('0', $sanitized['personal_network_available_fake']);
    }

    public function test_post_types_defaults_to_post_when_empty(): void
    {
        $sanitized = $this->settings->sanitize([]);
        $this->assertSame(['post'], $sanitized['post_types']);
    }

    public function test_post_types_filter_to_selectable_types_only(): void
    {
        $sanitized = $this->settings->sanitize([
            'post_types' => ['post', 'page', 'totally-fake-type'],
        ]);

        $this->assertContains('post', $sanitized['post_types']);
        $this->assertNotContains('totally-fake-type', $sanitized['post_types']);
    }

    public function test_template_override_is_preserved_when_equal_to_default(): void
    {
        $sanitized = $this->settings->sanitize([
            'template_enabled' => '1',
            'template' => $this->settings->getDefaultTemplate(),
        ]);

        $this->assertSame('1', $sanitized['template_enabled'], 'Explicit override flag must be respected even when value matches default.');
        $this->assertSame($this->settings->getDefaultTemplate(), $sanitized['template']);
    }

    public function test_template_enabled_but_empty_falls_back_to_default(): void
    {
        $sanitized = $this->settings->sanitize([
            'template_enabled' => '1',
            'template' => '',
        ]);

        $this->assertSame($this->settings->getDefaultTemplate(), $sanitized['template']);
    }

    public function test_custom_template_survives_when_different_from_default(): void
    {
        $sanitized = $this->settings->sanitize([
            'template_enabled' => '1',
            'template' => 'Custom {title} {url}',
        ]);

        $this->assertSame('1', $sanitized['template_enabled']);
        $this->assertSame('Custom {title} {url}', $sanitized['template']);
    }

    public function test_template_crlf_is_normalized_to_lf(): void
    {
        $sanitized = $this->settings->sanitize([
            'template_enabled' => '1',
            'template' => "line one\r\nline two\rline three",
        ]);

        $this->assertSame("line one\nline two\nline three", $sanitized['template']);
    }

    /**
     * @dataProvider effectiveTemplateProvider
     */
    public function test_effective_template_resolution(?string $networkKey, array $options, string $expected): void
    {
        if ($expected === '__default__') {
            $expected = $this->settings->getDefaultTemplate();
        }

        $resolved = $networkKey === null
            ? $this->settings->getEffectiveGeneralTemplate($options)
            : $this->settings->getEffectiveNetworkTemplate($networkKey, $options);

        $this->assertSame($expected, $resolved);
    }

    /** @return array<string, array{0:?string,1:array<string,string>,2:string}> */
    public function effectiveTemplateProvider(): array
    {
        return [
            'general falls back to default when override disabled' => [
                null,
                ['template_enabled' => '0', 'template' => 'IGNORED'],
                '__default__',
            ],
            'general uses override when enabled' => [
                null,
                ['template_enabled' => '1', 'template' => 'Custom'],
                'Custom',
            ],
            'network template wins over general when both enabled' => [
                'fake',
                [
                    'template_enabled' => '1',
                    'template' => 'GENERAL',
                    'fake_template_enabled' => '1',
                    'fake_template' => 'NETWORK',
                ],
                'NETWORK',
            ],
            'network template falls back to general when disabled' => [
                'fake',
                [
                    'template_enabled' => '1',
                    'template' => 'GENERAL',
                    'fake_template_enabled' => '0',
                    'fake_template' => 'IGNORED',
                ],
                'GENERAL',
            ],
        ];
    }

    public function test_personal_network_availability_defaults_to_enabled(): void
    {
        $this->assertTrue($this->settings->isPersonalNetworkAvailable('fake', []));
    }
}
