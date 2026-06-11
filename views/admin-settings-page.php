<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/helpers.php';
?>
<div
    class="wrap"
    data-postcaster-tabs-root="1"
    data-postcaster-tab-attribute="postcaster-tab"
    data-postcaster-panel-attribute="postcaster-panel"
    data-postcaster-tab-storage="settings"
>
    <h1><?php echo esc_html__('PostCaster Settings', 'postcaster'); ?></h1>
    <p><?php echo esc_html__('PostCaster publishes new posts to configured social networks when a post moves from unpublished to publish.', 'postcaster'); ?></p>

    <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('PostCaster settings tabs', 'postcaster'); ?>">
        <a href="#postcaster-tab-settings" class="nav-tab nav-tab-active" data-postcaster-tab="settings"><?php echo esc_html__('General', 'postcaster'); ?></a>
        <a href="#postcaster-tab-queue" class="nav-tab" data-postcaster-tab="queue"><?php echo esc_html__('Queue', 'postcaster'); ?></a>
        <?php if ($justbee_postcaster_debug_enabled || $justbee_postcaster_failure_rows !== []) : ?>
            <a href="#postcaster-tab-debug" class="nav-tab" data-postcaster-tab="debug"><?php echo esc_html__('Logging', 'postcaster'); ?></a>
        <?php endif; ?>
    </nav>

    <form method="post" action="options.php">
        <?php settings_fields('justbee_postcaster_options_group'); ?>
        <?php require __DIR__ . '/partials/admin-general-tab.php'; ?>
    </form>

    <?php require __DIR__ . '/partials/admin-queue-tab.php'; ?>

    <?php if ($justbee_postcaster_debug_enabled || $justbee_postcaster_failure_rows !== []) : ?>
        <?php require __DIR__ . '/partials/admin-debug-tab.php'; ?>
    <?php endif; ?>
</div>
