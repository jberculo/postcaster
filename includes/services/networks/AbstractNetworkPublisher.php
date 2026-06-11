<?php

namespace Justbee\PostCaster\Services\Networks;

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Support\NormalizesTemplate;
use Justbee\PostCaster\Support\SecretsCipher;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractNetworkPublisher implements NetworkPublisherInterface
{
    use NormalizesTemplate;

    protected const MEDIA_STATUS_POLL_ATTEMPTS = 10;
    protected const MEDIA_STATUS_POLL_DELAY_MICROSECONDS = 1000000;

    protected HttpService $http;

    public function __construct(HttpService $http)
    {
        $this->http = $http;
    }

    public function sanitizeProfile(array $input, array $defaults): array
    {
        return $this->sanitizeGlobal($input, $defaults);
    }

    public function secretFieldKeys(): array
    {
        return [];
    }

    final public function optionKey(string $suffix): string
    {
        return sanitize_key($this->getKey()) . '_' . sanitize_key($suffix);
    }

    public function getProfileDefaults(): array
    {
        return $this->getGlobalDefaults();
    }

    public function getSetupNotice(): ?array
    {
        return null;
    }

    public function getAccountReference(array $options): string
    {
        return trim((string) ($options[$this->optionKey('account_reference')] ?? ''));
    }

    public function formatAccountReference(string $reference): string
    {
        return trim($reference);
    }

    public function preparePostText(\WP_Post $post, array $options, string $text): string
    {
        return $text;
    }

    public function finalizePostText(\WP_Post $post, array $options, string $text, bool $includeFeaturedImage): string
    {
        return $text;
    }

    public function shouldRenderPreviewCard(\WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool
    {
        return false;
    }

    public function getPreviewWarning(\WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): ?string
    {
        return null;
    }

    /**
     * Helper for networks that show a card only when the post URL is in the rendered text.
     */
    final protected function postUrlIsPresentInText(\WP_Post $post, string $text): bool
    {
        $url = get_permalink($post);

        return is_string($url) && $url !== '' && str_contains($text, $url);
    }

    public function shouldAttachAsset(\WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): bool
    {
        return $includeFeaturedImage;
    }

    public function getPreviewAvatarColors(): array
    {
        return ['background' => '#eef2f7', 'color' => '#334155'];
    }

    protected function defaultsWithEnabled(array $defaults): array
    {
        return array_merge([
            $this->optionKey('enabled') => '0',
            $this->optionKey('template_enabled') => '0',
            $this->optionKey('template') => '',
            $this->optionKey('include_featured_image') => '0',
            $this->optionKey('character_limit') => (string) $this->getCharacterLimit(),
        ], $defaults);
    }

    protected function sanitizeEnabled(array $input, array $defaults): string
    {
        if (!array_key_exists($this->optionKey('enabled'), $input)) {
            return (string) ($defaults[$this->optionKey('enabled')] ?? '0');
        }

        return !empty($input[$this->optionKey('enabled')]) ? '1' : '0';
    }

    protected function sanitizeIncludeFeaturedImage(array $input, array $defaults): string
    {
        if (!array_key_exists($this->optionKey('include_featured_image'), $input)) {
            return (string) ($defaults[$this->optionKey('include_featured_image')] ?? '0');
        }

        return !empty($input[$this->optionKey('include_featured_image')]) ? '1' : '0';
    }

    protected function sanitizeTemplateEnabled(array $input, array $defaults): string
    {
        if (!array_key_exists($this->optionKey('template_enabled'), $input)) {
            return (string) ($defaults[$this->optionKey('template_enabled')] ?? '0');
        }

        return !empty($input[$this->optionKey('template_enabled')]) ? '1' : '0';
    }

    protected function sanitizeTemplate(array $input, array $defaults, string $fallback = ''): string
    {
        $template = $this->normalizeTemplate(sanitize_textarea_field((string) ($input[$this->optionKey('template')] ?? $defaults[$this->optionKey('template')] ?? '')));

        if ($template === '' && $fallback !== '') {
            return $fallback;
        }

        return $template;
    }

    protected function sanitizeCharacterLimit(array $input, array $defaults): string
    {
        $rawValue = array_key_exists($this->optionKey('character_limit'), $input)
            ? wp_unslash((string) $input[$this->optionKey('character_limit')])
            : (string) ($defaults[$this->optionKey('character_limit')] ?? $this->getCharacterLimit());
        $limit = (int) $rawValue;

        if ($limit <= 0) {
            $limit = $this->getCharacterLimit();
        }

        return (string) $limit;
    }

    protected function sanitizeSharedOptions(array $input, array $defaults): array
    {
        $templateEnabled = $this->sanitizeTemplateEnabled($input, $defaults);
        $fallbackTemplate = (string) ($defaults['template_effective'] ?? '');
        $template = $this->sanitizeTemplate($input, $defaults, $fallbackTemplate);

        return [
            $this->optionKey('enabled') => $this->sanitizeEnabled($input, $defaults),
            $this->optionKey('template_enabled') => $templateEnabled,
            $this->optionKey('template') => $template,
            $this->optionKey('include_featured_image') => $this->sanitizeIncludeFeaturedImage($input, $defaults),
            $this->optionKey('character_limit') => $this->sanitizeCharacterLimit($input, $defaults),
        ];
    }

    protected function mergeProfileValues(array $globalOptions, array $profile, array $keys): array
    {
        $globalOptions[$this->optionKey('enabled')] = '1';

        foreach ($keys as $key => $fallback) {
            if (is_int($key)) {
                $key = $fallback;
                $fallback = $globalOptions[$key] ?? '';
            }

            $globalOptions[$key] = $profile[$key] ?? $fallback;
        }

        return $globalOptions;
    }

    protected function persistedTextField(string $key, array $input, array $defaults): string
    {
        $value = array_key_exists($key, $input)
            ? sanitize_text_field((string) $input[$key])
            : (string) ($defaults[$key] ?? '');

        if ($value === '' && !empty($defaults[$key])) {
            $value = (string) $defaults[$key];
        }

        return $value;
    }

    protected function persistedSecretField(string $key, array $input, array $defaults): string
    {
        $existing = (string) ($defaults[$key] ?? '');

        if (array_key_exists($key, $input)) {
            $newValue = sanitize_text_field((string) $input[$key]);
            if ($newValue !== '') {
                $cipher = SecretsCipher::encrypt($newValue);
                if ($cipher !== null) {
                    return $cipher;
                }
                // Encryption unavailable: never persist plaintext. Keep
                // existing ciphertext; drop existing plaintext defensively.
                return SecretsCipher::isEncrypted($existing) ? $existing : '';
            }
        }

        if ($existing === '') {
            return '';
        }

        if (SecretsCipher::isEncrypted($existing)) {
            return $existing;
        }

        // Legacy plaintext on disk: encrypt on next save when possible,
        // otherwise drop it rather than rewriting plaintext.
        $cipher = SecretsCipher::encrypt($existing);
        return $cipher ?? '';
    }

    protected function enabledAdminField(): array
    {
        return [
            'key' => $this->optionKey('enabled'),
            'label' => __('Active', 'postcaster'),
            'type' => 'checkbox',
            'description' => __('Enable', 'postcaster'),
        ];
    }

    protected function textField(string $key, string $label, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => 'text',
        ], $extra);
    }

    protected function urlField(string $key, string $label, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => 'url',
        ], $extra);
    }

    protected function passwordField(string $key, string $label, string $placeholder): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => 'password',
            'placeholder' => $placeholder,
            'placeholder_empty' => __('No value stored yet.', 'postcaster'),
            'placeholder_filled' => __('A value is already stored. Enter a new one to replace it.', 'postcaster'),
        ];
    }

    protected function selectField(string $key, string $label, array $options, array $extra = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'options' => $options,
        ], $extra);
    }

    protected function enabledProfileField(): array
    {
        return [
            'key' => $this->optionKey('enabled'),
            'label' => __('Active', 'postcaster'),
            'type' => 'checkbox',
        ];
    }

    protected function includeFeaturedImageField(): array
    {
        return [
            'key' => $this->optionKey('include_featured_image'),
            'label' => __('Card/embed', 'postcaster'),
            'type' => 'checkbox',
            'description' => __('Let PostCaster include the article card/embed when publishing', 'postcaster'),
        ];
    }

    protected function templateField(): array
    {
        return [
            'key' => $this->optionKey('template'),
            'label' => __('Use a custom template', 'postcaster'),
            'type' => 'textarea',
            'rows' => 5,
            'toggle' => $this->optionKey('template_enabled'),
            'template_help' => true,
        ];
    }

    protected function characterLimitField(): array
    {
        return [
            'key' => $this->optionKey('character_limit'),
            'label' => __('Character limit', 'postcaster'),
            'type' => 'number',
            'description' => sprintf(
                /* translators: %d: default character limit for the network. */
                __('Default: %d characters.', 'postcaster'),
                $this->getCharacterLimit()
            ),
            'small' => true,
            'min' => 1,
            'step' => 1,
        ];
    }

    protected function accountReferenceField(array $extra = []): array
    {
        return $this->textField($this->optionKey('account_reference'), __('Account reference', 'postcaster'), $extra);
    }

}
