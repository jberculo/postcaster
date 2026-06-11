<?php

namespace Justbee\PostCaster\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateEditorFieldDecorator
{
    public function decorate(
        array $fields,
        array $currentTemplate,
        array $fallbackTemplate,
        string $previewNetworkKey = '',
        string $previewScope = 'global',
        int $previewUserId = 0,
        string $previewInitialText = '',
        string $previewInitialImageUrl = '',
        string $previewInitialImageAlt = '',
        ?array $previewInitialCard = null,
        array $previewInitialItems = [],
        ?array $testPost = null
    ): array {
        return array_map(
            static function (array $field) use ($currentTemplate, $fallbackTemplate, $previewNetworkKey, $previewScope, $previewUserId, $previewInitialText, $previewInitialImageUrl, $previewInitialImageAlt, $previewInitialCard, $previewInitialItems, $testPost): array {
                if (empty($field['template_help'])) {
                    return $field;
                }

                $field['current_template'] = $currentTemplate;
                $field['fallback_template'] = $fallbackTemplate;
                $field['preview_network_key'] = $previewNetworkKey;
                $field['preview_scope'] = $previewScope;
                $field['preview_user_id'] = $previewUserId;
                $field['preview_initial_text'] = $previewInitialText;
                $field['preview_initial_image_url'] = $previewInitialImageUrl;
                $field['preview_initial_image_alt'] = $previewInitialImageAlt;
                $field['preview_initial_card'] = $previewInitialCard;
                $field['preview_initial_items'] = $previewInitialItems;
                if ($testPost !== null) {
                    $field['test_post'] = $testPost;
                }

                return $field;
            },
            $fields
        );
    }
}
