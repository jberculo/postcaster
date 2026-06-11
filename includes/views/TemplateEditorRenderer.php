<?php

namespace Justbee\PostCaster\Views;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateEditorRenderer
{
    private bool $scriptRendered = false;
    private bool $dialogScriptRendered = false;
    private ?PreviewItemRenderer $previewItems = null;

    public function renderTableRows(array $args): void
    {
        ?>
        <tr data-postcaster-template-row="1">
            <th scope="row"><?php echo esc_html__('Template', 'postcaster'); ?></th>
            <td>
                <?php $this->render(array_merge($args, [
                    'layout' => 'table_rows',
                    'table_section' => 'toggle',
                    'toggle_label' => __('Override default template', 'postcaster'),
                ])); ?>
            </td>
        </tr>
        <tr data-postcaster-template-row="1">
            <th scope="row"><?php echo esc_html__('Template preview', 'postcaster'); ?></th>
            <td>
                <?php $this->render(array_merge($args, [
                    'layout' => 'table_rows',
                    'table_section' => 'preview',
                ])); ?>
            </td>
        </tr>
        <?php
    }

    public function render(array $args): void
    {
        $toggleKey = (string) ($args['toggle_key'] ?? '');
        $fieldKey = (string) ($args['field_key'] ?? '');
        $inputName = (string) ($args['input_name'] ?? '');
        $toggleName = (string) ($args['toggle_name'] ?? '');
        $fieldName = (string) ($args['field_name'] ?? '');
        $fieldId = (string) ($args['field_id'] ?? '');
        $toggleLabel = (string) ($args['toggle_label'] ?? __('Customize template', 'postcaster'));
        $toggleAriaLabel = (string) ($args['toggle_aria_label'] ?? ($toggleLabel !== '' ? $toggleLabel : __('Customize template', 'postcaster')));
        $currentValue = (string) ($args['current_value'] ?? '');
        $effectiveTemplate = (string) ($args['effective_template'] ?? '');
        $effectiveLabel = (string) ($args['effective_label'] ?? '');
        $fallbackTemplate = (string) ($args['fallback_template'] ?? $effectiveTemplate);
        $rows = (int) ($args['rows'] ?? 6);
        $enabled = !empty($args['enabled']);
        $emptyDescription = (string) ($args['empty_description'] ?? __('Make this empty and save or update the preview to restore the original template.', 'postcaster'));
        $previewButton = is_array($args['preview_button'] ?? null) ? $args['preview_button'] : null;
        $previewPostId = isset($args['preview_post_id']) ? (int) $args['preview_post_id'] : 0;
        $previewContext = sanitize_key((string) ($args['preview_context'] ?? 'global'));
        $previewExample = !empty($args['preview_example']);
        $previewNetworkKey = sanitize_key((string) ($args['preview_network_key'] ?? ''));
        $previewScope = sanitize_key((string) ($args['preview_scope'] ?? 'global'));
        $previewUserId = isset($args['preview_user_id']) ? (int) ($args['preview_user_id']) : 0;
        $previewInitialText = (string) ($args['preview_initial_text'] ?? '');
        $previewInitialImageUrl = (string) ($args['preview_initial_image_url'] ?? '');
        $previewInitialImageAlt = (string) ($args['preview_initial_image_alt'] ?? '');
        $previewInitialCard = is_array($args['preview_initial_card'] ?? null) ? $args['preview_initial_card'] : null;
        $previewInitialItems = is_array($args['preview_initial_items'] ?? null) ? $args['preview_initial_items'] : [];
        if ($previewInitialItems === [] && ($previewInitialText !== '' || $previewInitialImageUrl !== '' || $previewInitialCard !== null)) {
            $previewInitialItems = [[
                'label' => '',
                'text' => $previewInitialText,
                'image' => $previewInitialImageUrl !== '' ? [
                    'url' => $previewInitialImageUrl,
                    'alt' => $previewInitialImageAlt,
                ] : null,
                'card' => $previewInitialCard,
            ]];
        } elseif ($previewInitialItems !== []) {
            if ($previewInitialImageUrl !== '' && empty($previewInitialItems[0]['image'])) {
                $previewInitialItems[0]['image'] = [
                    'url' => $previewInitialImageUrl,
                    'alt' => $previewInitialImageAlt,
                ];
            }
            if ($previewInitialCard !== null && empty($previewInitialItems[0]['card'])) {
                $previewInitialItems[0]['card'] = $previewInitialCard;
            }
        }
        $previewSaveNote = (string) ($args['preview_save_note'] ?? __('Preview updated. Save the settings to use these changes for test posts and publishing.', 'postcaster'));
        $layout = (string) ($args['layout'] ?? 'stacked');
        $tableSection = (string) ($args['table_section'] ?? '');
        $normalizeEmptyToDefault = !array_key_exists('normalize_empty_to_default', $args) || !empty($args['normalize_empty_to_default']);
        $toggleInputName = $toggleName !== ''
            ? $toggleName
            : ($inputName !== '' ? $inputName . '[' . $toggleKey . ']' : $toggleKey);
        $templateInputName = $fieldName !== ''
            ? $fieldName
            : ($inputName !== '' ? $inputName . '[' . $fieldKey . ']' : $fieldKey);
        $templateInputId = $fieldId !== '' ? $fieldId : sanitize_html_class(str_replace('_', '-', $fieldKey));
        $textareaAttributes = FieldTableRenderer::getInputAttributes(['key' => $fieldKey], 'textarea');
        $displayValue = $enabled
            ? ($currentValue !== '' ? $currentValue : $fallbackTemplate)
            : $fallbackTemplate;
        if ($previewButton === null && $previewPostId > 0) {
            $previewButton = [
                'label' => __('Update preview', 'postcaster'),
                'attributes' => [
                    'data-postcaster-preview-type' => 'post',
                    'data-postcaster-preview-button' => '1',
                    'data-postcaster-post-id' => (string) $previewPostId,
                    'data-postcaster-preview-url' => esc_url(admin_url('admin-ajax.php')),
                    'data-postcaster-preview-nonce' => wp_create_nonce('justbee_postcaster_preview_post_template_' . $previewPostId),
                    'data-postcaster-preview-context' => $previewContext,
                    'data-postcaster-preview-loading' => __('Updating preview...', 'postcaster'),
                    'data-postcaster-preview-error' => __('Could not update the preview.', 'postcaster'),
                    'data-postcaster-preview-requires-edit' => '1',
                ],
            ];
        }
        if ($previewButton === null && $previewExample) {
            $previewButton = [
                'label' => __('Update preview', 'postcaster'),
                'attributes' => [
                    'data-postcaster-preview-type' => 'example',
                    'data-postcaster-preview-button' => '1',
                    'data-postcaster-preview-url' => esc_url(admin_url('admin-ajax.php')),
                    'data-postcaster-preview-nonce' => wp_create_nonce('justbee_postcaster_preview_template_example'),
                    'data-postcaster-preview-network-key' => $previewNetworkKey,
                    'data-postcaster-preview-scope' => $previewScope,
                    'data-postcaster-preview-user-id' => (string) $previewUserId,
                    'data-postcaster-preview-loading' => __('Updating preview...', 'postcaster'),
                    'data-postcaster-preview-error' => __('Could not update the preview.', 'postcaster'),
                    'data-postcaster-preview-requires-edit' => '1',
                ],
            ];
        }

        if ($layout === 'table_rows') {
            if ($tableSection === 'preview') {
                $testPost = is_array($args['test_post'] ?? null) ? $args['test_post'] : null;
                $dialogId = 'postcaster-template-dialog-' . sanitize_html_class(str_replace('_', '-', $toggleKey));
                $editorSelector = '[data-postcaster-template-editor="' . esc_attr($toggleKey) . '"]';
                $previewSelector = '[data-postcaster-template-preview-items="' . esc_attr($toggleKey) . '"]';
                $saveNoteSelector = '[data-postcaster-preview-save-note="' . esc_attr($toggleKey) . '"]';
                ?>
                <div data-postcaster-template-wrapper="<?php echo esc_attr($toggleKey); ?>">
                    <div data-postcaster-template-preview-items="<?php echo esc_attr($toggleKey); ?>">
                        <?php $this->renderPreviewItems($toggleKey, $previewInitialItems); ?>
                    </div>
                    <p>
                        <button
                            type="button"
                            class="button button-secondary"
                            data-postcaster-template-customize-button="<?php echo esc_attr($toggleKey); ?>"
                            data-postcaster-modal-open="<?php echo esc_attr($dialogId); ?>"
                            data-postcaster-modal-snapshot-textarea="<?php echo esc_attr($editorSelector); ?>"
                            data-postcaster-modal-snapshot-preview="<?php echo esc_attr($previewSelector); ?>"
                            data-postcaster-modal-hide-on-open="<?php echo esc_attr($saveNoteSelector); ?>"
                            <?php if (!$enabled) : ?>hidden style="display:none;"<?php endif; ?>
                        ><?php echo esc_html__('Customize template', 'postcaster'); ?></button>
                        <?php if ($testPost !== null && !empty($testPost['label'])) : ?>
                            <?php $this->renderTestPostButton($testPost); ?>
                        <?php endif; ?>
                    </p>
                    <dialog
                        id="<?php echo esc_attr($dialogId); ?>"
                        data-postcaster-template-dialog="<?php echo esc_attr($toggleKey); ?>"
                        class="postcaster-modal postcaster-modal--wide"
                    >
                        <h2 style="margin:0 0 14px;font-size:16px;"><?php echo esc_html__('Customize template', 'postcaster'); ?></h2>
                        <div style="margin-bottom:14px;">
                            <strong style="display:block;margin-bottom:6px;font-size:12px;text-transform:uppercase;color:#475569;letter-spacing:0.4px;"><?php echo esc_html__('Preview', 'postcaster'); ?></strong>
                            <div data-postcaster-template-preview-items="<?php echo esc_attr($toggleKey); ?>">
                                <?php $this->renderPreviewItems($toggleKey, $previewInitialItems); ?>
                            </div>
                        </div>
                        <?php $this->renderEditorMarkup(
                            $toggleKey,
                            $templateInputId,
                            $templateInputName,
                            $rows,
                            $currentValue,
                            $fallbackTemplate,
                            $normalizeEmptyToDefault,
                            $enabled,
                            $textareaAttributes,
                            $displayValue,
                            $previewButton,
                            $previewSaveNote,
                            $emptyDescription,
                            !empty($args['help'])
                        ); ?>
                        <p class="postcaster-modal__actions">
                            <button type="button" class="button" data-postcaster-modal-close="<?php echo esc_attr($dialogId); ?>" data-postcaster-modal-restore="<?php echo esc_attr($editorSelector); ?>" data-postcaster-modal-restore-preview="<?php echo esc_attr($previewSelector); ?>"><?php echo esc_html__('Cancel', 'postcaster'); ?></button>
                            <button type="button" class="button button-primary" data-postcaster-template-modal-save="<?php echo esc_attr($toggleKey); ?>"><?php echo esc_html__('Save changes', 'postcaster'); ?></button>
                        </p>
                    </dialog>
                </div>
                <?php
                $this->renderScript();
                $this->renderDialogScript();
                return;
            }

            if ($tableSection === 'toggle') {
                ?>
                <div data-postcaster-template-wrapper="<?php echo esc_attr($toggleKey); ?>">
                    <input type="hidden" name="<?php echo esc_attr($toggleInputName); ?>" value="0">
                    <label>
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr($toggleInputName); ?>"
                            value="1"
                            <?php checked($enabled); ?>
                            data-postcaster-template-control="<?php echo esc_attr($toggleKey); ?>"
                            aria-label="<?php echo esc_attr($toggleAriaLabel); ?>"
                        >
                        <?php echo esc_html($toggleLabel !== '' ? $toggleLabel : __('Enable', 'postcaster')); ?>
                    </label>
                </div>
                <?php
                $this->renderScript();
                return;
            }

            if ($tableSection === 'effective') {
                ?>
                <div data-postcaster-template-wrapper="<?php echo esc_attr($toggleKey); ?>">
                    <?php if ($effectiveLabel !== '') : ?>
                        <p class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: template source label. */
                                __('Current template source: %s', 'postcaster'),
                                $effectiveLabel
                            ));
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
                <?php
                return;
            }

        }

        ?>
        <div data-postcaster-template-wrapper="<?php echo esc_attr($toggleKey); ?>">
            <?php if ($previewExample || $previewButton !== null || $previewInitialText !== '' || $previewInitialItems !== []) : ?>
                <details class="postcaster-template-preview">
                    <summary><strong><?php echo esc_html__('Preview', 'postcaster'); ?></strong></summary>
                    <div data-postcaster-template-preview-items="<?php echo esc_attr($toggleKey); ?>">
                        <?php $this->renderPreviewItems($toggleKey, $previewInitialItems); ?>
                    </div>
                </details>
            <?php endif; ?>
            <p>
                <input type="hidden" name="<?php echo esc_attr($toggleInputName); ?>" value="0">
                <label>
                    <input
                        type="checkbox"
                        name="<?php echo esc_attr($toggleInputName); ?>"
                        value="1"
                        <?php checked($enabled); ?>
                        data-postcaster-template-control="<?php echo esc_attr($toggleKey); ?>"
                        aria-label="<?php echo esc_attr($toggleAriaLabel); ?>"
                    >
                    <?php if ($toggleLabel !== '') : ?>
                        <?php echo esc_html($toggleLabel); ?>
                    <?php endif; ?>
                </label>
            </p>
            <?php
            $this->renderEditorMarkup(
                $toggleKey,
                $templateInputId,
                $templateInputName,
                $rows,
                $currentValue,
                $fallbackTemplate,
                $normalizeEmptyToDefault,
                $enabled,
                $textareaAttributes,
                $displayValue,
                $previewButton,
                $previewSaveNote,
                $emptyDescription,
                !empty($args['help'])
            );
            ?>
        </div>
        <?php
        $this->renderScript();
    }

    private function renderDialogScript(): void
    {
        // Open/close/cancel-with-restore is handled by the shared
        // PostCasterModal helper (assets/js/postcaster-modal.js) via the
        // data-postcaster-modal-open / -close / -restore attributes on the
        // buttons rendered around the editor dialog.
        $this->dialogScriptRendered = true;
    }

    private function renderTestPostButton(array $testPost): void
    {
        $label = (string) ($testPost['label'] ?? '');
        if ($label === '') {
            return;
        }

        $description = (string) ($testPost['description'] ?? '');
        $type = (string) ($testPost['type'] ?? 'submit');
        $formId = (string) ($testPost['form_id'] ?? '');
        $attributes = is_array($testPost['attributes'] ?? null) ? $testPost['attributes'] : [];
        ?>
        <button
            type="<?php echo esc_attr($type === 'button' ? 'button' : 'submit'); ?>"
            class="button button-secondary"
            <?php if ($formId !== '' && $type !== 'button') : ?>form="<?php echo esc_attr($formId); ?>"<?php endif; ?>
            <?php foreach ($attributes as $name => $value) : ?>
                <?php echo esc_attr((string) $name); ?>="<?php echo esc_attr((string) $value); ?>"
            <?php endforeach; ?>
        >
            <?php echo esc_html($label); ?>
        </button>
        <?php if ($description !== '') : ?>
            <span class="description" style="margin-left:8px;"><?php echo esc_html($description); ?></span>
        <?php endif; ?>
        <?php
    }

    public function renderPlaceholdersHelp(): void
    {
        ?>
        <details class="postcaster-template-help">
            <summary><?php echo esc_html__('Supported template placeholders', 'postcaster'); ?></summary>
            <ul>
                <li><code>{title}</code>: <?php echo esc_html__('the title of your blog post', 'postcaster'); ?></li>
                <li><code>{site}</code>: <?php echo esc_html__('the title of your site', 'postcaster'); ?></li>
                <li><code>{post}</code>: <?php echo esc_html__('a short excerpt of the post content', 'postcaster'); ?></li>
                <li><code>{excerpt}</code>: <?php echo esc_html__('alias of {post} for backward compatibility', 'postcaster'); ?></li>
                <li><code>{category}</code>: <?php echo esc_html__('the first selected category for the post', 'postcaster'); ?></li>
                <li><code>{cat_desc}</code>: <?php echo esc_html__('the category description', 'postcaster'); ?></li>
                <li><code>{date}</code>: <?php echo esc_html__('the post date', 'postcaster'); ?></li>
                <li><code>{modified}</code>: <?php echo esc_html__('the post modified date', 'postcaster'); ?></li>
                <li><code>{url}</code>: <?php echo esc_html__('the post URL', 'postcaster'); ?></li>
                <li><code>{author}</code>: <?php echo esc_html__('the post author display name', 'postcaster'); ?></li>
                <li><code>{@site}</code>: <?php echo esc_html__('the configured site account reference for the current network, or {site} when unavailable', 'postcaster'); ?></li>
                <li><code>{@author}</code>: <?php echo esc_html__('the article author network reference, or {author} when unavailable', 'postcaster'); ?></li>
                <li><code>{tags}</code>: <?php echo esc_html__('the post tags converted to hashtags', 'postcaster'); ?></li>
            </ul>
        </details>
        <?php
    }

    public function renderScript(): void
    {
        // The wiring lives in assets/js/postcaster-template-editor.js, which
        // is enqueued globally by Plugin::enqueueAdminAssets() and reads the
        // markup via data-* attributes.
        $this->scriptRendered = true;
    }

    private function renderEditorMarkup(
        string $toggleKey,
        string $templateInputId,
        string $templateInputName,
        int $rows,
        string $currentValue,
        string $fallbackTemplate,
        bool $normalizeEmptyToDefault,
        bool $enabled,
        array $textareaAttributes,
        string $displayValue,
        ?array $previewButton,
        string $previewSaveNote,
        string $emptyDescription,
        bool $showHelp
    ): void {
        ?>
        <p>
            <textarea
                class="large-text"
                <?php if ($templateInputId !== '') : ?>id="<?php echo esc_attr($templateInputId); ?>"<?php endif; ?>
                name="<?php echo esc_attr($templateInputName); ?>"
                rows="<?php echo esc_attr((string) $rows); ?>"
                data-postcaster-template-editor="<?php echo esc_attr($toggleKey); ?>"
                data-postcaster-current-template="<?php echo esc_attr($currentValue); ?>"
                data-postcaster-inherited-template="<?php echo esc_attr($fallbackTemplate); ?>"
                data-postcaster-default-template="<?php echo esc_attr($fallbackTemplate); ?>"
                data-postcaster-normalize-empty="<?php echo $normalizeEmptyToDefault ? '1' : '0'; ?>"
                <?php echo $enabled ? '' : ' readonly="readonly"'; ?>
                <?php foreach ($textareaAttributes as $attributeName => $attributeValue) : ?>
                    <?php echo esc_attr($attributeName); ?>="<?php echo esc_attr($attributeValue); ?>"
                <?php endforeach; ?>
            ><?php echo esc_textarea($displayValue); ?></textarea>
        </p>
        <?php if ($previewButton !== null) : ?>
            <p>
                <button
                    type="button"
                    class="button button-secondary"
                    <?php foreach (($previewButton['attributes'] ?? []) as $attributeName => $attributeValue) : ?>
                        <?php echo esc_attr((string) $attributeName); ?>="<?php echo esc_attr((string) $attributeValue); ?>"
                    <?php endforeach; ?>
                    <?php if (empty($enabled)) : ?>hidden style="display:none;"<?php endif; ?>
                >
                    <?php echo esc_html((string) ($previewButton['label'] ?? '')); ?>
                </button>
            </p>
            <div class="notice notice-warning inline" data-postcaster-preview-save-note="<?php echo esc_attr($toggleKey); ?>" hidden style="display:none;">
                <p><?php echo esc_html($previewSaveNote); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($emptyDescription !== '') : ?>
            <p class="description"><?php echo esc_html($emptyDescription); ?></p>
        <?php endif; ?>
        <?php if ($showHelp) : ?>
            <?php $this->renderPlaceholdersHelp(); ?>
        <?php endif; ?>
        <?php
    }

    private function renderPreviewItems(string $toggleKey, array $items): void
    {
        $this->previewItemRenderer()->renderItems($toggleKey, $items);
    }

    private function previewItemRenderer(): PreviewItemRenderer
    {
        if ($this->previewItems === null) {
            $this->previewItems = new PreviewItemRenderer();
        }

        return $this->previewItems;
    }
}

