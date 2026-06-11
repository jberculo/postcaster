<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-tab-general" class="postcaster-tab-panel is-active" data-postcaster-panel="general">
    <?php if (!empty($justbee_postcaster_global_disabled_warning)) : ?>
        <?php justbee_postcaster_render_warning_notice(__('PostCaster is disabled in the general settings.', 'postcaster')); ?>
    <?php endif; ?>
    <div class="notice notice-info inline">
        <p><?php echo esc_html__('These defaults apply to the global social accounts on the network tabs below unless a network-specific template overrides them.', 'postcaster'); ?></p>
    </div>

    <table class="form-table" role="presentation">
        <?php justbee_postcaster_render_template_editor_table_rows([
            'row_label' => __('Template', 'postcaster'),
            'input_name' => $justbee_postcaster_option_name,
            'field_key' => 'template',
            'toggle_key' => 'template_enabled',
            'toggle_label' => __('Enable', 'postcaster'),
            'toggle_aria_label' => __('Use a custom template', 'postcaster'),
            'current_value' => (string) ($justbee_postcaster_options['template'] ?? ''),
            'effective_template' => (string) ($justbee_postcaster_effective_general_template['template'] ?? ''),
            'effective_label' => (string) ($justbee_postcaster_effective_general_template['label'] ?? ''),
            'fallback_template' => (string) ($justbee_postcaster_fallback_general_template['template'] ?? ''),
            'rows' => 6,
            'help' => true,
            'empty_description' => __('Make this empty and save or update the preview to restore the original template.', 'postcaster'),
            'enabled' => (($justbee_postcaster_options['template_enabled'] ?? '0') === '1'),
            'preview_example' => true,
            'preview_initial_text' => (string) ($justbee_postcaster_general_preview_initial_text ?? ''),
            'preview_initial_image_url' => (string) ($justbee_postcaster_general_preview_initial_image_url ?? ''),
            'preview_initial_image_alt' => (string) ($justbee_postcaster_general_preview_initial_image_alt ?? ''),
            'preview_initial_items' => (array) ($justbee_postcaster_general_preview_initial_items ?? []),
        ]); ?>
    </table>
</div>
