<?php

final class AdminGlobalGeneralTabViewTest extends WP_UnitTestCase
{
    private function renderTab(array $overrides = []): string
    {
        require_once dirname(__DIR__, 2) . '/views/helpers.php';

        $justbee_postcaster_global_disabled_warning = false;
        $justbee_postcaster_option_name = 'justbee_postcaster_options';
        $justbee_postcaster_options = $overrides['options'] ?? [
            'template_enabled' => '1',
            'template' => '{title}',
        ];
        $justbee_postcaster_effective_general_template = [
            'template' => '{title}',
            'label' => 'Own general template',
        ];
        $justbee_postcaster_fallback_general_template = [
            'template' => '{title}',
            'label' => 'Inherited from plugin default template',
        ];
        $justbee_postcaster_general_preview_initial_text = $overrides['preview_text'] ?? 'Initial general preview';
        $justbee_postcaster_general_preview_initial_image_url = '';
        $justbee_postcaster_general_preview_initial_image_alt = '';
        $justbee_postcaster_general_preview_initial_items = $overrides['preview_items'] ?? [[
            'label' => '',
            'header' => [
                'network' => '',
                'name' => 'My Site',
                'meta' => 'General preview',
                'avatar_text' => 'M',
            ],
            'text' => $overrides['preview_text'] ?? 'Initial general preview',
            'card' => [
                'domain' => 'example.test',
                'title' => 'Card title',
                'description' => 'Card description',
                'url' => 'https://example.test/article',
                'image_url' => 'https://example.test/card.jpg',
                'image_alt' => 'Card alt',
            ],
        ]];

        ob_start();
        require dirname(__DIR__, 2) . '/views/partials/admin-global-general-tab.php';
        return (string) ob_get_clean();
    }

    public function test_general_tab_renders_supplied_preview_text(): void
    {
        $output = $this->renderTab(['preview_text' => 'Initial general preview']);

        $this->assertNotSame('', trim($output), 'partial must produce output');
        $this->assertStringContainsString('Initial general preview', $output);
    }

    public function test_general_tab_escapes_user_controlled_strings(): void
    {
        $payload = '<script>alert("xss")</script>';
        $output = $this->renderTab([
            'preview_text' => $payload,
            'preview_items' => [[
                'label' => $payload,
                'header' => [
                    'network' => '',
                    'name' => $payload,
                    'meta' => 'General preview',
                    'avatar_text' => 'X',
                ],
                'text' => $payload,
                'card' => [
                    'domain' => 'example.test',
                    'title' => $payload,
                    'description' => $payload,
                    'url' => 'https://example.test/article',
                    'image_url' => 'https://example.test/card.jpg',
                    'image_alt' => $payload,
                ],
            ]],
        ]);

        $this->assertStringNotContainsString(
            $payload,
            $output,
            'user-controlled strings must never reach the partial output unescaped'
        );
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }
}
