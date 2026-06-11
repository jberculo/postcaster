<?php

namespace Justbee\PostCaster\Views;

use Justbee\PostCaster\Plugin;
use Justbee\PostCaster\Services\NetworkRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders preview "card" items shared by the template editor previews and the
 * post-edit metabox previews. The HTML structure mirrors the JS counterparts
 * in assets/js/postcaster-template-editor.js and assets/js/postcaster-compose-box.js
 * so AJAX-driven re-renders match what was rendered server-side.
 */
final class PreviewItemRenderer
{
    private const DEFAULT_PALETTE = ['background' => '#eef2f7', 'color' => '#334155'];

    public function renderItems(string $toggleKey, array $items): void
    {
        foreach ($items as $index => $item) {
            $this->renderItem($toggleKey, (array) $item, $index === 0);
        }
    }

    private function renderItem(string $toggleKey, array $item, bool $isFirst): void
    {
        $palette = $this->getAvatarPalette((string) (($item['header']['network'] ?? $item['network'] ?? '')));
        $avatarStyle = sprintf(
            'background:%s;color:%s;',
            sanitize_hex_color((string) $palette['background']) ?: self::DEFAULT_PALETTE['background'],
            sanitize_hex_color((string) $palette['color']) ?: self::DEFAULT_PALETTE['color']
        );
        $hasHeader = !empty($item['header']['name']) || !empty($item['label']);
        $card = is_array($item['card'] ?? null) ? $item['card'] : null;
        $hasCard = $card !== null && !empty($card['url']) && !empty($card['title']);
        $image = is_array($item['image'] ?? null) ? $item['image'] : null;
        $hasImage = $image !== null && !empty($image['url']);
        ?>
        <div class="postcaster-preview-item" data-postcaster-template-preview-item-wrap="<?php echo esc_attr($toggleKey); ?>">
            <?php if ($hasHeader) : ?>
                <div class="postcaster-preview-item__header">
                    <div class="postcaster-preview-item__avatar" style="<?php echo esc_attr($avatarStyle); ?>">
                        <?php echo esc_html($this->resolveAvatarText($item)); ?>
                    </div>
                    <div class="postcaster-preview-item__identity">
                        <div class="postcaster-preview-item__name"><?php echo esc_html((string) ($item['header']['name'] ?? $item['label'] ?? '')); ?></div>
                        <div class="postcaster-preview-item__meta"><?php echo esc_html((string) ($item['header']['meta'] ?? $item['label'] ?? '')); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="postcaster-preview-item__text"<?php if ($isFirst) : ?> data-postcaster-template-preview="<?php echo esc_attr($toggleKey); ?>"<?php endif; ?>><?php echo esc_html((string) ($item['text'] ?? '')); ?></div>
            <?php if ($hasCard) : ?>
                <div class="postcaster-preview-item__card" data-postcaster-template-preview-card-wrap="<?php echo esc_attr($toggleKey); ?>">
                    <?php if (!empty($card['image_url'])) : ?>
                        <div class="postcaster-preview-item__card-image">
                            <img src="<?php echo esc_url((string) $card['image_url']); ?>" alt="<?php echo esc_attr((string) ($card['image_alt'] ?? '')); ?>">
                        </div>
                    <?php endif; ?>
                    <div class="postcaster-preview-item__card-body">
                        <div class="postcaster-preview-item__card-title"><?php echo esc_html((string) ($card['title'] ?? '')); ?></div>
                        <div class="postcaster-preview-item__card-description"><?php echo esc_html((string) ($card['description'] ?? '')); ?></div>
                    </div>
                    <div class="postcaster-preview-item__card-domain"><?php echo esc_html((string) ($card['domain'] ?? '')); ?></div>
                </div>
                <?php if ($hasImage) : ?>
                    <?php $this->renderImage($toggleKey, $image, true); ?>
                <?php endif; ?>
            <?php elseif ($hasImage) : ?>
                <?php $this->renderImage($toggleKey, $image, false); ?>
            <?php endif; ?>
            <?php
            $warning = is_string($item['warning'] ?? null) ? trim((string) $item['warning']) : '';
            if ($warning !== '') :
                ?>
                <div class="postcaster-preview-item__warning notice notice-warning inline" style="margin:8px 0 0;padding:6px 10px;font-size:12px;">
                    <?php echo esc_html($warning); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderImage(string $toggleKey, array $image, bool $hidden): void
    {
        ?>
        <div class="postcaster-preview-item__image-wrap" data-postcaster-template-preview-image-wrap="<?php echo esc_attr($toggleKey); ?>"<?php if ($hidden) : ?> hidden style="display:none;"<?php endif; ?>>
            <img data-postcaster-template-preview-image="<?php echo esc_attr($toggleKey); ?>" src="<?php echo esc_url((string) $image['url']); ?>" alt="<?php echo esc_attr((string) ($image['alt'] ?? '')); ?>">
        </div>
        <?php
    }

    private function resolveAvatarText(array $item): string
    {
        if (!empty($item['header']['avatar_text'])) {
            return (string) $item['header']['avatar_text'];
        }

        $label = (string) ($item['label'] ?? '?');

        return mb_strtoupper(mb_substr($label, 0, 1, 'UTF-8'), 'UTF-8');
    }

    public function getAvatarPalette(string $networkKey): array
    {
        return $this->getAvatarPalettes()[$networkKey] ?? self::DEFAULT_PALETTE;
    }

    public function getAvatarPalettes(): array
    {
        $networks = Plugin::instance()->getNetworks();

        return $networks instanceof NetworkRegistry ? $networks->getAvatarPalettes() : [];
    }
}
