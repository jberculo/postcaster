<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-tab-settings" class="postcaster-tab-panel is-active" data-postcaster-panel="settings">
    <div class="notice notice-info inline">
        <details>
            <summary><strong><?php echo esc_html__('How it works', 'postcaster'); ?></strong></summary>
            <p><?php echo esc_html__('PostCaster only publishes automatically when a post moves from unpublished to publish. Updating an already published post does not create a new social post automatically.', 'postcaster'); ?></p>
            <p><?php echo esc_html__('Global social defaults and network credentials are configured under Global socials. Authors can add personal accounts under My socials when enabled.', 'postcaster'); ?></p>
            <p><?php echo esc_html__('Recommended test: enable extensive logging, publish a new test post with a featured image, and check the central PostCaster logging tab if nothing appears on the social network.', 'postcaster'); ?></p>
        </details>
    </div>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Plugin enabled', 'postcaster'); ?></th>
            <td>
                <input type="hidden" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[enabled]" value="0">
                <label><input type="checkbox" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[enabled]" value="1" <?php checked($justbee_postcaster_options['enabled'], '1'); ?>> <?php echo esc_html__('Enable', 'postcaster'); ?></label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Extensive logging', 'postcaster'); ?></th>
            <td>
                <input type="hidden" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[debug]" value="0">
                <label><input type="checkbox" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[debug]" value="1" <?php checked($justbee_postcaster_options['debug'], '1'); ?>> <?php echo esc_html__('Store log messages centrally in post meta and show them in the logging tab', 'postcaster'); ?></label>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Personal networks', 'postcaster'); ?></th>
            <td>
                <?php $justbee_postcaster_personal_networks_enabled = ($justbee_postcaster_options['personal_networks_enabled'] ?? '1') === '1'; ?>
                <input type="hidden" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[personal_networks_enabled]" value="0">
                <label><input type="checkbox" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[personal_networks_enabled]" value="1" <?php checked($justbee_postcaster_personal_networks_enabled); ?> data-postcaster-personal-networks-toggle="1"> <?php echo esc_html__('Allow authors to configure and use personal social accounts', 'postcaster'); ?></label>
                <p class="description"><?php echo esc_html__('When this is off, PostCaster hides the profile settings and only publishes through the global accounts configured here.', 'postcaster'); ?></p>
                <?php if ($justbee_postcaster_networks !== []) : ?>
                    <div data-postcaster-personal-networks-list="1"<?php if (!$justbee_postcaster_personal_networks_enabled) : ?> hidden style="display:none;"<?php endif; ?>>
                        <fieldset style="margin-top:12px;">
                            <legend class="screen-reader-text"><?php echo esc_html__('Available personal networks', 'postcaster'); ?></legend>
                            <?php foreach ($justbee_postcaster_networks as $justbee_postcaster_network) : ?>
                                <?php $justbee_postcaster_availability_key = 'personal_network_available_' . $justbee_postcaster_network->getKey(); ?>
                                <input type="hidden" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[<?php echo esc_attr($justbee_postcaster_availability_key); ?>]" value="0">
                                <label style="display:block; margin-bottom:4px;">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[<?php echo esc_attr($justbee_postcaster_availability_key); ?>]"
                                        value="1"
                                        <?php checked($justbee_postcaster_options[$justbee_postcaster_availability_key] ?? '1', '1'); ?>
                                    >
                                    <?php
                                    echo esc_html(
                                        sprintf(
                                            /* translators: %s: social network label. */
                                            __('Allow authors to use %s', 'postcaster'),
                                            $justbee_postcaster_network->getLabel()
                                        )
                                    );
                                    ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description"><?php echo esc_html__('Unchecked networks are hidden from My socials and any saved profile credentials for them are ignored until you enable them again.', 'postcaster'); ?></p>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Post types', 'postcaster'); ?></th>
            <td>
                <input type="hidden" name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[post_types][]" value="">
                <?php foreach ($justbee_postcaster_available_post_types as $justbee_postcaster_post_type) : ?>
                    <label style="display:block; margin-bottom:4px;">
                        <input
                            type="checkbox"
                            name="<?php echo esc_attr($justbee_postcaster_option_name); ?>[post_types][]"
                            value="<?php echo esc_attr((string) $justbee_postcaster_post_type->name); ?>"
                            <?php checked(in_array((string) $justbee_postcaster_post_type->name, $justbee_postcaster_selected_post_types, true)); ?>
                        >
                        <?php echo esc_html((string) ($justbee_postcaster_post_type->labels->singular_name ?? $justbee_postcaster_post_type->label ?? $justbee_postcaster_post_type->name)); ?>
                        <code><?php echo esc_html((string) $justbee_postcaster_post_type->name); ?></code>
                    </label>
                <?php endforeach; ?>
                <p class="description"><?php echo esc_html__('Choose which post types PostCaster may publish automatically.', 'postcaster'); ?></p>
            </td>
        </tr>
    </table>

    <?php submit_button(); ?>
</div>
