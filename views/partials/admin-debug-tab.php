<?php

if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="postcaster-tab-debug" class="postcaster-tab-panel" data-postcaster-panel="debug">
    <?php if ($justbee_postcaster_failure_rows !== []) : ?>
        <h2><?php echo esc_html__('Failed publishes', 'postcaster'); ?></h2>
        <p>
            <?php echo esc_html__('Posts that currently have one or more PostCaster errors. Open the post to inspect details and use the metabox to retry or fix the configuration.', 'postcaster'); ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Post', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Errors', 'postcaster'); ?></th>
                    <th scope="col"><?php echo esc_html__('Retries', 'postcaster'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($justbee_postcaster_failure_rows as $justbee_postcaster_row) : ?>
                <?php
                $justbee_postcaster_post = $justbee_postcaster_row['post'];
                $justbee_postcaster_title = get_the_title($justbee_postcaster_post);
                if ($justbee_postcaster_title === '') {
                    $justbee_postcaster_title = sprintf('#%d', $justbee_postcaster_post->ID);
                }
                ?>
                <tr>
                    <td>
                        <strong>
                            <?php if ($justbee_postcaster_row['edit_url'] !== '') : ?>
                                <a href="<?php echo esc_url($justbee_postcaster_row['edit_url']); ?>"><?php echo esc_html($justbee_postcaster_title); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($justbee_postcaster_title); ?>
                            <?php endif; ?>
                        </strong>
                        <div class="row-actions">
                            <span><?php echo esc_html(get_the_date('', $justbee_postcaster_post)); ?></span>
                            <?php if ($justbee_postcaster_post->post_author > 0) : ?>
                                · <span><?php echo esc_html(get_the_author_meta('display_name', (int) $justbee_postcaster_post->post_author)); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?php echo esc_html(ucfirst((string) $justbee_postcaster_post->post_status)); ?></td>
                    <td>
                        <ul style="margin:0;">
                            <?php foreach ($justbee_postcaster_row['errors'] as $justbee_postcaster_error) : ?>
                                <?php $justbee_postcaster_label = $justbee_postcaster_network_labels[$justbee_postcaster_error['network']] ?? $justbee_postcaster_error['network']; ?>
                                <li>
                                    <strong><?php echo esc_html($justbee_postcaster_label); ?></strong>
                                    <?php if ($justbee_postcaster_error['target_key'] !== '' && $justbee_postcaster_error['target_key'] !== 'global') : ?>
                                        <em>(<?php echo esc_html($justbee_postcaster_error['target_key']); ?>)</em>
                                    <?php endif; ?>
                                    - <?php echo esc_html($justbee_postcaster_error['message']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                    <td>
                        <?php
                        if ($justbee_postcaster_row['retry_count'] > 0) {
                            printf(
                                /* translators: %d: number of retries already attempted. */
                                esc_html__('Attempt %d', 'postcaster'),
                                (int) $justbee_postcaster_row['retry_count']
                            );
                        } else {
                            echo '-';
                        }

                        if ($justbee_postcaster_row['next_retry'] !== null) {
                            echo '<br><small>';
                            printf(
                                /* translators: %s: human-readable duration until the next retry. */
                                esc_html__('Next retry in %s', 'postcaster'),
                                esc_html(human_time_diff(time(), (int) $justbee_postcaster_row['next_retry']))
                            );
                            echo '</small>';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php justbee_postcaster_render_pagination($justbee_postcaster_failure_rows_pagination); ?>

        <?php if ($justbee_postcaster_debug_enabled) : ?>
            <hr>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($justbee_postcaster_debug_enabled) : ?>
        <h2><?php echo esc_html__('Extensive logging', 'postcaster'); ?></h2>
        <div class="notice notice-info inline">
            <p><strong><?php echo esc_html__('Central logging', 'postcaster'); ?></strong></p>
            <p><?php echo esc_html__('Newest messages are shown first.', 'postcaster'); ?></p>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(
                    add_query_arg('action', 'justbee_postcaster_clear_debug_logs', admin_url('admin-post.php')),
                    'justbee_postcaster_clear_debug_logs'
                )); ?>"><?php echo esc_html__('Clear logs', 'postcaster'); ?></a>
            </p>
        </div>

        <?php if ($justbee_postcaster_debug_entries === [] && $justbee_postcaster_system_debug_entries === []) : ?>
            <p><?php echo esc_html__('No PostCaster log messages yet.', 'postcaster'); ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($justbee_postcaster_system_debug_entries !== []) : ?>
        <div class="notice notice-info inline">
            <p><strong><?php echo esc_html__('Connection tests', 'postcaster'); ?></strong></p>
            <ol>
                <?php foreach ($justbee_postcaster_system_debug_entries as $justbee_postcaster_entry) : ?>
                    <li><code><?php echo esc_html(sprintf('[%s] %s', (string) ($justbee_postcaster_entry['timestamp'] ?? ''), (string) ($justbee_postcaster_entry['message'] ?? ''))); ?></code></li>
                <?php endforeach; ?>
            </ol>
        </div>

        <?php justbee_postcaster_render_pagination($justbee_postcaster_system_debug_entries_pagination); ?>
    <?php endif; ?>

    <?php if ($justbee_postcaster_debug_entries !== []) : ?>
        <div class="postcaster-debug-log">
            <?php foreach ($justbee_postcaster_debug_entries as $justbee_postcaster_entry) : ?>
                <div class="notice notice-info inline">
                    <p>
                        <strong>
                            <?php if (!empty($justbee_postcaster_entry['edit_url'])) : ?>
                                <a href="<?php echo esc_url((string) $justbee_postcaster_entry['edit_url']); ?>"><?php echo esc_html((string) $justbee_postcaster_entry['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html((string) $justbee_postcaster_entry['title']); ?>
                            <?php endif; ?>
                        </strong>
                        <code>#<?php echo esc_html((string) $justbee_postcaster_entry['post_id']); ?></code>
                    </p>
                    <ol>
                        <?php foreach ($justbee_postcaster_entry['lines'] as $justbee_postcaster_line) : ?>
                            <li><code><?php echo esc_html((string) $justbee_postcaster_line); ?></code></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endforeach; ?>
        </div>

        <?php justbee_postcaster_render_pagination($justbee_postcaster_debug_entries_pagination); ?>
    <?php endif; ?>
</div>
