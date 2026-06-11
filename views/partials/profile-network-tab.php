<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-profile-tab-<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>" class="postcaster-profile-tab-panel" data-postcaster-profile-panel="<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>">
    <?php if (!empty($justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()] ?? null) && ($justbee_postcaster_profile['enabled'] ?? '0') !== '1') : ?>
        <?php justbee_postcaster_render_warning_notice((string) $justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()]); ?>
    <?php endif; ?>
    <?php justbee_postcaster_render_network_setup_notice($justbee_postcaster_network); ?>
    <?php justbee_postcaster_render_fields_table($justbee_postcaster_network_fields[$justbee_postcaster_network->getKey()] ?? $justbee_postcaster_network->getProfileFields(), $justbee_postcaster_profile, 'justbee_postcaster_user'); ?>
</div>
