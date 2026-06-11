<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/helpers.php';

/** @var WP_Post $justbee_postcaster_post */
/** @var array $justbee_postcaster_compose_box */
/** @var bool $justbee_postcaster_disable_publish_checked */
/** @var array $justbee_postcaster_errors */
/** @var bool $justbee_postcaster_has_existing_remote_posts */
/** @var array<int, array{network_label:string, target_label:string, remote_url:string}> $justbee_postcaster_existing_remote_posts */
/** @var bool $justbee_postcaster_can_manual_publish */
/** @var bool $justbee_postcaster_is_read_only */
/** @var array|null $justbee_postcaster_retry_notice */

$justbee_postcaster_scopes = (array) ($justbee_postcaster_compose_box['scopes'] ?? []);
$justbee_postcaster_active_scope = (string) ($justbee_postcaster_compose_box['active_scope'] ?? '');
$justbee_postcaster_post_id = (int) $justbee_postcaster_post->ID;
$justbee_postcaster_admin_post_url = admin_url('admin-post.php');
$justbee_postcaster_preview_url = admin_url('admin-ajax.php');
$justbee_postcaster_preview_nonce = wp_create_nonce('justbee_postcaster_preview_post_template_' . $justbee_postcaster_post_id);
$justbee_postcaster_publish_nonce = wp_create_nonce('justbee_postcaster_publish_now_' . $justbee_postcaster_post_id);
?>
<?php wp_nonce_field('justbee_postcaster_post_settings', 'justbee_postcaster_post_nonce'); ?>

<?php if (!empty($justbee_postcaster_is_read_only)) : ?>
    <div class="notice notice-info inline">
        <p><?php echo esc_html__('This article has already been published. On published articles, only admins and editors can repost it from this page.', 'postcaster'); ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($justbee_postcaster_errors)) : ?>
    <div class="notice notice-error inline">
        <p><strong><?php echo esc_html__('PostCaster could not publish to one or more targets:', 'postcaster'); ?></strong></p>
        <ul style="margin:0 0 0 1.2em; list-style:disc;">
            <?php foreach ($justbee_postcaster_errors as $justbee_postcaster_error) : ?>
                <li><?php echo esc_html(sprintf('%s: %s', (string) ($justbee_postcaster_error['network'] ?? ''), (string) ($justbee_postcaster_error['message'] ?? ''))); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="description" style="margin-top:8px;">
            <?php echo esc_html__('Typical causes are invalid credentials, media upload problems, or a message that is still too long after safe shortening.', 'postcaster'); ?>
        </p>
    </div>
<?php endif; ?>

<?php if (!empty($justbee_postcaster_retry_notice) && is_array($justbee_postcaster_retry_notice)) : ?>
    <div class="notice notice-<?php echo esc_attr((string) ($justbee_postcaster_retry_notice['type'] ?? 'info')); ?> inline">
        <p><strong><?php echo esc_html((string) ($justbee_postcaster_retry_notice['title'] ?? '')); ?></strong></p>
        <p><?php echo esc_html((string) ($justbee_postcaster_retry_notice['message'] ?? '')); ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($justbee_postcaster_has_existing_remote_posts)) : ?>
    <div class="notice notice-warning inline">
        <p><strong><?php echo esc_html__('This article has already been posted:', 'postcaster'); ?></strong></p>
        <ul style="margin:4px 0 0 1.2em; list-style:disc;">
            <?php foreach ((array) $justbee_postcaster_existing_remote_posts as $justbee_postcaster_remote) : ?>
                <li>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: network label (Bluesky/Mastodon/...), 2: target group (Global/Personal accounts). */
                        __('%1$s — %2$s', 'postcaster'),
                        (string) ($justbee_postcaster_remote['network_label'] ?? ''),
                        (string) ($justbee_postcaster_remote['target_label'] ?? '')
                    )); ?>
                    <?php if (!empty($justbee_postcaster_remote['remote_url'])) : ?>
                        <a href="<?php echo esc_url((string) $justbee_postcaster_remote['remote_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('view post', 'postcaster'); ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!empty($justbee_postcaster_can_manual_publish)) : ?>
            <p class="description" style="margin-top:6px;"><?php echo esc_html__('Posting again can create duplicates.', 'postcaster'); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div
    data-postcaster-compose
    data-postcaster-post-id="<?php echo esc_attr((string) $justbee_postcaster_post_id); ?>"
    data-postcaster-preview-url="<?php echo esc_attr($justbee_postcaster_preview_url); ?>"
    data-postcaster-publish-url="<?php echo esc_attr($justbee_postcaster_admin_post_url); ?>"
    data-postcaster-preview-nonce="<?php echo esc_attr($justbee_postcaster_preview_nonce); ?>"
    data-postcaster-publish-nonce="<?php echo esc_attr($justbee_postcaster_publish_nonce); ?>"
>
    <?php if (count($justbee_postcaster_scopes) > 1) : ?>
        <p>
            <label>
                <strong><?php echo esc_html__('Posting to', 'postcaster'); ?></strong>
                <select data-postcaster-scope-select>
                    <?php foreach ($justbee_postcaster_scopes as $justbee_postcaster_scope) : ?>
                        <option value="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>" <?php selected((string) $justbee_postcaster_scope['key'], $justbee_postcaster_active_scope); ?>>
                            <?php echo esc_html((string) $justbee_postcaster_scope['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
    <?php endif; ?>

    <?php foreach ($justbee_postcaster_scopes as $justbee_postcaster_scope_key => $justbee_postcaster_scope) : ?>
        <?php
        $justbee_postcaster_input_prefix = 'justbee_postcaster_post_drafts[' . esc_attr((string) $justbee_postcaster_scope['key']) . ']';
        $justbee_postcaster_combined_items = (array) ($justbee_postcaster_scope['combined_preview']['items'] ?? []);
        ?>
        <div
            data-postcaster-scope-pane="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
            <?php if ((string) $justbee_postcaster_scope['key'] !== $justbee_postcaster_active_scope) : ?>hidden style="display:none;"<?php endif; ?>
        >
            <input
                type="hidden"
                name="<?php echo esc_attr($justbee_postcaster_input_prefix); ?>[combined]"
                value="<?php echo esc_attr((string) $justbee_postcaster_scope['combined_template']); ?>"
                data-postcaster-draft="combined"
                data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
            >
            <input
                type="hidden"
                name="<?php echo esc_attr($justbee_postcaster_input_prefix); ?>[include_featured_image]"
                value="<?php echo esc_attr((string) $justbee_postcaster_scope['include_featured_image_scope']); ?>"
                data-postcaster-draft="include_featured_image"
                data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
            >

            <?php if (count((array) $justbee_postcaster_scope['networks']) > 0) : ?>
                <p style="margin:6px 0 10px;">
                    <label>
                        <strong><?php echo esc_html__('Show preview for', 'postcaster'); ?></strong>
                        <select data-postcaster-network-select data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>">
                            <option value=""><?php echo esc_html__('All networks (combined)', 'postcaster'); ?></option>
                            <?php foreach ((array) $justbee_postcaster_scope['networks'] as $justbee_postcaster_network_key => $justbee_postcaster_network) : ?>
                                <option value="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>"><?php echo esc_html((string) $justbee_postcaster_network['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
            <?php endif; ?>

            <div data-postcaster-preview-block data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>" data-postcaster-network="">
                <h4 style="margin:8px 0;"><?php echo esc_html((string) $justbee_postcaster_scope['label']); ?></h4>
                <div data-postcaster-preview-items="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>:">
                    <?php justbee_postcaster_render_compose_preview_items($justbee_postcaster_combined_items); ?>
                </div>
                <p>
                    <button
                        type="button"
                        class="button button-secondary"
                        data-postcaster-edit
                        data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                        data-postcaster-network=""
                        data-postcaster-default-template="<?php echo esc_attr((string) $justbee_postcaster_scope['combined_default_template']); ?>"
                    ><?php echo esc_html__('Customize', 'postcaster'); ?></button>
                    <?php if (!empty($justbee_postcaster_can_manual_publish)) : ?>
                        <button
                            type="button"
                            class="button button-primary"
                            data-postcaster-publish
                            data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                            data-postcaster-network=""
                            data-postcaster-publish-summary="<?php echo esc_attr(sprintf(
                                /* translators: %s: target group label, e.g. "Global accounts". */
                                __('Post to all networks under %s.', 'postcaster'),
                                (string) $justbee_postcaster_scope['label']
                            )); ?>"
                        ><?php echo esc_html__('Post', 'postcaster'); ?></button>
                    <?php endif; ?>
                </p>
            </div>

            <?php foreach ((array) $justbee_postcaster_scope['networks'] as $justbee_postcaster_network_key => $justbee_postcaster_network) : ?>
                <input
                    type="hidden"
                    name="<?php echo esc_attr($justbee_postcaster_input_prefix); ?>[networks][<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>][template]"
                    value="<?php echo esc_attr((string) $justbee_postcaster_network['template']); ?>"
                    data-postcaster-draft="network_template"
                    data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                    data-postcaster-network="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>"
                >
                <input
                    type="hidden"
                    name="<?php echo esc_attr($justbee_postcaster_input_prefix); ?>[networks][<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>][include_featured_image]"
                    value="<?php echo esc_attr((string) $justbee_postcaster_network['include_featured_image']); ?>"
                    data-postcaster-draft="network_featured"
                    data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                    data-postcaster-network="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>"
                >
                <div data-postcaster-preview-block data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>" data-postcaster-network="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>" hidden style="display:none;">
                    <h4 style="margin:8px 0;"><?php echo esc_html((string) $justbee_postcaster_network['label']); ?></h4>
                    <div data-postcaster-preview-items="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>:<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>">
                        <?php justbee_postcaster_render_compose_preview_items((array) ($justbee_postcaster_network['preview']['items'] ?? [])); ?>
                    </div>
                    <p>
                        <button
                            type="button"
                            class="button button-secondary"
                            data-postcaster-edit
                            data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                            data-postcaster-network="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>"
                            data-postcaster-default-template="<?php echo esc_attr((string) $justbee_postcaster_scope['combined_default_template']); ?>"
                        ><?php echo esc_html__('Customize', 'postcaster'); ?></button>
                        <?php if (!empty($justbee_postcaster_can_manual_publish)) : ?>
                            <button
                                type="button"
                                class="button button-primary"
                                data-postcaster-publish
                                data-postcaster-scope="<?php echo esc_attr((string) $justbee_postcaster_scope['key']); ?>"
                                data-postcaster-network="<?php echo esc_attr((string) $justbee_postcaster_network['key']); ?>"
                                data-postcaster-publish-summary="<?php echo esc_attr(sprintf(
                                    /* translators: 1: network label, 2: target group label. */
                                    __('Post only to %1$s (%2$s).', 'postcaster'),
                                    (string) $justbee_postcaster_network['label'],
                                    (string) $justbee_postcaster_scope['label']
                                )); ?>"
                            ><?php echo esc_html__('Post', 'postcaster'); ?></button>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <p style="margin-top:14px;">
        <label>
            <input type="checkbox" name="justbee_postcaster_post_disable_publish" value="1" <?php checked($justbee_postcaster_disable_publish_checked); ?><?php echo !empty($justbee_postcaster_is_read_only) ? ' disabled="disabled"' : ''; ?>>
            <?php echo esc_html__('Skip automatic publishing for this article', 'postcaster'); ?>
        </label>
    </p>
    <p class="description">
        <?php echo esc_html__('When enabled, PostCaster will not automatically publish this article. Manual posts via the buttons above still work.', 'postcaster'); ?>
    </p>
</div>

<dialog data-postcaster-confirm-modal class="postcaster-modal postcaster-modal--compact">
    <form method="dialog" class="postcaster-modal__form postcaster-modal__form--compact">
        <h2 class="postcaster-modal__title postcaster-modal__title--small"><?php echo esc_html__('Post now?', 'postcaster'); ?></h2>
        <p data-postcaster-confirm-summary style="margin:0;font-size:13px;line-height:1.5;color:#1f2933;"></p>
        <div class="postcaster-modal__actions">
            <button type="button" class="button" data-postcaster-confirm-cancel><?php echo esc_html__('Cancel', 'postcaster'); ?></button>
            <button type="button" class="button button-primary" data-postcaster-confirm-ok><?php echo esc_html__('Post', 'postcaster'); ?></button>
        </div>
    </form>
</dialog>

<dialog data-postcaster-modal class="postcaster-modal">
    <form method="dialog" class="postcaster-modal__form">
        <h2 class="postcaster-modal__title" data-postcaster-modal-title><?php echo esc_html__('Customize template', 'postcaster'); ?></h2>
        <div data-postcaster-modal-preview style="max-width:480px;"></div>
        <div>
            <label class="postcaster-modal__section-label"><?php echo esc_html__('Template', 'postcaster'); ?></label>
            <textarea data-postcaster-modal-template rows="6" class="large-text postcaster-modal__textarea"></textarea>
        </div>
        <div class="postcaster-modal__actions">
            <button type="button" class="button" data-postcaster-modal-update><?php echo esc_html__('Update preview', 'postcaster'); ?></button>
            <button type="button" class="button" data-postcaster-modal-cancel><?php echo esc_html__('Cancel', 'postcaster'); ?></button>
            <button type="button" class="button button-primary" data-postcaster-modal-save><?php echo esc_html__('Save changes', 'postcaster'); ?></button>
        </div>
    </form>
</dialog>
