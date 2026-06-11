<?php

use Justbee\PostCaster\Views\FieldTableRenderer;
use Justbee\PostCaster\Views\NoticeRenderer;
use Justbee\PostCaster\Views\ScriptRenderer;
use Justbee\PostCaster\Views\TemplateEditorRenderer;

if (!defined('ABSPATH')) {
    exit;
}

function justbee_postcaster_notice_renderer(): NoticeRenderer
{
    static $justbee_postcaster_renderer = null;
    if (!$justbee_postcaster_renderer instanceof NoticeRenderer) {
        $justbee_postcaster_renderer = new NoticeRenderer();
    }

    return $justbee_postcaster_renderer;
}

function justbee_postcaster_script_renderer(): ScriptRenderer
{
    static $justbee_postcaster_renderer = null;
    if (!$justbee_postcaster_renderer instanceof ScriptRenderer) {
        $justbee_postcaster_renderer = new ScriptRenderer();
    }

    return $justbee_postcaster_renderer;
}

function justbee_postcaster_template_editor_renderer(): TemplateEditorRenderer
{
    static $justbee_postcaster_renderer = null;
    if (!$justbee_postcaster_renderer instanceof TemplateEditorRenderer) {
        $justbee_postcaster_renderer = new TemplateEditorRenderer();
    }

    return $justbee_postcaster_renderer;
}

function justbee_postcaster_field_table_renderer(): FieldTableRenderer
{
    static $justbee_postcaster_renderer = null;
    if (!$justbee_postcaster_renderer instanceof FieldTableRenderer) {
        $justbee_postcaster_renderer = new FieldTableRenderer(justbee_postcaster_template_editor_renderer());
    }

    return $justbee_postcaster_renderer;
}

function justbee_postcaster_render_tab_script(string $justbee_postcaster_tab_attribute, string $justbee_postcaster_panel_attribute, string $justbee_postcaster_storage_key = ''): void
{
    justbee_postcaster_script_renderer()->renderTabScript($justbee_postcaster_tab_attribute, $justbee_postcaster_panel_attribute, $justbee_postcaster_storage_key);
}

function justbee_postcaster_render_network_setup_notice($justbee_postcaster_network): void
{
    justbee_postcaster_notice_renderer()->renderNetworkSetupNotice($justbee_postcaster_network);
}

function justbee_postcaster_render_warning_notice(string $justbee_postcaster_message): void
{
    justbee_postcaster_notice_renderer()->renderWarningNotice($justbee_postcaster_message);
}

function justbee_postcaster_render_test_post_form(string $justbee_postcaster_form_id, string $justbee_postcaster_action, string $justbee_postcaster_network_key, string $justbee_postcaster_nonce_action, array $justbee_postcaster_hidden_fields = []): void
{
    justbee_postcaster_notice_renderer()->renderTestPostForm($justbee_postcaster_form_id, $justbee_postcaster_action, $justbee_postcaster_network_key, $justbee_postcaster_nonce_action, $justbee_postcaster_hidden_fields);
}

function justbee_postcaster_render_fields_table(array $justbee_postcaster_fields, array $justbee_postcaster_values, string $justbee_postcaster_input_name): void
{
    justbee_postcaster_field_table_renderer()->renderFieldsTable($justbee_postcaster_fields, $justbee_postcaster_values, $justbee_postcaster_input_name);
}

function justbee_postcaster_get_input_attributes(array $justbee_postcaster_field, string $justbee_postcaster_control_type = ''): array
{
    return FieldTableRenderer::getInputAttributes($justbee_postcaster_field, $justbee_postcaster_control_type);
}

function justbee_postcaster_render_template_editor_table_rows(array $justbee_postcaster_args): void
{
    justbee_postcaster_template_editor_renderer()->renderTableRows($justbee_postcaster_args);
}

function justbee_postcaster_render_template_editor(array $justbee_postcaster_args): void
{
    justbee_postcaster_template_editor_renderer()->render($justbee_postcaster_args);
}

function justbee_postcaster_render_template_editor_script(): void
{
    justbee_postcaster_template_editor_renderer()->renderScript();
}

function justbee_postcaster_render_compose_preview_items(array $justbee_postcaster_items): void
{
    foreach ($justbee_postcaster_items as $justbee_postcaster_item) {
        if (!is_array($justbee_postcaster_item)) {
            continue;
        }
        $justbee_postcaster_header = is_array($justbee_postcaster_item['header'] ?? null) ? $justbee_postcaster_item['header'] : [];
        $justbee_postcaster_card = is_array($justbee_postcaster_item['card'] ?? null) ? $justbee_postcaster_item['card'] : null;
        $justbee_postcaster_image = is_array($justbee_postcaster_item['image'] ?? null) ? $justbee_postcaster_item['image'] : null;
        $justbee_postcaster_avatar = (string) ($justbee_postcaster_header['avatar_text'] ?? '?');
        $justbee_postcaster_name = (string) ($justbee_postcaster_header['name'] ?? ($justbee_postcaster_item['label'] ?? ''));
        $justbee_postcaster_meta = (string) ($justbee_postcaster_header['meta'] ?? '');
        $justbee_postcaster_has_card = $justbee_postcaster_card !== null && !empty($justbee_postcaster_card['url']) && !empty($justbee_postcaster_card['title']);
        $justbee_postcaster_has_card_image = $justbee_postcaster_has_card && !empty($justbee_postcaster_card['image_url']);
        $justbee_postcaster_has_image = $justbee_postcaster_image !== null && !empty($justbee_postcaster_image['url']);
        $justbee_postcaster_warning = is_string($justbee_postcaster_item['warning'] ?? null) ? trim((string) $justbee_postcaster_item['warning']) : '';
        ?>
        <div class="postcaster-preview-item">
            <?php if ($justbee_postcaster_name !== '' || $justbee_postcaster_meta !== '') : ?>
                <div class="postcaster-preview-item__header">
                    <div class="postcaster-preview-item__avatar postcaster-preview-item__avatar--small"><?php echo esc_html($justbee_postcaster_avatar); ?></div>
                    <div class="postcaster-preview-item__identity">
                        <div class="postcaster-preview-item__name postcaster-preview-item__name--small"><?php echo esc_html($justbee_postcaster_name); ?></div>
                        <div class="postcaster-preview-item__meta postcaster-preview-item__meta--small"><?php echo esc_html($justbee_postcaster_meta); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="postcaster-preview-item__text postcaster-preview-item__text--small"><?php echo esc_html((string) ($justbee_postcaster_item['text'] ?? '')); ?></div>
            <?php if ($justbee_postcaster_has_card) : ?>
                <div class="postcaster-preview-item__card postcaster-preview-item__card--compact">
                    <?php if ($justbee_postcaster_has_card_image) : ?>
                        <div class="postcaster-preview-item__card-image">
                            <img src="<?php echo esc_url((string) $justbee_postcaster_card['image_url']); ?>" alt="<?php echo esc_attr((string) ($justbee_postcaster_card['image_alt'] ?? '')); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="postcaster-preview-item__card-body">
                        <div class="postcaster-preview-item__card-title postcaster-preview-item__card-title--compact"><?php echo esc_html((string) $justbee_postcaster_card['title']); ?></div>
                        <div class="postcaster-preview-item__card-description postcaster-preview-item__card-description--compact"><?php echo esc_html((string) ($justbee_postcaster_card['description'] ?? '')); ?></div>
                        <div class="postcaster-preview-item__card-domain postcaster-preview-item__card-domain--compact"><?php echo esc_html((string) ($justbee_postcaster_card['domain'] ?? '')); ?></div>
                    </div>
                </div>
            <?php elseif ($justbee_postcaster_has_image) : ?>
                <div class="postcaster-preview-item__image-wrap">
                    <img src="<?php echo esc_url((string) $justbee_postcaster_image['url']); ?>" alt="<?php echo esc_attr((string) ($justbee_postcaster_image['alt'] ?? '')); ?>">
                </div>
            <?php endif; ?>
            <?php if ($justbee_postcaster_warning !== '') : ?>
                <div class="postcaster-preview-item__warning notice notice-warning inline" style="margin:6px 0 0;padding:4px 8px;font-size:11px;">
                    <?php echo esc_html($justbee_postcaster_warning); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

function justbee_postcaster_render_template_placeholders_help(): void
{
    justbee_postcaster_template_editor_renderer()->renderPlaceholdersHelp();
}

function justbee_postcaster_render_pagination(array $justbee_postcaster_pagination): void
{
    $justbee_postcaster_total_pages = max(1, (int) ($justbee_postcaster_pagination['total_pages'] ?? 1));
    if ($justbee_postcaster_total_pages <= 1) {
        return;
    }

    $justbee_postcaster_total_items = max(0, (int) ($justbee_postcaster_pagination['total_items'] ?? 0));
    $justbee_postcaster_current_page = max(1, (int) ($justbee_postcaster_pagination['current_page'] ?? 1));
    $justbee_postcaster_per_page = max(1, (int) ($justbee_postcaster_pagination['per_page'] ?? 25));
    $justbee_postcaster_page_arg = (string) ($justbee_postcaster_pagination['page_arg'] ?? 'paged');
    $justbee_postcaster_base_url = (string) ($justbee_postcaster_pagination['base_url'] ?? '');
    $justbee_postcaster_fragment = (string) ($justbee_postcaster_pagination['fragment'] ?? '');
    $justbee_postcaster_first_item = $justbee_postcaster_total_items > 0 ? (($justbee_postcaster_current_page - 1) * $justbee_postcaster_per_page) + 1 : 0;
    $justbee_postcaster_last_item = min($justbee_postcaster_total_items, $justbee_postcaster_current_page * $justbee_postcaster_per_page);
    $justbee_postcaster_links = paginate_links([
        'base' => add_query_arg($justbee_postcaster_page_arg, '%#%', $justbee_postcaster_base_url),
        'format' => '',
        'current' => $justbee_postcaster_current_page,
        'total' => $justbee_postcaster_total_pages,
        'type' => 'plain',
        'prev_text' => __('Previous', 'postcaster'),
        'next_text' => __('Next', 'postcaster'),
        'add_fragment' => $justbee_postcaster_fragment,
    ]);

    if ($justbee_postcaster_links === '') {
        return;
    }
    ?>
    <div class="tablenav bottom">
        <div class="displaying-num">
            <?php
            printf(
                /* translators: 1: first visible item number, 2: last visible item number, 3: total number of items. */
                esc_html__('Showing %1$d-%2$d of %3$d', 'postcaster'),
                (int) $justbee_postcaster_first_item,
                (int) $justbee_postcaster_last_item,
                (int) $justbee_postcaster_total_items
            );
            ?>
        </div>
        <div class="tablenav-pages">
            <?php echo wp_kses_post($justbee_postcaster_links); ?>
        </div>
    </div>
    <?php
}
