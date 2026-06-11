<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Templates\RenderedMessage;
use WP_Post;
use WP_User;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared lookups around publishing targets, options, templates and character limits.
 *
 * Both PublisherService (publish path) and PreviewBuilder (preview path) lean on
 * these helpers, so they live here as a single dependency that can be injected
 * into either side.
 */
final class TargetContextResolver
{
    private SettingsModel $settings;
    private PostMetaModel $postMeta;
    private NetworkRegistry $networks;
    private PublishTargetResolver $targets;
    private PostRenderer $renderer;
    private PostTemplateContextBuilder $contextBuilder;
    private TemplateDescriptionService $templateDescriptions;

    public function __construct(
        SettingsModel $settings,
        PostMetaModel $postMeta,
        NetworkRegistry $networks,
        PublishTargetResolver $targets,
        PostRenderer $renderer,
        PostTemplateContextBuilder $contextBuilder,
        TemplateDescriptionService $templateDescriptions
    ) {
        $this->settings = $settings;
        $this->postMeta = $postMeta;
        $this->networks = $networks;
        $this->targets = $targets;
        $this->renderer = $renderer;
        $this->contextBuilder = $contextBuilder;
        $this->templateDescriptions = $templateDescriptions;
    }

    public function getPublishTargets(
        WP_Post $post,
        array $globalOptions,
        bool $includePersonalNetworks = true,
        bool $includeGlobalNetworks = true
    ): array {
        return $this->targets->getTargets($post, $globalOptions, $includePersonalNetworks, $includeGlobalNetworks);
    }

    public function getContextTargets(WP_Post $post, string $context): array
    {
        $includeGlobalNetworks = $context !== 'personal';
        $includePersonalNetworks = $context !== 'global';
        $targets = $this->getPublishTargets($post, $this->settings->get(), $includePersonalNetworks, $includeGlobalNetworks);
        $contextTargets = [];

        foreach ($targets as $networkKey => $networkTargets) {
            foreach ($networkTargets as $targetKey => $targetOptions) {
                if ($this->getTemplateContextForTarget((string) $targetKey, (array) $targetOptions) !== $context) {
                    continue;
                }

                $contextTargets[] = [
                    'network_key' => (string) $networkKey,
                    'target_key' => (string) $targetKey,
                    'options' => $targetOptions,
                ];
            }
        }

        return $contextTargets;
    }

    public function getTemplateContextForTarget(string $targetKey, array $targetOptions = []): string
    {
        $explicitContext = (string) ($targetOptions['justbee_postcaster_template_context'] ?? '');
        if (in_array($explicitContext, ['global', 'personal'], true)) {
            return $explicitContext;
        }

        return $targetKey === 'global' ? 'global' : 'personal';
    }

    public function getProfileForTarget(string $targetKey, array $globalOptions): array
    {
        $userId = $this->parseTargetUserId($targetKey);
        if ($userId === null) {
            return [];
        }

        $personalContext = $this->targets->getPersonalEditorContext($userId, $globalOptions);

        return is_array($personalContext['profile'] ?? null) ? $personalContext['profile'] : [];
    }

    public function parseTargetUserId(string $targetKey): ?int
    {
        if (!str_starts_with($targetKey, 'user_')) {
            return null;
        }

        $userId = (int) substr($targetKey, 5);

        return $userId > 0 ? $userId : null;
    }

    public function resolveTargetUser(string $targetKey): ?WP_User
    {
        $userId = $this->parseTargetUserId($targetKey);
        if ($userId === null) {
            return null;
        }

        $user = get_user_by('id', $userId);

        return $user instanceof WP_User ? $user : null;
    }

    public function describeLogTarget(string $targetKey): string
    {
        if ($targetKey === 'global') {
            return 'global';
        }

        $user = $this->resolveTargetUser($targetKey);
        if ($user && !empty($user->user_login)) {
            return (string) $user->user_login;
        }

        return $targetKey;
    }

    public function shouldIncludeFeaturedImage($network, array $targetOptions, ?string $includeFeaturedImageOverride): bool
    {
        if ($includeFeaturedImageOverride !== null) {
            return $includeFeaturedImageOverride === '1';
        }

        return ($targetOptions[$network->optionKey('include_featured_image')] ?? '0') === '1';
    }

    /**
     * Resolve the effective featured-image flag for a given target on a post.
     *
     * Looks up per-(post, scope, network) override → scope-level override → network plugin default.
     */
    public function shouldIncludeFeaturedImageForTarget(WP_Post $post, $network, string $targetKey, array $targetOptions): bool
    {
        $scope = $this->getTemplateContextForTarget($targetKey, $targetOptions);
        $networkDefault = ($targetOptions[$network->optionKey('include_featured_image')] ?? '0') === '1';

        return $this->postMeta->resolveIncludeFeaturedImage($post->ID, $scope, $network->getKey(), $networkDefault);
    }

    public function resolveTemplate(array $options, ?string $networkKey): string
    {
        if ($networkKey !== null) {
            $network = $this->networks->get($networkKey);
            if ($network) {
                $templateEnabledKey = $network->optionKey('template_enabled');
                $templateKey = $network->optionKey('template');
                $networkTemplate = trim((string) ($options[$templateKey] ?? ''));

                if (($options[$templateEnabledKey] ?? '0') === '1' && $networkTemplate !== '') {
                    return $this->renderer->decode($networkTemplate);
                }
            }
        }

        $template = trim((string) ($options['template'] ?? ''));
        if (($options['template_enabled'] ?? '0') === '1' && $template !== '') {
            return $this->renderer->decode($template);
        }

        return $this->renderer->decode((string) $this->settings->defaults()['template']);
    }

    public function getCharacterLimitForNetwork(string $networkKey, array $options): int
    {
        $network = $this->networks->get($networkKey);
        if (!$network) {
            return PHP_INT_MAX;
        }

        $limit = (int) ($options[$network->optionKey('character_limit')] ?? $network->getCharacterLimit());

        return $limit > 0 ? $limit : $network->getCharacterLimit();
    }

    public function buildRenderedPostMessage(
        WP_Post $post,
        string $networkKey,
        string $targetKey,
        array $targetOptions,
        ?string $postTemplate = null
    ): RenderedMessage {
        $templateContext = $this->getTemplateContextForTarget($targetKey, $targetOptions);
        $effectivePostTemplate = $postTemplate ?? $this->renderer->decode(trim($this->postMeta->resolvePostTemplate($post->ID, $templateContext, $networkKey)));
        $templateDescription = $this->resolveTargetTemplateDescription(
            $networkKey,
            $targetKey,
            $targetOptions,
            $effectivePostTemplate !== '' ? $effectivePostTemplate : null
        );

        return $this->renderer->render(
            (string) ($templateDescription['template'] ?? ''),
            $this->contextBuilder->buildPostValues($post, $networkKey, $targetKey, $targetOptions),
            $this->getCharacterLimitForNetwork($networkKey, $targetOptions)
        );
    }

    /**
     * @return string[]
     */
    public function buildPlaceholderMentionCandidates(
        WP_Post $post,
        string $networkKey,
        string $targetKey,
        array $targetOptions,
        ?string $postTemplate = null
    ): array {
        $templateContext = $this->getTemplateContextForTarget($targetKey, $targetOptions);
        $effectivePostTemplate = $postTemplate ?? $this->renderer->decode(trim($this->postMeta->resolvePostTemplate($post->ID, $templateContext, $networkKey)));
        $templateDescription = $this->resolveTargetTemplateDescription(
            $networkKey,
            $targetKey,
            $targetOptions,
            $effectivePostTemplate !== '' ? $effectivePostTemplate : null
        );

        return $this->extractPlaceholderMentionCandidates(
            (string) ($templateDescription['template'] ?? ''),
            $this->contextBuilder->buildPlaceholderMentionValues($post, $networkKey, $targetKey, $targetOptions)
        );
    }

    /**
     * @param array{'@site': string, '@author': string} $mentionValues
     * @return string[]
     */
    public function extractPlaceholderMentionCandidates(string $template, array $mentionValues): array
    {
        if ($template === '') {
            return [];
        }

        if (!preg_match_all('/\{@site\}|\{@author\}/', $template, $matches)) {
            return [];
        }

        $candidates = [];

        foreach ($matches[0] as $placeholder) {
            $placeholderKey = str_replace(['{', '}'], '', (string) $placeholder);
            $candidate = trim((string) ($mentionValues[$placeholderKey] ?? ''));
            if ($candidate === '' || !str_starts_with($candidate, '@')) {
                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    public function resolveTargetTemplateDescription(
        string $networkKey,
        string $targetKey,
        array $targetOptions,
        ?string $postTemplateOverride = null
    ): array {
        $postTemplateOverride = $postTemplateOverride !== null ? $this->renderer->decode(trim($postTemplateOverride)) : null;
        if ($postTemplateOverride !== null && $postTemplateOverride !== '') {
            return [
                'label' => __('Own article template', 'postcaster'),
                'template' => $postTemplateOverride,
            ];
        }

        $globalOptions = $this->settings->get();
        $templateContext = $this->getTemplateContextForTarget($targetKey, $targetOptions);

        if ($templateContext === 'personal') {
            return $this->templateDescriptions->describeNetworkTemplate($networkKey, $globalOptions, $this->getProfileForTarget($targetKey, $globalOptions));
        }

        return $this->templateDescriptions->describeNetworkTemplate($networkKey, $globalOptions);
    }

    public function buildPreparedTextData(
        WP_Post $post,
        $network,
        string $networkKey,
        array $targetOptions,
        RenderedMessage $message
    ): array {
        $limit = $this->getCharacterLimitForNetwork($networkKey, $targetOptions);
        $text = apply_filters(
            'justbee_postcaster_post_text',
            $this->prepareNetworkPostText($network, $post, $targetOptions, $message->getText()),
            $networkKey,
            $post
        );

        return [
            'text' => $text,
            'length' => mb_strlen($text, 'UTF-8'),
            'fits' => mb_strlen($text, 'UTF-8') <= $limit,
            'truncated' => $message->wasTruncated(),
            'limit' => $limit,
        ];
    }

    public function prepareNetworkPostText($network, WP_Post $post, array $options, string $text): string
    {
        $preparedText = $network->preparePostText($post, $options, $text);

        if ($text !== '' && trim($preparedText) === '') {
            return $text;
        }

        return $preparedText;
    }

}
