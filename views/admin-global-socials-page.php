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
    data-postcaster-tab-storage="global-socials"
>
    <h1><?php echo esc_html__('Global socials', 'postcaster'); ?></h1>
    <p><?php echo esc_html__('Configure the site-wide social accounts PostCaster can publish to.', 'postcaster'); ?></p>

    <form method="post" action="options.php">
        <?php settings_fields('justbee_postcaster_options_group'); ?>

        <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('PostCaster global network tabs', 'postcaster'); ?>">
            <a href="#postcaster-tab-general" class="nav-tab nav-tab-active" data-postcaster-tab="general"><?php echo esc_html__('General', 'postcaster'); ?></a>
            <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
                <a href="#postcaster-tab-<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>" class="nav-tab" data-postcaster-tab="<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>"><?php echo esc_html($justbee_postcaster_network->getLabel()); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php require __DIR__ . '/partials/admin-global-general-tab.php'; ?>

        <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
            <?php require __DIR__ . '/partials/admin-network-tab.php'; ?>
        <?php endforeach; ?>

        <?php submit_button(); ?>
    </form>

    <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
        <?php justbee_postcaster_render_test_post_form('postcaster-test-form-' . $justbee_postcaster_network->getKey(), 'justbee_postcaster_send_test', $justbee_postcaster_network->getKey(), 'justbee_postcaster_send_test_post'); ?>
    <?php endforeach; ?>
</div>
