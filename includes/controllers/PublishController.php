<?php

namespace Justbee\PostCaster\Controllers;

use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Services\PublishQueueService;
use Justbee\PostCaster\Services\PublisherService;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

final class PublishController
{
    private const FIELD_NONCE = 'justbee_postcaster_post_nonce';
    private const FIELD_DISABLE_PUBLISH = 'justbee_postcaster_post_disable_publish';
    private const FIELD_DRAFTS = 'justbee_postcaster_post_drafts';
    private const FIELD_PUBLISH_SCOPE = 'justbee_postcaster_publish_scope';
    private const FIELD_PUBLISH_NETWORK = 'justbee_postcaster_publish_network';

    private PublisherService $publisher;
    private PostMetaModel $postMeta;
    private PublishQueueService $queue;

    /** @var array<int, array> */
    private array $resolvedContextsCache = [];

    public function __construct(PublisherService $publisher, PostMetaModel $postMeta, ?PublishQueueService $queue = null)
    {
        $this->publisher = $publisher;
        $this->postMeta = $postMeta;
        $this->queue = $queue ?? new PublishQueueService($publisher, $postMeta);
        add_action('add_meta_boxes', [$this, 'registerMetaBox'], 10, 2);
        add_action('save_post', [$this, 'saveMetaBox'], 10, 2);
        add_action('wp_after_insert_post', [$this, 'handleAfterInsertPost'], 10, 4);
        add_action('admin_post_justbee_postcaster_publish_now', [$this, 'handlePublishNow']);
        add_action('wp_ajax_justbee_postcaster_preview_post_template', [$this, 'handlePreviewPostTemplate']);
        add_action('wp_ajax_justbee_postcaster_preview_template_example', [$this, 'handlePreviewTemplateExample']);
    }

    public function registerMetaBox(string $postType, $post): void
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        if (!array_key_exists($postType, AdminController::getSelectablePostTypes())) {
            return;
        }

        if (!$this->shouldShowMetaBox($post)) {
            return;
        }

        add_meta_box(
            'postcaster-compose',
            __('PostCaster', 'postcaster'),
            [$this, 'renderMetaBox'],
            $postType,
            'side',
            'default',
            ['__block_editor_compatible_meta_box' => true]
        );
    }

    public function renderMetaBox(WP_Post $post): void
    {
        $justbee_postcaster_post = $post;
        $publishingContexts = $this->getResolvedPublishingContexts($justbee_postcaster_post);
        if ($publishingContexts === []) {
            return;
        }

        $justbee_postcaster_compose_box = $this->buildComposeBoxData($justbee_postcaster_post, $publishingContexts);
        if ($justbee_postcaster_compose_box['scopes'] === []) {
            return;
        }

        $justbee_postcaster_disable_publish_checked = $this->postMeta->isPublishDisabled($justbee_postcaster_post->ID);
        $justbee_postcaster_existing_remote_posts = $this->buildRemotePublicationsList($justbee_postcaster_post->ID);
        $justbee_postcaster_has_existing_remote_posts = $justbee_postcaster_existing_remote_posts !== [];
        $justbee_postcaster_errors = $this->postMeta->getErrors($justbee_postcaster_post->ID);
        $justbee_postcaster_retry_summary = $this->queue->getRetrySummaryForPost($justbee_postcaster_post->ID);
        $justbee_postcaster_retry_notice = $this->buildRetryNotice($justbee_postcaster_retry_summary, $justbee_postcaster_errors);
        $justbee_postcaster_can_manual_publish = $this->canManuallyPublishPost($justbee_postcaster_post, $publishingContexts);
        $justbee_postcaster_is_read_only = $justbee_postcaster_post->post_status === 'publish' && !$justbee_postcaster_can_manual_publish;

        require __DIR__ . '/../../views/post-compose-box.php';
    }

    /**
     * Build the data structure consumed by views/post-compose-box.php.
     *
     * @return array{
     *   scopes: array<string, array{
     *     key: string, label: string,
     *     combined_template: string, combined_default_template: string,
     *     combined_preview: array,
     *     include_featured_image_scope: string,
     *     default_include_featured_image: bool,
     *     networks: array<string, array{
     *       key: string, label: string, template: string,
     *       include_featured_image: string, preview: array
     *     }>
     *   }>
     * }
     */
    private function buildComposeBoxData(WP_Post $post, array $publishingContexts): array
    {
        $scopes = [];

        foreach ($publishingContexts as $scopeKey => $publishingContext) {
            $combinedTemplate = $this->postMeta->getPostTemplate($post->ID, $scopeKey);
            $combinedDefault = (string) ($publishingContext['default_template'] ?? '');
            $combinedPreview = $this->publisher->getCombinedPreviewData(
                $post,
                $scopeKey,
                trim($combinedTemplate) !== '' ? $combinedTemplate : null
            );

            $perNetwork = $this->publisher->getPerNetworkPreviewData(
                $post,
                $scopeKey,
                trim($combinedTemplate) !== '' ? $combinedTemplate : null
            );

            $networks = [];
            foreach ($perNetwork as $networkKey => $networkPreview) {
                $networks[$networkKey] = [
                    'key' => (string) $networkKey,
                    'label' => (string) ($networkPreview['label'] ?? ''),
                    'template' => $this->postMeta->getNetworkPostTemplate($post->ID, $scopeKey, (string) $networkKey),
                    'include_featured_image' => (string) ($this->postMeta->getIncludeFeaturedImageNetworkOverride($post->ID, $scopeKey, (string) $networkKey) ?? ''),
                    'preview' => [
                        'items' => (array) ($networkPreview['items'] ?? []),
                    ],
                ];
            }

            $scopes[$scopeKey] = [
                'key' => $scopeKey,
                'label' => (string) ($publishingContext['label'] ?? ''),
                'combined_template' => $combinedTemplate,
                'combined_default_template' => $combinedDefault,
                'combined_preview' => [
                    'items' => (array) ($combinedPreview['items'] ?? []),
                    'text' => (string) ($combinedPreview['text'] ?? ''),
                    'image' => is_array($combinedPreview['image'] ?? null) ? $combinedPreview['image'] : null,
                    'card' => is_array($combinedPreview['card'] ?? null) ? $combinedPreview['card'] : null,
                ],
                'include_featured_image_scope' => (string) ($this->postMeta->getIncludeFeaturedImageScopeOverride($post->ID, $scopeKey) ?? ''),
                'default_include_featured_image' => $this->publisher->getDefaultIncludeFeaturedImage($post),
                'networks' => $networks,
            ];
        }

        return [
            'scopes' => $scopes,
            'active_scope' => array_key_first($scopes) ?? '',
        ];
    }

    public function saveMetaBox(int $postId, WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (!isset($_POST[self::FIELD_NONCE])) {
            return;
        }

        $nonce = sanitize_text_field(wp_unslash((string) $_POST[self::FIELD_NONCE]));
        if (!wp_verify_nonce($nonce, 'justbee_postcaster_post_settings')) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        // Nonce verified above; safe to unslash the whole payload for the helpers below.
        $postData = wp_unslash($_POST);

        if ($this->isPublishedReadOnlyForCurrentUser($post, $postData)) {
            return;
        }

        $publishingContexts = $this->getResolvedPublishingContexts($post);
        if ($publishingContexts === []) {
            return;
        }

        $this->saveMetaboxPublishSettings($post, $publishingContexts, true, $postData);
    }

    public function handleAfterInsertPost(int $postId, WP_Post $post, bool $update, $postBefore): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if ($post->post_status !== 'publish') {
            return;
        }
        if ($postBefore instanceof WP_Post && $postBefore->post_status === 'publish') {
            return;
        }

        $this->diagnoseAndSchedule($post);
    }

    private function diagnoseAndSchedule(WP_Post $post): void
    {
        $diagnosis = $this->publisher->getPublishDiagnosis($post);
        if (!$diagnosis['should_publish']) {
            AdminController::queuePostNotice($post->ID, sprintf(
                /* translators: %s: reasons PostCaster did not schedule the post. */
                __('PostCaster did not schedule this post: %s', 'postcaster'),
                implode(' ', $diagnosis['reasons'])
            ), 'warning');
            return;
        }

        $this->schedulePublish($post->ID);
    }

    public function handlePublishNow(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cast to int is a full sanitizer for an integer field.
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to publish this article with PostCaster.', 'postcaster'));
        }

        check_admin_referer('justbee_postcaster_publish_now_' . $postId);

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            wp_die(esc_html__('The selected article could not be found.', 'postcaster'));
        }

        if ($post->post_status !== 'publish') {
            AdminController::queuePostNotice($postId, __('PostCaster can only post directly from already published articles.', 'postcaster'), 'warning');
            $this->redirectToPostEdit($postId);
        }

        $publishingContexts = $this->getResolvedPublishingContexts($post);
        if ($publishingContexts === []) {
            AdminController::queuePostNotice($postId, __('No PostCaster targets are configured for this article.', 'postcaster'), 'warning');
            $this->redirectToPostEdit($postId);
        }

        // Nonce verified via check_admin_referer() above; safe to read POST for the helpers below.
        $postData = wp_unslash($_POST);

        $this->saveMetaboxPublishSettings($post, $publishingContexts, false, $postData);

        $scope = $this->resolveManualPublishScope($publishingContexts, $postData) + ['ignore_post_disable' => true];
        $this->runManualPublish($post, $scope);
    }

    private function resolveManualPublishScope(array $publishingContexts, array $postData): array
    {
        $requestedScope = isset($postData[self::FIELD_PUBLISH_SCOPE])
            ? sanitize_key((string) $postData[self::FIELD_PUBLISH_SCOPE])
            : '';
        $requestedNetwork = isset($postData[self::FIELD_PUBLISH_NETWORK])
            ? sanitize_key((string) $postData[self::FIELD_PUBLISH_NETWORK])
            : '';

        $includeGlobalNetworks = isset($publishingContexts['global']);
        $includePersonalNetworks = isset($publishingContexts['personal']);

        if ($requestedScope === 'global' && $includeGlobalNetworks) {
            $includePersonalNetworks = false;
        } elseif ($requestedScope === 'personal' && $includePersonalNetworks) {
            $includeGlobalNetworks = false;
        }

        $context = [
            'include_global_networks' => $includeGlobalNetworks,
            'include_personal_networks' => $includePersonalNetworks,
        ];

        if ($requestedNetwork !== '') {
            $context['only_network_key'] = $requestedNetwork;
        }

        return $context;
    }

    private function runManualPublish(WP_Post $post, array $scope): void
    {
        $postId = $post->ID;
        $diagnosis = $this->publisher->getPublishDiagnosis($post, $scope);

        if (!$diagnosis['should_publish']) {
            AdminController::queuePostNotice($postId, sprintf(
                /* translators: %s: reasons PostCaster did not publish the article. */
                __('PostCaster did not publish this article: %s', 'postcaster'),
                implode(' ', $diagnosis['reasons'])
            ), 'warning');
            $this->redirectToPostEdit($postId);
        }

        $queuedJobs = $this->queue->enqueuePublish($post, $scope + ['allow_repost' => true]);
        AdminController::queuePostNotice(
            $postId,
            $queuedJobs > 0
                ? __('PostCaster queued this article for the selected publish targets.', 'postcaster')
                : __('PostCaster did not add any new publish jobs for this article.', 'postcaster'),
            'success'
        );
        $this->redirectToPostEdit($postId);
    }

    public function handlePreviewPostTemplate(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cast to int is a full sanitizer for an integer field.
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
        }

        check_ajax_referer('justbee_postcaster_preview_post_template_' . $postId);

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            wp_send_json_error(['message' => __('The selected article could not be found.', 'postcaster')], 404);
        }

        $publishingContexts = $this->getResolvedPublishingContexts($post);
        if ($publishingContexts === []) {
            wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
        }

        $contextKey = isset($_POST['template_context']) ? sanitize_key(wp_unslash((string) $_POST['template_context'])) : 'global';
        if (!isset($publishingContexts[$contextKey])) {
            wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
        }

        $template = isset($_POST['template'])
            ? sanitize_textarea_field(wp_unslash((string) $_POST['template']))
            : '';
        $networkKey = isset($_POST['network_key'])
            ? sanitize_key(wp_unslash((string) $_POST['network_key']))
            : '';

        $includeFeaturedImageOverride = null;
        if (array_key_exists('include_featured_image', $_POST)) {
            $raw = sanitize_text_field(wp_unslash((string) $_POST['include_featured_image']));
            if ($raw === '1' || $raw === '0') {
                $includeFeaturedImageOverride = $raw === '1';
            }
        }

        if ($networkKey !== '') {
            $perNetwork = $this->publisher->getPerNetworkPreviewData(
                $post,
                $contextKey,
                $template !== '' ? $template : null,
                $includeFeaturedImageOverride
            );
            $networkPreview = $perNetwork[$networkKey] ?? null;
            if (!is_array($networkPreview)) {
                wp_send_json_success([
                    'items' => [],
                    'text' => '',
                    'image_url' => '',
                    'image_alt' => '',
                    'card' => null,
                ]);
            }

            $items = (array) ($networkPreview['items'] ?? []);
            $first = is_array($items[0] ?? null) ? $items[0] : [];
            wp_send_json_success([
                'items' => $items,
                'text' => (string) ($first['text'] ?? ''),
                'image_url' => '',
                'image_alt' => '',
                'card' => is_array($first['card'] ?? null) ? $first['card'] : null,
            ]);
        }

        $previewData = $this->publisher->getCombinedPreviewData(
            $post,
            $contextKey,
            $template !== '' ? $template : null,
            $includeFeaturedImageOverride
        );

        wp_send_json_success([
            'items' => (array) ($previewData['items'] ?? []),
            'text' => (string) ($previewData['text'] ?? ''),
            'image_url' => (string) (($previewData['image']['url'] ?? '')),
            'image_alt' => (string) (($previewData['image']['alt'] ?? '')),
            'card' => is_array($previewData['card'] ?? null) ? $previewData['card'] : null,
        ]);
    }

    public function handlePreviewTemplateExample(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
        }

        check_ajax_referer('justbee_postcaster_preview_template_example');

        $networkKey = isset($_POST['network_key']) ? sanitize_key(wp_unslash((string) $_POST['network_key'])) : '';
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash((string) $_POST['scope'])) : 'global';
        $template = isset($_POST['template']) ? sanitize_textarea_field(wp_unslash((string) $_POST['template'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- cast to int is a full sanitizer for an integer field.
        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($scope === 'personal') {
            if ($userId <= 0 || !current_user_can('edit_user', $userId)) {
                wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
            }
        } else {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('You are not allowed to preview this PostCaster template.', 'postcaster')], 403);
            }

            $scope = 'global';
            $userId = 0;
        }

        $resolvedNetworkKey = $networkKey !== '' ? $networkKey : null;
        $previewItems = $this->publisher->buildExamplePreviewItems($resolvedNetworkKey, $template, $scope, $userId);
        $previewData = $this->publisher->buildExamplePreviewData($resolvedNetworkKey, $template, $scope, $userId);

        wp_send_json_success([
            'items' => $previewItems,
            'text' => (string) ($previewData['text'] ?? ''),
            'image_url' => (string) (($previewData['image']['url'] ?? '')),
            'image_alt' => (string) (($previewData['image']['alt'] ?? '')),
            'card' => is_array($previewData['card'] ?? null) ? $previewData['card'] : null,
        ]);
    }

    private function schedulePublish(int $postId): void
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return;
        }

        $queuedJobs = $this->queue->enqueuePublish($post);
        if ($queuedJobs <= 0) {
            return;
        }

        $this->postMeta->appendLog($postId, sprintf(
            /* translators: %d: number of queued publish jobs. */
            __('Queued %d PostCaster publish jobs.', 'postcaster'),
            $queuedJobs
        ));
        AdminController::queuePostNotice($postId, sprintf(
            /* translators: %d: number of queued publish jobs. */
            __('PostCaster queued %d background publish jobs.', 'postcaster'),
            $queuedJobs
        ), 'success');
    }

    private function redirectToPostEdit(int $postId): void
    {
        wp_safe_redirect(get_edit_post_link($postId, ''));
        exit;
    }

    private function canUseGlobalContext(): bool
    {
        return current_user_can('edit_others_posts');
    }

    private function canManuallyPublishPost(WP_Post $post, ?array $publishingContexts = null): bool
    {
        if ($post->post_status !== 'publish' || !current_user_can('edit_post', $post->ID)) {
            return false;
        }

        $publishingContexts = $publishingContexts ?? $this->getResolvedPublishingContexts($post);

        return $publishingContexts !== [];
    }

    private function isPublishedReadOnlyForCurrentUser(WP_Post $post, array $postData): bool
    {
        if ($post->post_status !== 'publish' || $this->canManuallyPublishPost($post)) {
            return false;
        }

        $originalStatus = isset($postData['original_post_status'])
            ? sanitize_key((string) $postData['original_post_status'])
            : '';

        return $originalStatus === 'publish';
    }

    private function shouldShowMetaBox(WP_Post $post): bool
    {
        return $this->getAvailablePublishingContexts($post) !== [];
    }

    private function getResolvedPublishingContexts(WP_Post $post): array
    {
        if (isset($this->resolvedContextsCache[$post->ID])) {
            return $this->resolvedContextsCache[$post->ID];
        }

        $contexts = $this->getAvailablePublishingContexts($post);

        foreach ($contexts as $contextKey => $context) {
            $resolvedTemplateDescription = $this->publisher->describeResolvedContextTemplate(
                $post,
                $contextKey,
                (array) ($context['template_description'] ?? [])
            );

            $contexts[$contextKey]['template_description'] = $resolvedTemplateDescription;
            // Keep the scope-level default_template from PublisherService — it
            // represents the general/scope template (network-agnostic), which
            // is what the combined modal must fall back to. Resolving against
            // a single-network scope would otherwise pin it to that network's
            // per-network template.
        }

        return $this->resolvedContextsCache[$post->ID] = $contexts;
    }

    private function buildPublishingContextView(WP_Post $post, string $contextKey, array $publishingContext): array
    {
        $postTemplate = $this->postMeta->getPostTemplate($post->ID, $contextKey);
        $hasOverride = trim($postTemplate) !== '';

        return [
            'label' => (string) ($publishingContext['label'] ?? ''),
            'default_template' => (string) ($publishingContext['default_template'] ?? ''),
            'template_description' => (array) ($publishingContext['template_description'] ?? []),
            'post_template' => $postTemplate,
            'has_override' => $hasOverride,
        ];
    }

    private function saveMetaboxPublishSettings(WP_Post $post, array $publishingContexts, bool $alwaysPersistFeaturedImage, array $postData): void
    {
        $drafts = isset($postData[self::FIELD_DRAFTS]) && is_array($postData[self::FIELD_DRAFTS])
            ? $postData[self::FIELD_DRAFTS]
            : [];

        foreach ($publishingContexts as $scopeKey => $publishingContext) {
            $scopeData = isset($drafts[$scopeKey]) && is_array($drafts[$scopeKey]) ? $drafts[$scopeKey] : null;

            $defaultTemplate = (string) ($publishingContext['default_template'] ?? $this->publisher->getDefaultPostTemplate());

            if ($scopeData !== null && array_key_exists('combined', $scopeData)) {
                $combinedTemplate = sanitize_textarea_field((string) $scopeData['combined']);
                $this->postMeta->savePostTemplate($post->ID, $combinedTemplate, $defaultTemplate, $scopeKey);
            }

            if ($scopeData !== null && array_key_exists('include_featured_image', $scopeData)) {
                $scopeFeatured = $this->normalizeTriState((string) $scopeData['include_featured_image']);
                $this->postMeta->saveIncludeFeaturedImageScopeOverride($post->ID, $scopeKey, $scopeFeatured);
            } elseif ($alwaysPersistFeaturedImage) {
                $this->postMeta->saveIncludeFeaturedImageScopeOverride($post->ID, $scopeKey, '');
            }

            $networksData = $scopeData !== null && isset($scopeData['networks']) && is_array($scopeData['networks'])
                ? $scopeData['networks']
                : [];

            foreach ($networksData as $networkKey => $networkData) {
                $networkKey = sanitize_key((string) $networkKey);
                if ($networkKey === '' || !is_array($networkData)) {
                    continue;
                }

                if (array_key_exists('template', $networkData)) {
                    $networkTemplate = sanitize_textarea_field((string) $networkData['template']);
                    $this->postMeta->saveNetworkPostTemplate(
                        $post->ID,
                        $scopeKey,
                        $networkKey,
                        $networkTemplate,
                        $defaultTemplate
                    );
                }

                if (array_key_exists('include_featured_image', $networkData)) {
                    $networkFeatured = $this->normalizeTriState((string) $networkData['include_featured_image']);
                    $this->postMeta->saveIncludeFeaturedImageNetworkOverride(
                        $post->ID,
                        $scopeKey,
                        $networkKey,
                        $networkFeatured
                    );
                }
            }
        }

        $disablePublish = isset($postData[self::FIELD_DISABLE_PUBLISH]) ? '1' : '0';
        $this->postMeta->saveDisablePublishOverride($post->ID, $disablePublish, '0');
    }

    private function normalizeTriState(string $value): string
    {
        if ($value === '1' || $value === '0') {
            return $value;
        }

        return '';
    }

    private function getAvailablePublishingContexts(WP_Post $post): array
    {
        $contexts = [];
        $userId = get_current_user_id();

        if ($this->canUseGlobalContext()) {
            $globalContext = $this->publisher->getGlobalPublishingContext();
            if ($globalContext !== null) {
                $contexts['global'] = $globalContext;
            }
        }

        if ($this->publisher->isPostAuthorOrCoauthor($post, $userId)) {
            $personalContext = $this->publisher->getPersonalPublishingContextForUser($userId);
            if ($personalContext !== null) {
                $contexts['personal'] = $personalContext;
            }
        }

        return $contexts;
    }

    private function buildRetryNotice(array $retrySummary, array $errors): ?array
    {
        $retryCount = max(0, (int) ($retrySummary['retry_count'] ?? 0));
        $nextRetryTimestamp = is_int($retrySummary['next_retry'] ?? null) ? (int) $retrySummary['next_retry'] : 0;
        $retryLimitReached = !empty($retrySummary['retry_limit_reached']);

        if ($nextRetryTimestamp > 0) {
            return [
                'type' => 'warning',
                'title' => __('PostCaster will retry failed targets automatically.', 'postcaster'),
                'message' => sprintf(
                    /* translators: 1: retry attempt count, 2: timestamp of next retry. */
                    __('Retry attempt %1$d is queued in the background scheduler. If background processing runs normally, the next retry should happen around %2$s.', 'postcaster'),
                    max(1, $retryCount),
                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), $nextRetryTimestamp)
                ),
            ];
        }

        if ($retryLimitReached && $errors !== []) {
            return [
                'type' => 'error',
                'title' => __('PostCaster stopped retrying automatically for this article.', 'postcaster'),
                'message' => __('The retry limit was reached. Fix the reported problem below, then publish manually if you want to try again immediately.', 'postcaster'),
            ];
        }

        if ($retryCount > 0 && $errors !== []) {
            return [
                'type' => 'warning',
                'title' => __('PostCaster recorded one or more failed attempts for this article.', 'postcaster'),
                'message' => __('Check the target-specific errors below. If the underlying problem is already fixed, you can publish manually from this page.', 'postcaster'),
            ];
        }

        return null;
    }

    /**
     * @return array<int, array{network_label:string, target_label:string, remote_url:string}>
     */
    private function buildRemotePublicationsList(int $postId): array
    {
        $rows = [];
        $networks = \Justbee\PostCaster\Plugin::instance()->getNetworks();

        foreach ($this->postMeta->getRemotePublications($postId) as $publication) {
            $networkKey = (string) $publication['network'];
            $targetKey = (string) $publication['target_key'];
            $network = $networks instanceof \Justbee\PostCaster\Services\NetworkRegistry ? $networks->get($networkKey) : null;
            $networkLabel = $network ? $network->getLabel() : $networkKey;

            $targetLabel = $targetKey === 'global'
                ? __('Global accounts', 'postcaster')
                : __('Personal accounts', 'postcaster');

            $rows[] = [
                'network_label' => $networkLabel,
                'target_label' => $targetLabel,
                'remote_url' => (string) $publication['remote_url'],
            ];
        }

        return $rows;
    }
}
