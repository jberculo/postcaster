<?php

namespace Justbee\PostCaster\Models;

use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Support\NormalizesTemplate;
use Justbee\PostCaster\Support\SecretsCipher;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsModel
{
    use NormalizesTemplate;

    public const OPTION_NAME = 'justbee_postcaster_options';
    private const DEFAULT_TEMPLATE = "{title}\n\n{url}";

    private NetworkRegistry $networks;
    private bool $hasSecretDecryptionFailures = false;

    public function __construct(NetworkRegistry $networks)
    {
        $this->networks = $networks;
    }

    public static function activate(): void
    {
        if (!get_option(self::OPTION_NAME)) {
            add_option(self::OPTION_NAME, [
                'enabled' => '0',
                'personal_networks_enabled' => '1',
                'debug' => '0',
                'post_types' => ['post'],
                'template_enabled' => '0',
                'template' => self::DEFAULT_TEMPLATE,
            ]);
        }
    }

    public function defaults(): array
    {
        $defaults = [
            'enabled' => '0',
            'personal_networks_enabled' => '1',
            'debug' => '0',
            'post_types' => ['post'],
            'template_enabled' => '0',
            'template' => self::DEFAULT_TEMPLATE,
        ];

        foreach ($this->networks->keys() as $networkKey) {
            $defaults[$this->personalAvailabilityKey($networkKey)] = '1';
        }

        return array_merge($defaults, $this->networks->getGlobalDefaults());
    }

    public function getDefaultTemplate(): string
    {
        return self::DEFAULT_TEMPLATE;
    }

    public function getEffectiveGeneralTemplate(?array $options = null): string
    {
        $options = $options ?? $this->get();
        $template = trim((string) ($options['template'] ?? ''));

        if (($options['template_enabled'] ?? '0') === '1' && $template !== '') {
            return $template;
        }

        return self::DEFAULT_TEMPLATE;
    }

    public function getEffectiveNetworkTemplate(string $networkKey, ?array $options = null): string
    {
        $options = $options ?? $this->get();
        $network = $this->networks->get($networkKey);
        if ($network) {
            $template = trim((string) ($options[$network->optionKey('template')] ?? ''));
            if (($options[$network->optionKey('template_enabled')] ?? '0') === '1' && $template !== '') {
                return $template;
            }
        }

        return $this->getEffectiveGeneralTemplate($options);
    }

    public function personalAvailabilityKey(string $networkKey): string
    {
        return 'personal_network_available_' . sanitize_key($networkKey);
    }

    public function isPersonalNetworkAvailable(string $networkKey, ?array $options = null): bool
    {
        $options = $options ?? $this->get();

        return ($options[$this->personalAvailabilityKey($networkKey)] ?? '1') === '1';
    }

    public function getAvailablePersonalNetworks(?array $options = null): array
    {
        $options = $options ?? $this->get();

        return array_filter(
            $this->networks->all(),
            fn ($publisher): bool => $this->isPersonalNetworkAvailable($publisher->getKey(), $options)
        );
    }

    public function get(): array
    {
        $this->hasSecretDecryptionFailures = false;
        $stored = get_option(self::OPTION_NAME, []);
        $options = wp_parse_args($stored, $this->defaults());

        if (
            is_array($stored)
            && !array_key_exists('template_enabled', $stored)
            && (string) ($options['template'] ?? '') !== self::DEFAULT_TEMPLATE
        ) {
            $options['template_enabled'] = '1';
        }

        foreach ($this->networks->getSecretOptionKeys() as $secretKey) {
            if (isset($options[$secretKey]) && is_string($options[$secretKey])) {
                $options[$secretKey] = $this->decryptSecretValue((string) $options[$secretKey]);
            }
        }

        return $options;
    }

    public function hasSecretDecryptionFailures(): bool
    {
        return $this->hasSecretDecryptionFailures;
    }

    public function update(array $changes): array
    {
        $options = wp_parse_args($changes, $this->get());
        $sanitized = $this->sanitize($options);

        update_option(self::OPTION_NAME, $sanitized);

        return $sanitized;
    }

    /**
     * Sanitize the full PostCaster global settings array for the Settings API.
     *
     * Every supported field is normalized with an appropriate WordPress core
     * sanitizer before storage. This includes sanitize_key() for slugs and
     * post types, sanitize_text_field() for short text fields,
     * sanitize_textarea_field() for templates, and esc_url_raw() for service
     * URLs. Secret fields are sanitized first and then persisted through the
     * encryption helper so no plaintext secrets are stored.
     *
     * @param mixed $input Raw option payload coming from register_setting().
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $defaults = $this->get();
        // Replace decrypted secret values with their raw stored ciphertext so that
        // sanitize callbacks falling through to defaults pass the existing ciphertext
        // through verbatim instead of re-encrypting it with a fresh nonce.
        $rawStored = get_option(self::OPTION_NAME, []);
        if (is_array($rawStored)) {
            foreach ($this->networks->getSecretOptionKeys() as $secretKey) {
                if (array_key_exists($secretKey, $rawStored)) {
                    $defaults[$secretKey] = $rawStored[$secretKey];
                }
            }
        }
        $input = is_array($input) ? $input : [];
        $output = $defaults;

        foreach (['enabled', 'personal_networks_enabled', 'debug'] as $key) {
            if (array_key_exists($key, $input)) {
                $output[$key] = !empty($input[$key]) ? '1' : '0';
            }
        }

        foreach ($this->networks->keys() as $networkKey) {
            $availabilityKey = $this->personalAvailabilityKey($networkKey);

            if (array_key_exists($availabilityKey, $input)) {
                $output[$availabilityKey] = !empty($input[$availabilityKey]) ? '1' : '0';
            }
        }

        $allowedPostTypes = array_keys(\Justbee\PostCaster\Controllers\AdminController::getSelectablePostTypes());
        if (array_key_exists('post_types', $input)) {
            $postTypes = is_array($input['post_types'] ?? null) ? $input['post_types'] : [];
            $postTypes = array_values(array_unique(array_filter(array_map(
                static fn($postType): string => sanitize_key((string) $postType),
                $postTypes
            ))));
            $postTypes = array_values(array_intersect($postTypes, $allowedPostTypes));
            $output['post_types'] = $postTypes !== [] ? $postTypes : ['post'];
        }

        if (array_key_exists('template_enabled', $input)) {
            $output['template_enabled'] = !empty($input['template_enabled']) ? '1' : '0';
        }

        if (array_key_exists('template', $input)) {
            $output['template'] = $this->normalizeTemplate((string) sanitize_textarea_field($input['template'] ?? $defaults['template']));
        }

        if (
            array_key_exists('template_enabled', $input)
            && $output['template_enabled'] === '1'
            && trim((string) $output['template']) === ''
        ) {
            $output['template'] = self::DEFAULT_TEMPLATE;
        }

        $globalTemplateDefaults = array_merge($defaults, [
            'template_effective' => $this->getEffectiveGeneralTemplate($output),
        ]);

        foreach ($this->networks->all() as $publisher) {
            $output = array_merge($output, $publisher->sanitizeGlobal($input, $globalTemplateDefaults));
        }

        return $output;
    }

    private function decryptSecretValue(string $value): string
    {
        $decrypted = SecretsCipher::tryDecrypt($value);
        if ($decrypted !== null) {
            return $decrypted;
        }

        if (SecretsCipher::isEncrypted($value)) {
            $this->hasSecretDecryptionFailures = true;
            return '';
        }

        return $value;
    }

}
