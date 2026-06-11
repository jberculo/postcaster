<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Services\Networks\NetworkPublisherInterface;

if (!defined('ABSPATH')) {
    exit;
}

final class NetworkRegistry
{
    /** @var array<string, NetworkPublisherInterface> */
    private array $publishers = [];

    /** @param NetworkPublisherInterface[] $publishers */
    public function __construct(array $publishers = [])
    {
        foreach ($publishers as $publisher) {
            if ($publisher instanceof NetworkPublisherInterface) {
                $this->register($publisher);
            }
        }
    }

    public function register(NetworkPublisherInterface $publisher): void
    {
        $this->publishers[$publisher->getKey()] = $publisher;
    }

    public function all(): array
    {
        return $this->publishers;
    }

    public function keys(): array
    {
        return array_keys($this->publishers);
    }

    public function get(string $key): ?NetworkPublisherInterface
    {
        return $this->publishers[$key] ?? null;
    }

    public function getGlobalDefaults(): array
    {
        $defaults = [];
        foreach ($this->publishers as $publisher) {
            $defaults = array_merge($defaults, $publisher->getGlobalDefaults());
        }

        return $defaults;
    }

    public function getProfileDefaults(): array
    {
        $defaults = [];
        foreach ($this->publishers as $publisher) {
            $defaults = array_merge($defaults, $publisher->getProfileDefaults());
        }

        return $defaults;
    }

    /**
     * Avatar palette per network for the previews UI.
     *
     * @return array<string, array{background: string, color: string}>
     */
    public function getAvatarPalettes(): array
    {
        $palettes = [];
        foreach ($this->publishers as $publisher) {
            $colors = $publisher->getPreviewAvatarColors();
            $palettes[$publisher->getKey()] = [
                'background' => self::sanitizeHexColor((string) ($colors['background'] ?? ''), '#f0f0f1'),
                'color' => self::sanitizeHexColor((string) ($colors['color'] ?? ''), '#1d2327'),
            ];
        }

        return $palettes;
    }

    /**
     * Avatar colors flow into a JS-rendered style="..." attribute on
     * preview items. Restrict them to short or long hex literals so a
     * third-party publisher registered via the justbee_postcaster_network_publishers
     * filter cannot inject arbitrary CSS.
     */
    private static function sanitizeHexColor(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1 ? $value : $fallback;
    }

    /**
     * @return string[] Full option keys (with network prefix) that hold secret values.
     */
    public function getSecretOptionKeys(): array
    {
        $keys = [];
        foreach ($this->publishers as $publisher) {
            foreach ($publisher->secretFieldKeys() as $suffix) {
                $keys[] = $publisher->optionKey($suffix);
            }
        }

        return $keys;
    }
}
