<?php

namespace Justbee\PostCaster\Services\Networks;

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class MastodonPublisher extends AbstractNetworkPublisher
{
    private MediaService $media;

    public function __construct(HttpService $http, MediaService $media)
    {
        parent::__construct($http);
        $this->media = $media;
    }

    public function getKey(): string
    {
        return 'mastodon';
    }

    public function getLabel(): string
    {
        return 'Mastodon';
    }

    public function getCharacterLimit(): int
    {
        return 500;
    }

    public function getPreviewAvatarColors(): array
    {
        return ['background' => '#f4f0ff', 'color' => '#5b21b6'];
    }

    public function shouldRenderPreviewCard(WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool
    {
        return $this->postUrlIsPresentInText($post, $text);
    }

    public function getGlobalDefaults(): array
    {
        return $this->defaultsWithEnabled([
            $this->optionKey('base_url') => '',
            $this->optionKey('access_token') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('visibility') => 'public',
        ]);
    }

    public function secretFieldKeys(): array
    {
        return ['access_token'];
    }

    public function sanitizeGlobal(array $input, array $defaults): array
    {
        $visibility = sanitize_key($input[$this->optionKey('visibility')] ?? $defaults[$this->optionKey('visibility')]);
        $accessToken = $this->persistedSecretField($this->optionKey('access_token'), $input, $defaults);

        return array_merge($this->sanitizeSharedOptions($input, $defaults), [
            $this->optionKey('base_url') => esc_url_raw(trim((string) ($input[$this->optionKey('base_url')] ?? $defaults[$this->optionKey('base_url')] ?? ''))),
            $this->optionKey('access_token') => $accessToken,
            $this->optionKey('account_reference') => sanitize_text_field((string) ($input[$this->optionKey('account_reference')] ?? $defaults[$this->optionKey('account_reference')] ?? '')),
            $this->optionKey('visibility') => in_array($visibility, ['public', 'unlisted', 'private'], true) ? $visibility : 'public',
        ]);
    }

    public function mergeProfileIntoOptions(array $globalOptions, array $profile): array
    {
        return $this->mergeProfileValues($globalOptions, $profile, [
            $this->optionKey('template_enabled') => $globalOptions[$this->optionKey('template_enabled')] ?? '0',
            $this->optionKey('template') => $globalOptions[$this->optionKey('template')] ?? '',
            $this->optionKey('include_featured_image') => $globalOptions[$this->optionKey('include_featured_image')] ?? '0',
            $this->optionKey('character_limit') => (string) ($globalOptions[$this->optionKey('character_limit')] ?? $this->getCharacterLimit()),
            $this->optionKey('base_url') => '',
            $this->optionKey('access_token') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('visibility') => 'public',
        ]);
    }

    public function getAdminFields(): array
    {
        return [
            $this->enabledAdminField(),
            $this->templateField(),
            $this->characterLimitField(),
            $this->urlField($this->optionKey('base_url'), __('Base URL', 'postcaster'), [
                'description' => __('For example https://mastodon.social.', 'postcaster'),
            ]),
            $this->passwordField(
                $this->optionKey('access_token'),
                __('Access token', 'postcaster'),
                __('Leave blank to keep existing access token.', 'postcaster')
            ),
            $this->accountReferenceField([
                'description' => __('Used in previews and templates, for example @newsroom@mastodon.social.', 'postcaster'),
            ]),
            $this->selectField($this->optionKey('visibility'), __('Visibility', 'postcaster'), $this->getVisibilityOptions()),
        ];
    }

    public function getProfileFields(): array
    {
        return [
            $this->enabledProfileField(),
            $this->templateField(),
            $this->urlField($this->optionKey('base_url'), __('Base URL', 'postcaster'), [
                'placeholder' => 'https://mastodon.social',
            ]),
            $this->passwordField(
                $this->optionKey('access_token'),
                __('Access token', 'postcaster'),
                __('Leave blank to keep existing access token.', 'postcaster')
            ),
            $this->accountReferenceField([
                'placeholder' => '@newsroom@mastodon.social',
            ]),
            $this->selectField($this->optionKey('visibility'), __('Visibility', 'postcaster'), $this->getVisibilityOptions()),
        ];
    }

    public function getSetupNotice(): ?array
    {
        return [
            'title' => __('Mastodon setup', 'postcaster'),
            'steps' => [
                __('Log in to the Mastodon account that should publish posts.', 'postcaster'),
                __('In Mastodon, open Preferences or Settings, go to Development, and create a new application or access token for PostCaster.', 'postcaster'),
                __('When creating the token, allow posting statuses and uploading media. On some servers this is write:statuses and write:media; on others a broader write scope is used.', 'postcaster'),
                __('Fill in Base URL with the full server address only, for example https://mastodon.social, without your profile path.', 'postcaster'),
                __('Paste the copied token into the PostCaster field Access token and choose the desired Visibility.', 'postcaster'),
                __('Save the settings and send a test post.', 'postcaster'),
            ],
            'note' => __('The token must be allowed to publish posts and upload media if you want featured images included.', 'postcaster'),
        ];
    }

    private function getVisibilityOptions(): array
    {
        return [
            'public' => __('Public', 'postcaster'),
            'unlisted' => __('Unlisted', 'postcaster'),
            'private' => __('Followers-only', 'postcaster'),
        ];
    }

    public function isConfigured(array $options): bool
    {
        return trim((string) ($options[$this->optionKey('base_url')] ?? '')) !== ''
            && trim((string) ($options[$this->optionKey('access_token')] ?? '')) !== '';
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
        if ($options[$this->optionKey('base_url')] === '' || $options[$this->optionKey('access_token')] === '') {
            return new WP_Error('justbee_postcaster_mastodon_config', __('Mastodon credentials are missing.', 'postcaster'));
        }

        $baseUrl = untrailingslashit($options[$this->optionKey('base_url')]);
        $mediaId = null;

        if ($asset) {
            $prepared = $this->media->readImageBytes(
                $asset,
                'justbee_postcaster_mastodon_media',
                __('Could not read Mastodon image.', 'postcaster')
            );
            if (is_wp_error($prepared)) {
                return $prepared;
            }

            $uploadData = $this->http->multipartPost(
                $baseUrl . '/api/v2/media',
                [
                    'file' => [
                        'filename' => basename($asset['path']),
                        'type' => $prepared['mime'],
                        'contents' => $prepared['bytes'],
                    ],
                    'description' => $asset['alt'],
                ],
                ['Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')]]
            );

            if (is_wp_error($uploadData)) {
                return $uploadData;
            }

            $mediaId = $uploadData['id'] ?? null;
            if ($mediaId) {
                // Short polling (4×500ms = 2s). Slower Mastodon processing falls
                // through to the background retry handled by Action Scheduler.
                $mediaReady = false;

                for ($i = 0; $i < self::MEDIA_STATUS_POLL_ATTEMPTS; $i++) {
                    $statusResponse = wp_remote_get($baseUrl . '/api/v1/media/' . rawurlencode((string) $mediaId), [
                        'timeout' => 30,
                        'headers' => ['Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')]],
                    ]);

                    $statusData = $this->http->decodeJsonResponse($statusResponse, __('Mastodon media upload failed.', 'postcaster'));
                    if (is_wp_error($statusData)) {
                        return $statusData;
                    }

                    $processingState = (string) ($statusData['processing'] ?? '');
                    if ($processingState === '' || $processingState === 'succeeded') {
                        $mediaReady = true;
                        break;
                    }

                    if ($processingState === 'failed') {
                        return new WP_Error(
                            'justbee_postcaster_mastodon_processing',
                            __('Mastodon media processing failed.', 'postcaster'),
                            ['retryable' => false]
                        );
                    }

                    usleep(self::MEDIA_STATUS_POLL_DELAY_MICROSECONDS);
                }

                if (!$mediaReady) {
                    return new WP_Error(
                        'justbee_postcaster_mastodon_processing',
                        __('Mastodon media still processing; will retry in the background queue.', 'postcaster'),
                        ['retryable' => true]
                    );
                }
            }
        }

        $body = [
            'status' => $this->preparePostText($post, $options, $text),
            'visibility' => $options[$this->optionKey('visibility')],
            'language' => substr(get_bloginfo('language'), 0, 2),
        ];
        if ($mediaId) {
            $body['media_ids[]'] = $mediaId;
        }

        $response = wp_remote_post($baseUrl . '/api/v1/statuses', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')]],
            'body' => $body,
        ]);

        $statusData = $this->http->decodeJsonResponse($response, __('Mastodon status post failed.', 'postcaster'));
        if (is_wp_error($statusData)) {
            return $statusData;
        }

        return [
            'id' => (string) ($statusData['id'] ?? ''),
            'url' => (string) ($statusData['url'] ?? ''),
        ];
    }


    public function publishTest(array $options, string $text, array $context = [])
    {
        if ($options[$this->optionKey('base_url')] === '' || $options[$this->optionKey('access_token')] === '') {
            return new WP_Error('justbee_postcaster_mastodon_config', __('Mastodon credentials are missing.', 'postcaster'));
        }

        $baseUrl = untrailingslashit($options[$this->optionKey('base_url')]);
        $response = wp_remote_post($baseUrl . '/api/v1/statuses', [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')]],
            'body' => [
                'status' => $text,
                'visibility' => $options[$this->optionKey('visibility')],
                'language' => substr(get_bloginfo('language'), 0, 2),
            ],
        ]);

        $statusData = $this->http->decodeJsonResponse($response, __('Mastodon status post failed.', 'postcaster'));
        if (is_wp_error($statusData)) {
            return $statusData;
        }

        return [
            'id' => (string) ($statusData['id'] ?? ''),
            'url' => (string) ($statusData['url'] ?? ''),
        ];
    }
}
