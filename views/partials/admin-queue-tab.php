<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-tab-queue" class="postcaster-tab-panel" data-postcaster-panel="queue">
    <h2><?php echo esc_html__('Background queue', 'postcaster'); ?></h2>
    <p><?php echo esc_html__('Queued jobs waiting to run, running now, or recently completed for PostCaster.', 'postcaster'); ?></p>

    <?php if ($justbee_postcaster_queue_rows === []) : ?>
        <p><?php echo esc_html__('No queued publish jobs right now.', 'postcaster'); ?></p>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Article', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Network', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Target', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Trigger', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Scheduled', 'postcaster'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($justbee_postcaster_queue_rows as $justbee_postcaster_row) : ?>
                    <tr>
                        <td>
                            <strong>
                                <?php if ($justbee_postcaster_row['edit_url'] !== '') : ?>
                                    <a href="<?php echo esc_url($justbee_postcaster_row['edit_url']); ?>"><?php echo esc_html($justbee_postcaster_row['post_title']); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($justbee_postcaster_row['post_title']); ?>
                                <?php endif; ?>
                            </strong>
                            <?php if ($justbee_postcaster_row['action_id'] > 0) : ?>
                                <div class="row-actions">
                                    <span><code>#<?php echo esc_html((string) $justbee_postcaster_row['action_id']); ?></code></span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($justbee_postcaster_row['network_label']); ?></td>
                        <td><?php echo esc_html($justbee_postcaster_row['target_label']); ?></td>
                        <td><?php echo esc_html($justbee_postcaster_row['trigger']); ?></td>
                        <td>
                            <strong><?php echo esc_html($justbee_postcaster_row['status']); ?></strong>
                            <?php
                            if ($justbee_postcaster_row['attempt'] > 0) {
                                echo '<br><small>';
                                printf(
                                    /* translators: %d: number of retries already attempted. */
                                    esc_html__('Attempt %d', 'postcaster'),
                                    (int) $justbee_postcaster_row['attempt']
                                );
                                echo '</small>';
                            }
                            ?>
                            <?php if ($justbee_postcaster_row['error_message'] !== '') : ?>
                                <br><small><?php echo esc_html($justbee_postcaster_row['error_message']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($justbee_postcaster_row['scheduled_at'] !== null) : ?>
                                <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $justbee_postcaster_row['scheduled_at'])); ?>
                            <?php else : ?>
                                <?php echo esc_html__('Unknown', 'postcaster'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php justbee_postcaster_render_pagination($justbee_postcaster_queue_rows_pagination); ?>
    <?php endif; ?>
</div>
