<?php

namespace Justbee\PostCaster\Views;

if (!defined('ABSPATH')) {
    exit;
}

final class FieldTableRenderer
{
    private TemplateEditorRenderer $templateEditor;

    public function __construct(TemplateEditorRenderer $templateEditor)
    {
        $this->templateEditor = $templateEditor;
    }

    public function renderFieldsTable(array $fields, array $values, string $inputName): void
    {
        ?>
        <table class="form-table" role="presentation">
            <?php foreach ($fields as $field) : ?>
                <?php
                $toggleKey = $field['toggle'] ?? '';
                $isTemplateEditor = $field['type'] === 'textarea' && $toggleKey !== '';
                $isVisible = $toggleKey === '' || (($values[$toggleKey] ?? '0') === '1');
                ?>
                <?php if ($isTemplateEditor) : ?>
                    <?php $this->templateEditor->renderTableRows([
                        'row_label' => (string) ($field['label'] ?? ''),
                        'input_name' => $inputName,
                        'field_key' => (string) $field['key'],
                        'toggle_key' => (string) $toggleKey,
                        'toggle_label' => __('Enable', 'postcaster'),
                        'toggle_aria_label' => (string) ($field['label'] ?? ''),
                        'current_value' => (string) ($values[$field['key']] ?? ''),
                        'effective_template' => (string) ($field['current_template']['template'] ?? ''),
                        'effective_label' => (string) ($field['current_template']['label'] ?? ''),
                        'fallback_template' => (string) (($field['fallback_template']['template'] ?? '') !== '' ? $field['fallback_template']['template'] : ($field['current_template']['template'] ?? '')),
                        'rows' => (int) ($field['rows'] ?? 5),
                        'help' => !empty($field['template_help']),
                        'empty_description' => __('Make this empty and save or update the preview to restore the original template.', 'postcaster'),
                        'enabled' => (($values[$toggleKey] ?? '0') === '1'),
                        'preview_example' => true,
                        'preview_network_key' => (string) ($field['preview_network_key'] ?? ''),
                        'preview_scope' => (string) ($field['preview_scope'] ?? 'global'),
                        'preview_user_id' => (int) ($field['preview_user_id'] ?? 0),
                        'preview_initial_text' => (string) ($field['preview_initial_text'] ?? ''),
                        'preview_initial_image_url' => (string) ($field['preview_initial_image_url'] ?? ''),
                        'preview_initial_image_alt' => (string) ($field['preview_initial_image_alt'] ?? ''),
                        'preview_initial_card' => is_array($field['preview_initial_card'] ?? null) ? $field['preview_initial_card'] : null,
                        'preview_initial_items' => is_array($field['preview_initial_items'] ?? null) ? $field['preview_initial_items'] : [],
                        'test_post' => is_array($field['test_post'] ?? null) ? $field['test_post'] : null,
                    ]); ?>
                    <?php continue; ?>
                <?php endif; ?>
                <tr<?php if ($toggleKey !== '') :
                    ?> data-postcaster-toggle-row="<?php echo esc_attr($toggleKey); ?>"<?php
                   endif; ?><?php if (!$isVisible) : ?> style="display:none;"<?php
                   endif; ?>>
                    <th scope="row"><?php echo esc_html($field['label']); ?></th>
                    <td>
                        <?php if ($field['type'] === 'checkbox') : ?>
                            <?php $checkboxAttributes = self::getInputAttributes($field, 'checkbox'); ?>
                            <input type="hidden" name="<?php echo esc_attr($inputName); ?>[<?php echo esc_attr($field['key']); ?>]" value="0">
                            <label><input type="checkbox" name="<?php echo esc_attr($inputName); ?>[<?php echo esc_attr($field['key']); ?>]" value="1" <?php checked($values[$field['key']] ?? '0', '1'); ?><?php if (!empty($field['controls'])) :
                                ?> data-postcaster-toggle-control="<?php echo esc_attr($field['key']); ?>"<?php
                                endif; ?><?php foreach ($checkboxAttributes as $attributeName => $attributeValue) :
    ?> <?php echo esc_attr($attributeName); ?>="<?php echo esc_attr($attributeValue); ?>"<?php
                                endforeach; ?>> <?php echo esc_html((string) ($field['description'] ?? $field['label'] ?? '')); ?></label>
                        <?php elseif ($field['type'] === 'select') : ?>
                            <?php $selectAttributes = self::getInputAttributes($field, 'select'); ?>
                            <select name="<?php echo esc_attr($inputName); ?>[<?php echo esc_attr($field['key']); ?>]"<?php foreach ($selectAttributes as $attributeName => $attributeValue) :
                                ?> <?php echo esc_attr($attributeName); ?>="<?php echo esc_attr($attributeValue); ?>"<?php
                            endforeach; ?>>
                                <?php foreach (($field['options'] ?? []) as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($values[$field['key']] ?? '', $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!empty($field['description'])) :
                                ?><p class="description"><?php echo esc_html((string) $field['description']); ?></p><?php
                            endif; ?>
                        <?php elseif ($field['type'] === 'textarea') : ?>
                            <?php $textareaAttributes = self::getInputAttributes($field, 'textarea'); ?>
                            <textarea class="large-text" name="<?php echo esc_attr($inputName); ?>[<?php echo esc_attr($field['key']); ?>]" rows="<?php echo esc_attr((string) ($field['rows'] ?? 5)); ?>"<?php if (!empty($field['placeholder'])) :
                                ?> placeholder="<?php echo esc_attr($field['placeholder']); ?>"<?php
                                endif; ?><?php foreach ($textareaAttributes as $attributeName => $attributeValue) :
    ?> <?php echo esc_attr($attributeName); ?>="<?php echo esc_attr($attributeValue); ?>"<?php
                                endforeach; ?>><?php echo esc_textarea((string) ($values[$field['key']] ?? '')); ?></textarea>
                            <?php if (!empty($field['description'])) :
                                ?><p class="description"><?php echo esc_html((string) $field['description']); ?></p><?php
                            endif; ?>
                            <?php if (!empty($field['template_help'])) : ?>
                                <?php $this->templateEditor->renderPlaceholdersHelp(); ?>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php $value = $field['type'] === 'password' ? '' : ($values[$field['key']] ?? ''); ?>
                            <?php
                            $placeholder = (string) ($field['placeholder'] ?? '');
                            if ($field['type'] === 'password') {
                                $hasStoredValue = !empty($values[$field['key']] ?? '');
                                $placeholder = $hasStoredValue
                                    ? (string) ($field['placeholder_filled'] ?? $placeholder)
                                    : (string) ($field['placeholder_empty'] ?? $placeholder);
                            }
                            ?>
                            <?php $inputAttributes = self::getInputAttributes($field); ?>
                            <input type="<?php echo esc_attr($field['type']); ?>" class="<?php echo !empty($field['small']) ? 'small-text' : 'regular-text'; ?>" name="<?php echo esc_attr($inputName); ?>[<?php echo esc_attr($field['key']); ?>]" value="<?php echo esc_attr($value); ?>"<?php if ($placeholder !== '') :
                                ?> placeholder="<?php echo esc_attr($placeholder); ?>"<?php
                                endif; ?><?php foreach ($inputAttributes as $attributeName => $attributeValue) :
    ?> <?php echo esc_attr($attributeName); ?>="<?php echo esc_attr($attributeValue); ?>"<?php
                                endforeach; ?>>
                            <?php if (!empty($field['description'])) :
                                ?><p class="description"><?php echo esc_html((string) $field['description']); ?></p><?php
                            endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    public static function getInputAttributes(array $field, string $controlType = ''): array
    {
        $type = $controlType !== '' ? $controlType : (string) ($field['type'] ?? 'text');
        $attributes = [
            'autocomplete' => in_array($type, ['password', 'text', 'url', 'textarea'], true) ? 'off' : 'off',
            'data-lpignore' => 'true',
            'data-1p-ignore' => 'true',
            'data-bwignore' => 'true',
            'data-form-type' => 'other',
        ];

        foreach (['min', 'max', 'step'] as $attributeName) {
            if (isset($field[$attributeName]) && $field[$attributeName] !== '') {
                $attributes[$attributeName] = (string) $field[$attributeName];
            }
        }

        if (!in_array($type, ['checkbox', 'select'], true)) {
            $attributes['autocapitalize'] = 'off';
            $attributes['autocorrect'] = 'off';
            $attributes['spellcheck'] = 'false';
        }

        return $attributes;
    }
}

