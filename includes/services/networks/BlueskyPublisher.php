<?php

namespace Justbee\PostCaster\Services\Networks;

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class BlueskyPublisher extends AbstractNetworkPublisher
{
    private BlueskyRecordBuilder $recordBuilder;

    public function __construct(HttpService $http, MediaService $media)
    {
        parent::__construct($http);
        $this->recordBuilder = new BlueskyRecordBuilder($http, $media);
    }

    public function getKey(): string
    {
        return 'bluesky';
    }

    public function getLabel(): string
    {
        return 'Bluesky';
    }

    public function getCharacterLimit(): int
    {
        return 300;
    }

    public function shouldRenderPreviewCard(WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool
    {
        // Show a card preview whenever the user kept {url} in the template,
        // matching Mastodon and LinkedIn. Whether we actually upload the
        // embed at publish time is decided separately based on the
        // include_featured_image option (see publish/publishTest); when
        // that option is off Bluesky may fall back to its built-in URL
        // unfurling, hence getPreviewWarning() flags the uncertainty.
        return $this->postUrlIsPresentInText($post, $text);
    }

    public function shouldAttachAsset(WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): bool
    {
        // The asset only flows through our manually built link-card embed,
        // which we only upload when the publisher will actually render
        // that card (option on + URL in text).
        if (!$includeFeaturedImage) {
            return false;
        }

        return $this->postUrlIsPresentInText($post, $renderedText);
    }

    public function getPreviewWarning(WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): ?string
    {
        if ($includeFeaturedImage) {
            return null;
        }

        if (!$this->postUrlIsPresentInText($post, $renderedText)) {
            return null;
        }

        return __(
            'Bluesky may or may not auto-render this card from the URL. Enable "Let PostCaster include the article card/embed when publishing" to make PostCaster upload the card itself.',
            'postcaster'
        );
    }

    public function getPreviewAvatarColors(): array
    {
        return ['background' => '#e8f1ff', 'color' => '#1d4ed8'];
    }

    public function finalizePostText(WP_Post $post, array $options, string $text, bool $includeFeaturedImage): string
    {
        if (!$includeFeaturedImage) {
            return $text;
        }

        $url = (string) get_permalink($post);
        if ($url === '') {
            return $text;
        }

        $pattern = '/(\n*)' . preg_quote($url, '/') . '(\n*)/';
        $stripped = preg_replace_callback($pattern, static function (array $match): string {
            $count = min(2, max(strlen($match[1] ?? ''), strlen($match[2] ?? '')));

            return str_repeat("\n", $count);
        }, $text);

        return trim((string) $stripped, "\n");
    }

    public function getGlobalDefaults(): array
    {
        return $this->defaultsWithEnabled([
            $this->optionKey('include_featured_image') => '1',
            $this->optionKey('service_url') => 'https://bsky.social',
            $this->optionKey('identifier') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('app_password') => '',
        ]);
    }

    public function secretFieldKeys(): array
    {
        return ['app_password'];
    }

    public function sanitizeGlobal(array $input, array $defaults): array
    {
        $appPassword = $this->persistedSecretField($this->optionKey('app_password'), $input, $defaults);

        return array_merge($this->sanitizeSharedOptions($input, $defaults), [
            $this->optionKey('service_url') => esc_url_raw(trim((string) ($input[$this->optionKey('service_url')] ?? $defaults[$this->optionKey('service_url')]))),
            $this->optionKey('identifier') => sanitize_text_field((string) ($input[$this->optionKey('identifier')] ?? $defaults[$this->optionKey('identifier')] ?? '')),
            $this->optionKey('account_reference') => sanitize_text_field((string) ($input[$this->optionKey('account_reference')] ?? $defaults[$this->optionKey('account_reference')] ?? '')),
            $this->optionKey('app_password') => $appPassword,
        ]);
    }

    public function sanitizeProfile(array $input, array $defaults): array
    {
        $appPassword = $this->persistedSecretField($this->optionKey('app_password'), $input, $defaults);

        return array_merge($this->sanitizeSharedOptions($input, $defaults), [
            $this->optionKey('service_url') => esc_url_raw(trim((string) ($input[$this->optionKey('service_url')] ?? $defaults[$this->optionKey('service_url')] ?? 'https://bsky.social'))),
            $this->optionKey('identifier') => sanitize_text_field((string) ($input[$this->optionKey('identifier')] ?? $defaults[$this->optionKey('identifier')] ?? '')),
            $this->optionKey('account_reference') => sanitize_text_field((string) ($input[$this->optionKey('account_reference')] ?? $defaults[$this->optionKey('account_reference')] ?? '')),
            $this->optionKey('app_password') => $appPassword,
        ]);
    }

    public function mergeProfileIntoOptions(array $globalOptions, array $profile): array
    {
        return $this->mergeProfileValues($globalOptions, $profile, [
            $this->optionKey('template_enabled') => $globalOptions[$this->optionKey('template_enabled')] ?? '0',
            $this->optionKey('template') => $globalOptions[$this->optionKey('template')] ?? '',
            $this->optionKey('include_featured_image') => $globalOptions[$this->optionKey('include_featured_image')] ?? '0',
            $this->optionKey('character_limit') => (string) ($globalOptions[$this->optionKey('character_limit')] ?? $this->getCharacterLimit()),
            $this->optionKey('service_url') => $globalOptions[$this->optionKey('service_url')] ?? 'https://bsky.social',
            $this->optionKey('identifier') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('app_password') => '',
        ]);
    }

    public function getAdminFields(): array
    {
        return [
            $this->enabledAdminField(),
            $this->templateField(),
            $this->includeFeaturedImageField(),
            $this->characterLimitField(),
            $this->urlField($this->optionKey('service_url'), __('Service URL', 'postcaster')),
            $this->textField($this->optionKey('identifier'), __('Identifier', 'postcaster'), [
                'description' => __('For example <login>.bsky.social.', 'postcaster'),
            ]),
            $this->accountReferenceField([
                'description' => __('Used in previews and templates, for example @newsroom.bsky.social.', 'postcaster'),
            ]),
            $this->passwordField(
                $this->optionKey('app_password'),
                __('App password', 'postcaster'),
                __('Leave blank to keep existing app password.', 'postcaster')
            ),
        ];
    }

    public function getProfileFields(): array
    {
        return [
            $this->enabledProfileField(),
            $this->templateField(),
            $this->includeFeaturedImageField(),
            $this->urlField($this->optionKey('service_url'), __('Service URL', 'postcaster'), [
                'placeholder' => 'https://bsky.social',
            ]),
            $this->textField($this->optionKey('identifier'), __('Identifier', 'postcaster'), [
                'placeholder' => '<login>.bsky.social',
            ]),
            $this->accountReferenceField([
                'placeholder' => '@newsroom.bsky.social',
            ]),
            $this->passwordField(
                $this->optionKey('app_password'),
                __('App password', 'postcaster'),
                __('Leave blank to keep existing app password.', 'postcaster')
            ),
        ];
    }

    public function getSetupNotice(): ?array
    {
        return [
            'title' => __('Bluesky setup', 'postcaster'),
            'steps' => [
                __('Log in to the Bluesky account that should publish posts.', 'postcaster'),
                __('In Bluesky, open Settings and then App passwords (sometimes under Privacy and Security).', 'postcaster'),
                __('Create a new app password for PostCaster, give it a recognizable name, and copy it immediately because Bluesky only shows it once.', 'postcaster'),
                __('Fill in Service URL with usually https://bsky.social.', 'postcaster'),
                __('Fill in Identifier with the account handle, for example <login>.bsky.social.', 'postcaster'),
                __('Paste the copied app password into the PostCaster field App password, save the settings, and send a test post.', 'postcaster'),
            ],
            'note' => __('Note: Bluesky images must stay below roughly 1 MB after processing, otherwise image upload can fail.', 'postcaster'),
        ];
    }

    public function getAccountReference(array $options): string
    {
        $reference = parent::getAccountReference($options);

        if ($reference === '') {
            $reference = trim((string) ($options[$this->optionKey('identifier')] ?? ''));
        }

        return $reference;
    }

    public function isConfigured(array $options): bool
    {
        return trim((string) ($options[$this->optionKey('identifier')] ?? '')) !== ''
            && trim((string) ($options[$this->optionKey('app_password')] ?? '')) !== '';
    }

    public function formatAccountReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        return str_starts_with($reference, '@') ? $reference : '@' . ltrim($reference, '@');
    }


    public function publish(WP_Post $post, array $options, ?array $asset, string $text)
    {
        if ($options[$this->optionKey('identifier')] === '' || $options[$this->optionKey('app_password')] === '') {
            return new WP_Error('justbee_postcaster_bluesky_config', __('Bluesky credentials are missing.', 'postcaster'));
        }

        $serviceUrl = untrailingslashit($options[$this->optionKey('service_url')]);
        $session = $this->http->jsonPost($serviceUrl . '/xrpc/com.atproto.server.createSession', [
            'identifier' => $options[$this->optionKey('identifier')],
            'password' => $options[$this->optionKey('app_password')],
        ]);
        if (is_wp_error($session)) {
            return $session;
        }

        $renderCard = ($options[$this->optionKey('include_featured_image')] ?? '0') === '1';
        $record = $this->recordBuilder->buildRecord(
            $post,
            $serviceUrl,
            (string) $session['accessJwt'],
            $text,
            $renderCard,
            $asset,
            is_array($options['justbee_postcaster_placeholder_mentions'] ?? null) ? $options['justbee_postcaster_placeholder_mentions'] : []
        );

        // Fallback: when the card was supposed to render but the embed
        // could not be assembled (thumb upload failed, or no permalink),
        // restore {url} to the text and post a plain text record so the
        // article still has a clickable reference on the timeline.
        if ($renderCard && (is_wp_error($record) || !isset($record['embed']))) {
            $text = $this->ensureUrlInText($post, $text);
            $record = $this->recordBuilder->buildRecord(
                $post,
                $serviceUrl,
                (string) $session['accessJwt'],
                $text,
                false,
                null,
                is_array($options['justbee_postcaster_placeholder_mentions'] ?? null) ? $options['justbee_postcaster_placeholder_mentions'] : []
            );
        }

        if (is_wp_error($record)) {
            return $record;
        }

        $createRecord = $this->http->jsonPost(
            $serviceUrl . '/xrpc/com.atproto.repo.createRecord',
            [
                'repo' => $session['did'],
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ],
            ['Authorization' => 'Bearer ' . $session['accessJwt']]
        );
        if (is_wp_error($createRecord)) {
            return $createRecord;
        }

        $uri = (string) ($createRecord['uri'] ?? '');

        return [
            'id' => $uri,
            'url' => $this->buildPostUrl($uri, (string) ($options[$this->optionKey('identifier')] ?? '')),
        ];
    }

    public function publishTest(array $options, string $text, array $context = [])
    {
        if ($options[$this->optionKey('identifier')] === '' || $options[$this->optionKey('app_password')] === '') {
            return new WP_Error('justbee_postcaster_bluesky_config', __('Bluesky credentials are missing.', 'postcaster'));
        }

        $serviceUrl = untrailingslashit($options[$this->optionKey('service_url')]);
        $session = $this->http->jsonPost($serviceUrl . '/xrpc/com.atproto.server.createSession', [
            'identifier' => $options[$this->optionKey('identifier')],
            'password' => $options[$this->optionKey('app_password')],
        ]);
        if (is_wp_error($session)) {
            return $session;
        }

        $examplePost = $context['post'] ?? null;
        $asset = is_array($context['asset'] ?? null) ? $context['asset'] : null;
        $includeFeaturedImage = !empty($context['include_featured_image']);

        if ($examplePost instanceof WP_Post) {
            $text = $this->finalizePostText($examplePost, $options, $text, $includeFeaturedImage);
            $record = $this->recordBuilder->buildRecord(
                $examplePost,
                $serviceUrl,
                (string) $session['accessJwt'],
                $text,
                $includeFeaturedImage,
                $includeFeaturedImage ? $asset : null,
                is_array($context['placeholder_mentions'] ?? null) ? $context['placeholder_mentions'] : []
            );

            if ($includeFeaturedImage && (is_wp_error($record) || !isset($record['embed']))) {
                $text = $this->ensureUrlInText($examplePost, $text);
                $record = $this->recordBuilder->buildRecord(
                    $examplePost,
                    $serviceUrl,
                    (string) $session['accessJwt'],
                    $text,
                    false,
                    null,
                    is_array($context['placeholder_mentions'] ?? null) ? $context['placeholder_mentions'] : []
                );
            }

            if (is_wp_error($record)) {
                return $record;
            }
        } else {
            $record = [
                '$type' => 'app.bsky.feed.post',
                'text' => $text,
                'createdAt' => gmdate('c'),
            ];
        }

        $createRecord = $this->http->jsonPost(
            $serviceUrl . '/xrpc/com.atproto.repo.createRecord',
            [
                'repo' => $session['did'],
                'collection' => 'app.bsky.feed.post',
                'record' => $record,
            ],
            ['Authorization' => 'Bearer ' . $session['accessJwt']]
        );
        if (is_wp_error($createRecord)) {
            return $createRecord;
        }

        $uri = (string) ($createRecord['uri'] ?? '');

        return [
            'id' => $uri,
            'url' => $this->buildPostUrl($uri, (string) ($options[$this->optionKey('identifier')] ?? '')),
        ];
    }

    /**
     * Turn an at:// record URI into the public https://bsky.app/... URL.
     * Format: at://{did}/app.bsky.feed.post/{rkey} → https://bsky.app/profile/{handle}/post/{rkey}
     */
    private function buildPostUrl(string $uri, string $identifier): string
    {
        if ($uri === '' || $identifier === '') {
            return '';
        }

        $parts = explode('/', $uri);
        $rkey = (string) end($parts);
        if ($rkey === '' || $rkey === $uri) {
            return '';
        }

        return 'https://bsky.app/profile/' . rawurlencode($identifier) . '/post/' . rawurlencode($rkey);
    }

    /**
     * Restore the post permalink to a text from which it was previously
     * stripped, so a card-less post still carries a clickable reference.
     */
    private function ensureUrlInText(WP_Post $post, string $text): string
    {
        $url = get_permalink($post);
        if (!is_string($url) || $url === '') {
            return $text;
        }

        if (str_contains($text, $url)) {
            return $text;
        }

        $text = trim($text);
        return $text === '' ? $url : $text . "\n\n" . $url;
    }
}
