<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-tab-<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>" class="postcaster-tab-panel" data-postcaster-panel="<?php echo esc_attr($justbee_postcaster_network->getKey()); ?>">
    <?php if (!empty($justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()] ?? null)) : ?>
        <?php justbee_postcaster_render_warning_notice((string) $justbee_postcaster_network_warnings[$justbee_postcaster_network->getKey()]); ?>
    <?php endif; ?>
    <?php justbee_postcaster_render_network_setup_notice($justbee_postcaster_network); ?>
    <?php justbee_postcaster_render_fields_table($justbee_postcaster_network_fields[$justbee_postcaster_network->getKey()] ?? $justbee_postcaster_network->getAdminFields(), $justbee_postcaster_options, $justbee_postcaster_option_name); ?>
</div>
