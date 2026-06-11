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
    data-postcaster-tab-storage="admin"
>
    <h1><?php echo esc_html__('PostCaster', 'postcaster'); ?></h1>
    <p><?php echo esc_html__('PostCaster publishes new posts to configured social networks when a post moves from unpublished to publish.', 'postcaster'); ?></p>

    <form method="post" action="options.php">
        <?php settings_fields('justbee_postcaster_options_group'); ?>

        <nav class="nav-tab-wrapper" aria-label="PostCaster tabs">
            <a href="#postcaster-tab-settings" class="nav-tab nav-tab-active" data-postcaster-tab="settings"><?php echo esc_html__('General', 'postcaster'); ?></a>
            <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
                <a href="#postcaster-tab-<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>" class="nav-tab" data-postcaster-tab="<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>"><?php echo esc_html($justbee_postcaster_network->getLabel()); ?></a>
            <?php endforeach; ?>
            <?php if ($justbee_postcaster_debug_enabled) : ?>
                <a href="#postcaster-tab-debug" class="nav-tab" data-postcaster-tab="debug"><?php echo esc_html__('Extensive logging', 'postcaster'); ?></a>
            <?php endif; ?>
        </nav>

        <?php require __DIR__ . '/partials/admin-general-tab.php'; ?>

        <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
            <?php require __DIR__ . '/partials/admin-network-tab.php'; ?>
        <?php endforeach; ?>

        <?php if ($justbee_postcaster_debug_enabled) : ?>
            <?php require __DIR__ . '/partials/admin-debug-tab.php'; ?>
        <?php endif; ?>

        <?php submit_button(); ?>
    </form>

    <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
        <?php justbee_postcaster_render_test_post_form('postcaster-test-form-' . $justbee_postcaster_network->getKey(), 'justbee_postcaster_send_test', $justbee_postcaster_network->getKey(), 'justbee_postcaster_send_test_post'); ?>
    <?php endforeach; ?>
</div>
