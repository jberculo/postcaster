<?php

namespace Justbee\PostCaster\Views;

use Justbee\PostCaster\Services\Networks\NetworkPublisherInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class NoticeRenderer
{
    public function renderNetworkSetupNotice(NetworkPublisherInterface $network): void
    {
        $notice = $network->getSetupNotice();

        if (!is_array($notice) || empty($notice['title']) || empty($notice['steps']) || !is_array($notice['steps'])) {
            return;
        }
        ?>
        <div class="notice notice-info inline">
            <details>
                <summary><strong><?php echo esc_html((string) $notice['title']); ?></strong></summary>
                <ol>
                    <?php foreach ($notice['steps'] as $step) : ?>
                        <li><?php echo esc_html((string) $step); ?></li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!empty($notice['note'])) : ?>
                    <p><?php echo esc_html((string) $notice['note']); ?></p>
                <?php endif; ?>
            </details>
        </div>
        <?php
    }

    public static function buildTestPostConfig(NetworkPublisherInterface $network, array $overrides): array
    {
        return array_merge([
            'label' => sprintf(
                /* translators: %s: social network label. */
                __('Send test post to %s', 'postcaster'),
                $network->getLabel()
            ),
            'description' => __('Uses the latest published article. Save settings first to apply changes.', 'postcaster'),
        ], $overrides);
    }

    public function renderWarningNotice(string $message): void
    {
        ?>
        <div class="notice notice-warning inline">
            <p><strong aria-hidden="true">!</strong> <?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    public function renderTestPostForm(string $formId, string $action, string $networkKey, string $nonceAction, array $hiddenFields = []): void
    {
        ?>
        <form id="<?php echo esc_attr($formId); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none;">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="network" value="<?php echo esc_attr($networkKey); ?>">
            <?php foreach ($hiddenFields as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
            <?php endforeach; ?>
            <?php wp_nonce_field($nonceAction); ?>
        </form>
        <?php
    }
}

