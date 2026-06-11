<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drives the actual publish flow: diagnosis, scheduling-result, error logging.
 *
 * Preview rendering lives in PreviewBuilder; shared lookups live in
 * TargetContextResolver. PublisherService keeps thin delegate methods so
 * existing callers do not need to change.
 */
final class PublisherService
{
    private const TARGET_LOCK_TTL = 900;
    private const QUEUE_LOCK_RETRY_DELAY_SECONDS = 5;

    /** @var array{retryable: bool, retry_after: int} */
    private array $lastOutcome = ['retryable' => false, 'retry_after' => 0];

    private SettingsModel $settings;
    private PostMetaModel $postMeta;
    private MediaService $media;
    private NetworkRegistry $networks;
    private PublishTargetResolver $targets;
    private TemplateDescriptionService $templateDescriptions;
    private TargetContextResolver $context;
    private PreviewBuilder $previews;

    public function __construct(
        SettingsModel $settings,
        PostMetaModel $postMeta,
        MediaService $media,
        NetworkRegistry $networks,
        PublishTargetResolver $targets,
        PostTemplateContextBuilder $contextBuilder,
        TemplateDescriptionService $templateDescriptions,
        PostRenderer $renderer,
        ?TargetContextResolver $context = null,
        ?PreviewBuilder $previews = null
    ) {
        $this->settings = $settings;
        $this->postMeta = $postMeta;
        $this->media = $media;
        $this->networks = $networks;
        $this->targets = $targets;
        $this->templateDescriptions = $templateDescriptions;
        $this->context = $context ?? new TargetContextResolver(
            $settings,
            $postMeta,
            $networks,
            $targets,
            $renderer,
            $contextBuilder,
            $templateDescriptions
        );
        $this->previews = $previews ?? new PreviewBuilder(
            $settings,
            $postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $templateDescriptions,
            $renderer,
            $this->context
        );
    }

    public function shouldPublish(WP_Post $post): bool
    {
        return $this->getPublishDiagnosis($post)['should_publish'];
    }

    public function getGlobalOptions(): array
    {
        return $this->settings->get();
    }

    public function getGlobalPublishingContext(): ?array
    {
        $globalOptions = $this->settings->get();

        if (($globalOptions['enabled'] ?? '0') !== '1' || !$this->targets->hasConfiguredGlobalTargets($globalOptions)) {
            return null;
        }

        return [
            'scope' => 'global',
            'label' => __('Global accounts', 'postcaster'),
            'options' => $globalOptions,
            'template_description' => $this->templateDescriptions->describeGeneralTemplate($globalOptions),
            'default_template' => $this->context->resolveTemplate($globalOptions, null),
        ];
    }

    public function getPersonalPublishingContextForUser(int $userId): ?array
    {
        $globalOptions = $this->settings->get();
        $personalContext = $this->targets->getPersonalEditorContext($userId, $globalOptions);
        if ($personalContext === null) {
            return null;
        }

        return [
            'scope' => 'personal',
            'label' => __('Personal accounts', 'postcaster'),
            'options' => $personalContext['options'],
            'profile' => $personalContext['profile'],
            'template_description' => $this->templateDescriptions->describeGeneralTemplate($globalOptions, $personalContext['profile']),
            'default_template' => $this->context->resolveTemplate($personalContext['options'], null),
        ];
    }

    public function isPostAuthorOrCoauthor(WP_Post $post, int $userId): bool
    {
        return $this->targets->isPublishingUserForPost($post, $userId);
    }

    public function getDefaultPostTemplate(): string
    {
        return $this->context->resolveTemplate($this->settings->get(), null);
    }

    public function getDefaultIncludeFeaturedImage(WP_Post $post): bool
    {
        foreach ($this->context->getPublishTargets($post, $this->settings->get()) as $networkKey => $networkTargets) {
            $network = $this->networks->get($networkKey);
            if (!$network) {
                continue;
            }

            foreach ($networkTargets as $targetOptions) {
                if (($targetOptions[$network->optionKey('include_featured_image')] ?? '0') === '1') {
                    return true;
                }
            }
        }

        return false;
    }

    public function getPublishDiagnosis(WP_Post $post, array $context = []): array
    {
        $options = $this->settings->get();
        $reasons = [];
        $includePersonalNetworks = $this->shouldPublishToPersonalNetworks($options, $context);
        $includeGlobalNetworks = $this->shouldPublishToGlobalNetworks($context);

        if (($options['enabled'] ?? '0') !== '1') {
            $reasons[] = __('PostCaster is disabled in the general settings.', 'postcaster');
            return ['should_publish' => false, 'reasons' => $reasons];
        }

        $postTypes = is_array($options['post_types'] ?? null) ? $options['post_types'] : ['post'];
        $postTypes = $postTypes !== [] ? $postTypes : ['post'];

        if (!in_array($post->post_type, $postTypes, true)) {
            $reasons[] = sprintf(
                /* translators: %s: WordPress post type slug. */
                __('Post type %s is not enabled for PostCaster.', 'postcaster'),
                $post->post_type
            );
            return ['should_publish' => false, 'reasons' => $reasons];
        }

        if (empty($context['ignore_post_disable']) && $this->postMeta->isPublishDisabled($post->ID)) {
            $reasons[] = __('This article is set to not publish through PostCaster.', 'postcaster');
            return ['should_publish' => false, 'reasons' => $reasons];
        }

        if (!apply_filters('justbee_postcaster_should_publish', true, $post, $options)) {
            $reasons[] = __('A filter blocked PostCaster for this post.', 'postcaster');
            return ['should_publish' => false, 'reasons' => $reasons];
        }

        foreach ($this->context->getPublishTargets($post, $options, $includePersonalNetworks, $includeGlobalNetworks) as $targets) {
            if (!empty($targets)) {
                return ['should_publish' => true, 'reasons' => []];
            }
        }

        $reasons[] = __('No active PostCaster targets were found for this post.', 'postcaster');
        return ['should_publish' => false, 'reasons' => $reasons];
    }

    public function publishPost(WP_Post $post, array $context = []): bool
    {
        $this->lastOutcome = ['retryable' => false, 'retry_after' => 0];

        if (!$this->getPublishDiagnosis($post, $context)['should_publish']) {
            return false;
        }

        $options = $this->settings->get();
        $includePersonalNetworks = $this->shouldPublishToPersonalNetworks($options, $context);
        $includeGlobalNetworks = $this->shouldPublishToGlobalNetworks($context);
        $allowRepost = !empty($context['allow_repost']);
        $onlyNetworkKey = isset($context['only_network_key']) ? sanitize_key((string) $context['only_network_key']) : '';
        $targets = $this->context->getPublishTargets($post, $options, $includePersonalNetworks, $includeGlobalNetworks);
        $asset = null;
        $hadFailures = false;

        foreach ($targets as $networkKey => $networkTargets) {
            if ($onlyNetworkKey !== '' && $networkKey !== $onlyNetworkKey) {
                continue;
            }

            $network = $this->networks->get($networkKey);
            if (!$network) {
                continue;
            }

            if ($asset === null) {
                $asset = $this->media->getPostImageAsset($post->ID, 'publish');
            }

            foreach ($networkTargets as $targetKey => $targetOptions) {
                $hadFailures = $this->publishToTarget(
                    $post,
                    $network,
                    $networkKey,
                    (string) $targetKey,
                    $targetOptions,
                    $allowRepost,
                    $asset
                ) || $hadFailures;
            }
        }

        return $hadFailures;
    }

    public function buildTargetJobs(WP_Post $post, array $context = []): array
    {
        $jobs = [];

        foreach ($this->getResolvedPublishTargets($post, $context) as $networkKey => $networkTargets) {
            foreach ($networkTargets as $targetKey => $targetOptions) {
                $jobs[] = [
                    'post_id' => $post->ID,
                    'network_key' => (string) $networkKey,
                    'target_key' => (string) $targetKey,
                    'allow_repost' => !empty($context['allow_repost']),
                    'attempt' => 1,
                    'trigger' => !empty($context['allow_repost']) ? 'manual_publish' : 'auto_publish',
                ];
            }
        }

        return $jobs;
    }

    /**
     * @return array{status:string,retryable:bool,retry_after:int}
     */
    public function publishQueuedTarget(int $postId, string $networkKey, string $targetKey, bool $allowRepost = false): array
    {
        $this->lastOutcome = ['retryable' => false, 'retry_after' => 0];

        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return ['status' => 'skipped', 'retryable' => false, 'retry_after' => 0];
        }

        if (!$this->shouldPublish($post)) {
            return ['status' => 'skipped', 'retryable' => false, 'retry_after' => 0];
        }

        $targets = $this->getResolvedPublishTargets($post, []);
        $targetOptions = is_array($targets[$networkKey][$targetKey] ?? null) ? $targets[$networkKey][$targetKey] : null;
        $network = $this->networks->get($networkKey);

        if ($targetOptions === null || !$network) {
            return ['status' => 'skipped', 'retryable' => false, 'retry_after' => 0];
        }

        if ($this->postMeta->hasPublishLock($postId, $networkKey, $targetKey)) {
            $targetLabel = $this->context->describeLogTarget($targetKey);
            $this->log(
                $postId,
                sprintf(
                    '%s publish deferred for post %d target %s because another publish is already in progress',
                    $networkKey,
                    $postId,
                    $targetLabel
                ),
                $targetOptions
            );

            return [
                'status' => 'failed',
                'retryable' => true,
                'retry_after' => self::QUEUE_LOCK_RETRY_DELAY_SECONDS,
                'exact_retry_after' => true,
            ];
        }

        $asset = $this->media->getPostImageAsset($post->ID, 'publish');
        $hadFailure = $this->publishToTarget(
            $post,
            $network,
            $networkKey,
            $targetKey,
            $targetOptions,
            $allowRepost,
            $asset
        );

        if (!$hadFailure) {
            return ['status' => 'success', 'retryable' => false, 'retry_after' => 0];
        }

        return [
            'status' => 'failed',
            'retryable' => $this->lastOutcome['retryable'],
            'retry_after' => $this->lastOutcome['retry_after'],
        ];
    }

    public function getPreviewTexts(WP_Post $post, array $context = []): array
    {
        $options = $this->settings->get();
        $includePersonalNetworks = $this->shouldPublishToPersonalNetworks($options, $context);
        $includeGlobalNetworks = $this->shouldPublishToGlobalNetworks($context);
        $targets = $this->context->getPublishTargets($post, $options, $includePersonalNetworks, $includeGlobalNetworks);
        $postTemplate = $this->postMeta->getPostTemplate($post->ID);

        return $this->previews->buildTargetPreviews($post, $targets, $postTemplate);
    }

    public function buildTestStatusText(
        string $networkKey,
        array $options,
        string $scope = 'global',
        int $userId = 0
    ): string {
        return $this->previews->buildTestStatusText($networkKey, $options, $scope, $userId);
    }

    /**
     * @return array{post?: WP_Post, asset?: array|null, include_featured_image: bool}
     */
    public function buildTestPublishContext(
        string $networkKey,
        array $options,
        string $scope = 'global',
        int $userId = 0
    ): array {
        $post = $this->previews->getExamplePost($scope, $userId);
        $network = $this->networks->get($networkKey);

        if (!$post instanceof WP_Post || $network === null) {
            return ['include_featured_image' => false];
        }

        $includeFeaturedImage = ($options[$network->optionKey('include_featured_image')] ?? '0') === '1';

        return [
            'post' => $post,
            'asset' => $includeFeaturedImage ? $this->media->getPostImageAsset($post->ID, 'publish') : null,
            'include_featured_image' => $includeFeaturedImage,
            'placeholder_mentions' => $this->previews->buildTestMentionCandidates($networkKey, $options, $scope, $userId),
        ];
    }

    public function buildExampleStatusText(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): string {
        return $this->previews->buildExampleStatusText($networkKey, $template, $scope, $userId);
    }

    public function buildExamplePreviewItems(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): array {
        return $this->previews->buildExamplePreviewItems($networkKey, $template, $scope, $userId);
    }

    public function buildExamplePreviewData(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): array {
        return $this->previews->buildExamplePreviewData($networkKey, $template, $scope, $userId);
    }

    public function getContextPreviewTexts(WP_Post $post, string $context = 'global', ?string $templateOverride = null): array
    {
        return $this->previews->getContextPreviewTexts($post, $context, $templateOverride);
    }

    public function getContextPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        return $this->previews->getContextPreviewData($post, $context, $templateOverride, $includeFeaturedImageOverride);
    }

    /**
     * @return array{retryable: bool, retry_after: int}
     *
     * Result of the most recent publishPost() call. retryable is true when at
     * least one failure was transient (HTTP 408/429/5xx, network error). The
     * retry_after value (seconds) reflects the longest Retry-After hint
     * received, capped at 1 hour by HttpService.
     */
    public function getLastPublishOutcome(): array
    {
        return $this->lastOutcome;
    }

    private function recordFailure(\WP_Error $error): void
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        $retryAfter = is_array($data) ? (int) ($data['retry_after'] ?? 0) : 0;
        $hasExplicitRetryability = is_array($data) && array_key_exists('retryable', $data);
        $isRetryable = $hasExplicitRetryability
            ? !empty($data['retryable'])
            : ($this->errorLooksTransportLevel($error) || ($status > 0 && $this->isRetryableStatus($status)));

        if (!$isRetryable) {
            return;
        }

        $this->lastOutcome['retryable'] = true;
        if ($retryAfter > $this->lastOutcome['retry_after']) {
            $this->lastOutcome['retry_after'] = $retryAfter;
        }
    }

    private function isRetryableStatus(int $status): bool
    {
        // 0 = network/transport error or non-HTTP failure (e.g. media still
        // processing). Treat as transient. 408/429 are retry-after canonicals,
        // 5xx is server-side. Any other 4xx is a permanent client error.
        if ($status === 0 || $status === 408 || $status === 429) {
            return true;
        }

        return $status >= 500 && $status < 600;
    }

    private function errorLooksTransportLevel(\WP_Error $error): bool
    {
        return $error->get_error_code() === 'http_request_failed';
    }

    public function getCombinedPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        return $this->previews->getCombinedPreviewData($post, $context, $templateOverride, $includeFeaturedImageOverride);
    }

    public function getPerNetworkPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        return $this->previews->getPerNetworkPreviewData($post, $context, $templateOverride, $includeFeaturedImageOverride);
    }

    public function describeResolvedContextTemplate(WP_Post $post, string $context, array $contextDescription): array
    {
        return $this->previews->describeResolvedContextTemplate($post, $context, $contextDescription);
    }

    public function describePostPreviewTemplate(WP_Post $post): array
    {
        return $this->previews->describePostPreviewTemplate($post);
    }

    private function publishToTarget(
        WP_Post $post,
        $network,
        string $networkKey,
        string $targetKey,
        array $targetOptions,
        bool $allowRepost,
        ?array $asset
    ): bool {
        $targetLabel = $this->context->describeLogTarget($targetKey);
        $lockToken = $this->postMeta->acquirePublishLock($post->ID, $networkKey, $targetKey, self::TARGET_LOCK_TTL);
        if ($lockToken === null) {
            $this->log(
                $post->ID,
                sprintf('%s publish skipped for post %d target %s because another publish is already in progress', $networkKey, $post->ID, $targetLabel),
                $targetOptions
            );
            return false;
        }

        try {
            if (!$allowRepost && $this->postMeta->hasRemoteId($post->ID, $networkKey, $targetKey)) {
                return false;
            }

            $message = $this->context->buildRenderedPostMessage($post, $networkKey, $targetKey, $targetOptions);
            $prepared = $this->context->buildPreparedTextData($post, $network, $networkKey, $targetOptions, $message);
            $targetOptions['justbee_postcaster_placeholder_mentions'] = $this->context->buildPlaceholderMentionCandidates(
                $post,
                $networkKey,
                $targetKey,
                $targetOptions
            );
            if (!$prepared['fits']) {
                $this->saveTooLongError($post->ID, $networkKey, $targetKey, $prepared['length'], $prepared['limit'], $targetOptions);
                return true;
            }

            $shouldIncludeFeaturedImage = $this->context->shouldIncludeFeaturedImageForTarget(
                $post,
                $network,
                $targetKey,
                $targetOptions
            );

            // Decide BEFORE finalize, while the rendered text still reflects
            // template intent (e.g. did the user include {url}?).
            $shouldAttachAsset = $network->shouldAttachAsset(
                $post,
                $prepared['text'],
                $targetOptions,
                $shouldIncludeFeaturedImage
            );

            $prepared['text'] = $network->finalizePostText($post, $targetOptions, $prepared['text'], $shouldIncludeFeaturedImage);
            $prepared['length'] = mb_strlen((string) $prepared['text'], 'UTF-8');
            $prepared['fits'] = $prepared['length'] <= $prepared['limit'];

            $this->log(
                $post->ID,
                sprintf(
                    '%s publish starting for post %d target %s; featured image included: %s; asset attached: %s',
                    $networkKey,
                    $post->ID,
                    $targetLabel,
                    $shouldIncludeFeaturedImage ? 'yes' : 'no',
                    $shouldAttachAsset ? 'yes' : 'no'
                ),
                $targetOptions
            );

            $result = $network->publish(
                $post,
                $targetOptions,
                $shouldAttachAsset ? $asset : null,
                $prepared['text']
            );

            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $this->recordFailure($result);
                $this->postMeta->saveError($post->ID, $networkKey, $targetKey, $message);
                $this->log(
                    $post->ID,
                    sprintf('%s publish failed for post %d target %s: %s', $networkKey, $post->ID, $targetLabel, $message),
                    $targetOptions
                );
                return true;
            }

            $this->postMeta->saveSuccess($post->ID, $networkKey, $targetKey, $result);
            $this->log(
                $post->ID,
                sprintf('%s publish succeeded for post %d target %s', $networkKey, $post->ID, $targetLabel),
                $targetOptions
            );

            return false;
        } finally {
            $this->postMeta->releasePublishLock($post->ID, $networkKey, $targetKey, $lockToken);
        }
    }

    private function saveTooLongError(
        int $postId,
        string $networkKey,
        string $targetKey,
        int $length,
        int $limit,
        array $targetOptions
    ): void {
        $targetLabel = $this->context->describeLogTarget($targetKey);
        $errorMessage = sprintf(
            /* translators: 1: current character count, 2: maximum allowed character count, 3: social network key. */
            __('PostCaster could not publish because the rendered message is still %1$d characters long while %2$d is the maximum for %3$s. Shorten the template or the non-shrinkable parts such as the URL.', 'postcaster'),
            $length,
            $limit,
            $networkKey
        );

        $this->postMeta->saveError($postId, $networkKey, $targetKey, $errorMessage);
        $this->log(
            $postId,
            sprintf('%s publish skipped for post %d target %s: %s', $networkKey, $postId, $targetLabel, $errorMessage),
            $targetOptions
        );
    }

    private function log(int $postId, string $message, array $options): void
    {
        if (($options['debug'] ?? '0') !== '1') {
            return;
        }

        $this->postMeta->appendLog($postId, $message);
    }

    private function shouldPublishToPersonalNetworks(array $options, array $context): bool
    {
        if (($options['personal_networks_enabled'] ?? '1') !== '1') {
            return false;
        }

        return !isset($context['include_personal_networks']) || $context['include_personal_networks'] === true;
    }

    private function shouldPublishToGlobalNetworks(array $context): bool
    {
        return !isset($context['include_global_networks']) || $context['include_global_networks'] === true;
    }

    private function getResolvedPublishTargets(WP_Post $post, array $context): array
    {
        $options = $this->settings->get();
        $includePersonalNetworks = $this->shouldPublishToPersonalNetworks($options, $context);
        $includeGlobalNetworks = $this->shouldPublishToGlobalNetworks($context);
        $onlyNetworkKey = isset($context['only_network_key']) ? sanitize_key((string) $context['only_network_key']) : '';
        $targets = $this->context->getPublishTargets($post, $options, $includePersonalNetworks, $includeGlobalNetworks);

        if ($onlyNetworkKey === '' || !isset($targets[$onlyNetworkKey])) {
            return $targets;
        }

        return [
            $onlyNetworkKey => $targets[$onlyNetworkKey],
        ];
    }
}
