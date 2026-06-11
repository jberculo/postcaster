<?php

use Justbee\PostCaster\Views\TemplateEditorRenderer;

final class TemplateEditorRendererTest extends WP_UnitTestCase
{
    public function test_render_table_preview_outputs_initial_preview_text(): void
    {
        $renderer = new TemplateEditorRenderer();

        ob_start();
        $renderer->renderTableRows([
            'row_label' => 'Template',
            'input_name' => 'postcaster',
            'field_key' => 'template',
            'toggle_key' => 'template_enabled',
            'current_value' => '',
            'effective_template' => 'CURRENT',
            'fallback_template' => 'CURRENT',
            'preview_example' => true,
            'preview_network_key' => 'mastodon',
            'preview_initial_text' => 'Initial preview text',
            'preview_initial_image_url' => 'https://example.test/image.jpg',
            'preview_initial_image_alt' => 'Preview image alt',
            'preview_initial_card' => [
                'domain' => 'example.test',
                'title' => 'Card title',
                'description' => 'Card description',
                'url' => 'https://example.test/article',
                'image_url' => 'https://example.test/card.jpg',
                'image_alt' => 'Card image alt',
            ],
            'preview_initial_items' => [
                [
                    'label' => 'Bluesky',
                    'text' => 'Initial preview text',
                    'card' => [
                        'domain' => 'example.test',
                        'title' => 'Card title',
                        'description' => 'Card description',
                        'url' => 'https://example.test/article',
                        'image_url' => 'https://example.test/card.jpg',
                        'image_alt' => 'Card image alt',
                    ],
                ],
            ],
        ]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-postcaster-template-preview="template_enabled"', $output);
        $this->assertStringContainsString('Initial preview text', $output);
        $this->assertStringContainsString('data-postcaster-template-preview-image="template_enabled"', $output);
        $this->assertStringContainsString('https://example.test/image.jpg', $output);
        $this->assertStringContainsString('data-postcaster-template-preview-card-wrap="template_enabled"', $output);
        $this->assertStringContainsString('Card title', $output);
        $this->assertGreaterThan(
            strpos($output, 'Initial preview text'),
            strpos($output, 'Card title')
        );
    }

    public function test_render_uses_fallback_template_when_override_is_enabled_without_current_value(): void
    {
        $renderer = new TemplateEditorRenderer();

        ob_start();
        $renderer->render([
            'toggle_key' => 'template_enabled',
            'field_key' => 'template',
            'field_id' => 'template',
            'current_value' => '',
            'effective_template' => "{title}\n\n{url}",
            'fallback_template' => "{title}\n\n{url}",
            'enabled' => true,
        ]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-postcaster-template-editor="template_enabled"', $output);
        $this->assertStringContainsString("{title}\n\n{url}", $output);
        $this->assertStringNotContainsString('readonly="readonly"', $output);
    }

    public function test_render_table_rows_scopes_editor_script_to_matching_wrappers(): void
    {
        $renderer = new TemplateEditorRenderer();

        ob_start();
        $renderer->renderTableRows([
            'row_label' => 'Template',
            'input_name' => 'postcaster',
            'field_key' => 'template',
            'toggle_key' => 'template_enabled',
            'current_value' => 'Custom template',
            'effective_template' => 'Fallback template',
            'fallback_template' => 'Fallback template',
            'enabled' => true,
            'preview_example' => true,
            'preview_network_key' => 'mastodon',
        ]);
        $output = (string) ob_get_clean();

        // The toggle/preview wiring lives in assets/js/postcaster-template-editor.js,
        // hooked to the rendered markup via data-* attributes. Verify the markup
        // surface that script depends on is present.
        $this->assertStringContainsString('data-postcaster-template-control="template_enabled"', $output);
        $this->assertStringContainsString('data-postcaster-template-editor="template_enabled"', $output);
        $this->assertStringContainsString('data-postcaster-template-preview-items="template_enabled"', $output);
    }

    public function test_render_table_rows_wraps_editor_textarea_in_template_wrapper(): void
    {
        $renderer = new TemplateEditorRenderer();

        ob_start();
        $renderer->renderTableRows([
            'row_label' => 'Template',
            'input_name' => 'postcaster',
            'field_key' => 'template',
            'toggle_key' => 'template_enabled',
            'current_value' => '',
            'effective_template' => 'Fallback template',
            'fallback_template' => 'Fallback template',
            'enabled' => false,
        ]);
        $output = (string) ob_get_clean();

        $wrapperOpen = strpos($output, '<div data-postcaster-template-wrapper="template_enabled">');
        $textareaPos = strpos($output, 'data-postcaster-template-editor="template_enabled"');

        $this->assertNotFalse($wrapperOpen, 'editor section must be wrapped in a template-wrapper div');
        $this->assertNotFalse($textareaPos, 'editor textarea must be present');
        // Check that there is a wrapper opening tag before the textarea — the toggle script
        // bails out without one and the textarea then stays readonly forever.
        $wrappersBeforeTextarea = substr_count(substr($output, 0, $textareaPos), '<div data-postcaster-template-wrapper="template_enabled">');
        $this->assertGreaterThanOrEqual(2, $wrappersBeforeTextarea, 'textarea must sit inside a template-wrapper so the toggle can find it');
    }

    public function test_render_exposes_data_attributes_consumed_by_template_editor_script(): void
    {
        $renderer = new TemplateEditorRenderer();

        ob_start();
        $renderer->render([
            'toggle_key' => 'template_enabled',
            'field_key' => 'template',
            'field_id' => 'template',
            'current_value' => 'Initial value',
            'effective_template' => 'Fallback template',
            'fallback_template' => 'Fallback template',
            'enabled' => true,
        ]);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('data-postcaster-template-editor="template_enabled"', $output);
        $this->assertStringContainsString('data-postcaster-current-template="Initial value"', $output);
        $this->assertStringContainsString('data-postcaster-default-template="Fallback template"', $output);
    }
}
