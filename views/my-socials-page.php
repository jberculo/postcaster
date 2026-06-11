<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/helpers.php';
?>
<div class="wrap">
    <h1><?php echo esc_html__('My socials', 'postcaster'); ?></h1>

    <?php if (!empty($justbee_postcaster_personal_networks_disabled_message)) : ?>
        <?php justbee_postcaster_render_warning_notice($justbee_postcaster_personal_networks_disabled_message); ?>
    <?php endif; ?>

    <?php if (empty($justbee_postcaster_personal_networks_disabled_message)) : ?>
        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            data-postcaster-tabs-root="1"
            data-postcaster-tab-attribute="postcaster-profile-tab"
            data-postcaster-panel-attribute="postcaster-profile-panel"
            data-postcaster-tab-storage="profile"
        >
            <input type="hidden" name="action" value="justbee_postcaster_save_my_socials">
            <?php require __DIR__ . '/user-profile.php'; ?>
        </form>
    <?php endif; ?>
</div>
