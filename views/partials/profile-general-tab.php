<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-profile-tab-settings" class="postcaster-profile-tab-panel is-active" data-postcaster-profile-panel="settings">
    <?php if (!empty($justbee_postcaster_personal_accounts_warning)) : ?>
        <?php justbee_postcaster_render_warning_notice($justbee_postcaster_personal_accounts_warning); ?>
    <?php endif; ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Use personal accounts', 'postcaster'); ?></th>
            <td>
                <label><input type="checkbox" name="justbee_postcaster_user[enabled]" value="1" <?php checked($justbee_postcaster_profile['enabled'], '1'); ?>> <?php echo esc_html__('Use my own social accounts for my publications', 'postcaster'); ?></label>
                <p class="description"><?php echo esc_html__('Only for posts where you are the author or a linked co-author. Global accounts remain active in parallel when they are also configured.', 'postcaster'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Other articles', 'postcaster'); ?></th>
            <td>
                <label><input type="checkbox" name="justbee_postcaster_user[publish_other_posts]" value="1" <?php checked($justbee_postcaster_profile['publish_other_posts'] ?? '0', '1'); ?>> <?php echo esc_html__('Also publish other articles that appear on the site through my personal accounts', 'postcaster'); ?></label>
                <p class="description"><?php echo esc_html__('Off by default. When enabled, posts by other authors use the general PostCaster template on your personal networks. Your own articles still use your personal profile flow and template settings.', 'postcaster'); ?></p>
            </td>
        </tr>
    </table>
    <?php justbee_postcaster_render_fields_table($justbee_postcaster_profile_general_fields, $justbee_postcaster_profile, 'justbee_postcaster_user'); ?>
</div>
