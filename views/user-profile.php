<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/helpers.php';
?>
<?php wp_nonce_field('justbee_postcaster_user_profile', 'justbee_postcaster_user_profile_nonce'); ?>
<?php wp_nonce_field('justbee_postcaster_send_profile_test_post', 'justbee_postcaster_profile_test_post_nonce'); ?>
<input type="hidden" name="user_id" value="<?php echo esc_attr($justbee_postcaster_user->ID); ?>">
<h2><?php echo esc_html__('PostCaster', 'postcaster'); ?></h2>

<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('PostCaster profile tabs', 'postcaster'); ?>">
    <a href="#postcaster-profile-tab-settings" class="nav-tab nav-tab-active" data-postcaster-profile-tab="settings"><?php echo esc_html__('General', 'postcaster'); ?></a>
    <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
        <a href="#postcaster-profile-tab-<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>" class="nav-tab" data-postcaster-profile-tab="<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>"><?php echo esc_html($justbee_postcaster_network->getLabel()); ?></a>
    <?php endforeach; ?>
</nav>

<?php require __DIR__ . '/partials/profile-general-tab.php'; ?>

<hr />

<?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
    <?php require __DIR__ . '/partials/profile-network-tab.php'; ?>
<?php endforeach; ?>

<p>
    <button type="submit" class="button button-primary"><?php echo esc_html__('Save profile', 'postcaster'); ?></button>
</p>
