<?php

namespace Justbee\PostCaster\Services\Networks;

use Justbee\PostCaster\Services\HttpService;
use Justbee\PostCaster\Services\MediaService;
use WP_Error;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class LinkedInPublisher extends AbstractNetworkPublisher
{
    private MediaService $media;

    public function __construct(HttpService $http, MediaService $media)
    {
        parent::__construct($http);
        $this->media = $media;
    }

    public function getKey(): string
    {
        return 'linkedin';
    }

    public function getLabel(): string
    {
        return 'LinkedIn';
    }

    public function getCharacterLimit(): int
    {
        return 3000;
    }

    public function getPreviewAvatarColors(): array
    {
        return ['background' => '#e8f0ff', 'color' => '#0a66c2'];
    }

    public function shouldRenderPreviewCard(WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool
    {
        return $this->postUrlIsPresentInText($post, $text);
    }

    public function getGlobalDefaults(): array
    {
        return $this->defaultsWithEnabled([
            $this->optionKey('access_token') => '',
            $this->optionKey('author_urn') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('version') => gmdate('Ym'),
        ]);
    }

    public function secretFieldKeys(): array
    {
        return ['access_token'];
    }

    public function sanitizeGlobal(array $input, array $defaults): array
    {
        $version = preg_replace('/[^0-9]/', '', (string) ($input[$this->optionKey('version')] ?? $defaults[$this->optionKey('version')]));
        $accessToken = $this->persistedSecretField($this->optionKey('access_token'), $input, $defaults);

        return array_merge($this->sanitizeSharedOptions($input, $defaults), [
            $this->optionKey('access_token') => $accessToken,
            $this->optionKey('author_urn') => sanitize_text_field((string) ($input[$this->optionKey('author_urn')] ?? $defaults[$this->optionKey('author_urn')] ?? '')),
            $this->optionKey('account_reference') => sanitize_text_field((string) ($input[$this->optionKey('account_reference')] ?? $defaults[$this->optionKey('account_reference')] ?? '')),
            $this->optionKey('version') => strlen($version) === 6 ? $version : $defaults[$this->optionKey('version')],
        ]);
    }

    public function mergeProfileIntoOptions(array $globalOptions, array $profile): array
    {
        return $this->mergeProfileValues($globalOptions, $profile, [
            $this->optionKey('template_enabled') => $globalOptions[$this->optionKey('template_enabled')] ?? '0',
            $this->optionKey('template') => $globalOptions[$this->optionKey('template')] ?? '',
            $this->optionKey('include_featured_image') => $globalOptions[$this->optionKey('include_featured_image')] ?? '0',
            $this->optionKey('character_limit') => (string) ($globalOptions[$this->optionKey('character_limit')] ?? $this->getCharacterLimit()),
            $this->optionKey('access_token') => '',
            $this->optionKey('author_urn') => '',
            $this->optionKey('account_reference') => '',
            $this->optionKey('version') => gmdate('Ym'),
        ]);
    }

    public function getAdminFields(): array
    {
        return [
            $this->enabledAdminField(),
            $this->templateField(),
            $this->characterLimitField(),
            $this->passwordField(
                $this->optionKey('access_token'),
                __('Access token', 'postcaster'),
                __('Leave blank to keep existing access token.', 'postcaster')
            ),
            $this->textField($this->optionKey('author_urn'), __('Author URN', 'postcaster'), [
                /* translators: Example LinkedIn author URNs. */
                'description' => __('For example urn:li:organization:123456 or urn:li:person:abcdef.', 'postcaster'),
            ]),
            $this->accountReferenceField([
                'description' => __('Used in previews and templates, for example @newsroom or Newsroom Organization.', 'postcaster'),
            ]),
            $this->textField($this->optionKey('version'), __('LinkedIn version', 'postcaster'), [
                /* translators: Example LinkedIn API version format. */
                'description' => __('Format YYYYMM.', 'postcaster'),
                'small' => true,
            ]),
        ];
    }

    public function getProfileFields(): array
    {
        return [
            $this->enabledProfileField(),
            $this->templateField(),
            $this->passwordField(
                $this->optionKey('access_token'),
                __('Access token', 'postcaster'),
                __('Leave blank to keep existing access token.', 'postcaster')
            ),
            $this->textField($this->optionKey('author_urn'), __('Author URN', 'postcaster'), [
                'placeholder' => 'urn:li:organization:123456',
            ]),
            $this->accountReferenceField([
                'placeholder' => '@newsroom',
            ]),
            $this->textField($this->optionKey('version'), __('LinkedIn version', 'postcaster'), [
                'placeholder' => 'YYYYMM',
                'small' => true,
            ]),
        ];
    }

    public function getAccountReference(array $options): string
    {
        $reference = parent::getAccountReference($options);

        if ($reference === '') {
            $reference = trim((string) ($options[$this->optionKey('author_urn')] ?? ''));
        }

        return $reference;
    }

    public function isConfigured(array $options): bool
    {
        return trim((string) ($options[$this->optionKey('access_token')] ?? '')) !== ''
            && trim((string) ($options[$this->optionKey('author_urn')] ?? '')) !== '';
    }

    public function publish(WP_Post $post, array $options, ?array $asset, string $text)
    {
        if ($options[$this->optionKey('access_token')] === '' || $options[$this->optionKey('author_urn')] === '') {
            return new WP_Error('justbee_postcaster_linkedin_config', __('LinkedIn credentials are missing.', 'postcaster'));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')],
            'Linkedin-Version' => $options[$this->optionKey('version')],
            'X-Restli-Protocol-Version' => '2.0.0',
        ];

        $mediaUrn = null;
        if ($asset) {
            $initialize = $this->http->jsonPost(
                'https://api.linkedin.com/rest/images?action=initializeUpload',
                ['initializeUploadRequest' => ['owner' => $options[$this->optionKey('author_urn')]]],
                $headers
            );
            if (is_wp_error($initialize)) {
                return $initialize;
            }

            $uploadUrl = $initialize['value']['uploadUrl'] ?? '';
            $mediaUrn = $initialize['value']['image'] ?? '';
            if ($uploadUrl === '' || $mediaUrn === '') {
                return new WP_Error(
                    'justbee_postcaster_linkedin_upload',
                    __('LinkedIn upload URL or image URN is missing.', 'postcaster'),
                    ['retryable' => false]
                );
            }

            $prepared = $this->media->readImageBytes(
                $asset,
                'justbee_postcaster_linkedin_media',
                __('Could not read LinkedIn image.', 'postcaster')
            );
            if (is_wp_error($prepared)) {
                return $prepared;
            }

            $binaryResponse = wp_remote_request($uploadUrl, [
                'method' => 'PUT',
                'timeout' => 60,
                'headers' => ['Content-Type' => $prepared['mime']],
                'body' => $prepared['bytes'],
            ]);
            if (is_wp_error($binaryResponse)) {
                return $binaryResponse;
            }
            $uploadCode = (int) wp_remote_retrieve_response_code($binaryResponse);
            if ($uploadCode < 200 || $uploadCode >= 300) {
                return new WP_Error(
                    'justbee_postcaster_linkedin_upload',
                    'LinkedIn image upload failed: HTTP ' . $uploadCode . ' ' . wp_remote_retrieve_body($binaryResponse),
                    ['status' => $uploadCode]
                );
            }

            // Short polling (4×500ms = 2s). Slower LinkedIn processing falls
            // through to the background retry handled by Action Scheduler.
            $statusReady = false;
            for ($i = 0; $i < self::MEDIA_STATUS_POLL_ATTEMPTS; $i++) {
                $statusResponse = wp_remote_get('https://api.linkedin.com/rest/images/' . rawurlencode($mediaUrn), [
                    'timeout' => 30,
                    'headers' => $headers,
                ]);

                $statusData = $this->http->decodeJsonResponse($statusResponse, __('LinkedIn image status check failed.', 'postcaster'));
                if (is_wp_error($statusData)) {
                    return $statusData;
                }

                if (($statusData['status'] ?? '') === 'AVAILABLE') {
                    $statusReady = true;
                    break;
                }

                usleep(self::MEDIA_STATUS_POLL_DELAY_MICROSECONDS);
            }

            if (!$statusReady) {
                return new WP_Error(
                    'justbee_postcaster_linkedin_processing',
                    __('LinkedIn image still processing; will retry in the background queue.', 'postcaster'),
                    ['retryable' => true]
                );
            }
        }

        $payload = [
            'author' => $options[$this->optionKey('author_urn')],
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
        if ($mediaUrn) {
            $payload['content'] = ['media' => ['id' => $mediaUrn, 'altText' => $asset['alt']]];
        }

        $response = wp_remote_post('https://api.linkedin.com/rest/posts', [
            'timeout' => 30,
            'headers' => array_merge($headers, ['Content-Type' => 'application/json; charset=utf-8']),
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'justbee_postcaster_linkedin_post',
                'LinkedIn post create failed: HTTP ' . $code . ' ' . wp_remote_retrieve_body($response),
                ['status' => $code]
            );
        }

        $postUrn = (string) wp_remote_retrieve_header($response, 'x-restli-id');

        return [
            'id' => $postUrn,
            'url' => $postUrn !== ''
                ? 'https://www.linkedin.com/feed/update/' . rawurlencode($postUrn) . '/'
                : '',
        ];
    }

    public function publishTest(array $options, string $text, array $context = [])
    {
        if ($options[$this->optionKey('access_token')] === '' || $options[$this->optionKey('author_urn')] === '') {
            return new WP_Error('justbee_postcaster_linkedin_config', __('LinkedIn credentials are missing.', 'postcaster'));
        }

        $headers = [
            'Authorization' => 'Bearer ' . $options[$this->optionKey('access_token')],
            'Linkedin-Version' => $options[$this->optionKey('version')],
            'X-Restli-Protocol-Version' => '2.0.0',
        ];

        $payload = [
            'author' => $options[$this->optionKey('author_urn')],
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $response = wp_remote_post('https://api.linkedin.com/rest/posts', [
            'timeout' => 30,
            'headers' => array_merge($headers, ['Content-Type' => 'application/json; charset=utf-8']),
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'justbee_postcaster_linkedin_post',
                'LinkedIn post create failed: HTTP ' . $code . ' ' . wp_remote_retrieve_body($response),
                ['status' => $code]
            );
        }

        $postUrn = (string) wp_remote_retrieve_header($response, 'x-restli-id');

        return [
            'id' => $postUrn,
            'url' => $postUrn !== ''
                ? 'https://www.linkedin.com/feed/update/' . rawurlencode($postUrn) . '/'
                : '',
        ];
    }
}
