<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds preview data for the admin UI and CLI.
 *
 * Owns rendering of example previews, per-target previews and the supporting
 * header/card/image scaffolding. Reuses TargetContextResolver for everything
 * that is shared with the actual publish path so the preview never drifts
 * from what gets published.
 */
final class PreviewBuilder
{
    private SettingsModel $settings;
    private PostMetaModel $postMeta;
    private MediaService $media;
    private NetworkRegistry $networks;
    private PublishTargetResolver $targets;
    private PostTemplateContextBuilder $contextBuilder;
    private TemplateDescriptionService $templateDescriptions;
    private PostRenderer $renderer;
    private TargetContextResolver $context;
    private PreviewScaffoldBuilder $scaffold;

    public function __construct(
        SettingsModel $settings,
        PostMetaModel $postMeta,
        MediaService $media,
        NetworkRegistry $networks,
        PublishTargetResolver $targets,
        PostTemplateContextBuilder $contextBuilder,
        TemplateDescriptionService $templateDescriptions,
        PostRenderer $renderer,
        TargetContextResolver $context,
        ?PreviewScaffoldBuilder $scaffold = null
    ) {
        $this->settings = $settings;
        $this->postMeta = $postMeta;
        $this->media = $media;
        $this->networks = $networks;
        $this->targets = $targets;
        $this->contextBuilder = $contextBuilder;
        $this->templateDescriptions = $templateDescriptions;
        $this->renderer = $renderer;
        $this->context = $context;
        $this->scaffold = $scaffold ?? new PreviewScaffoldBuilder($networks, $media, $context);
    }

    public function buildTestStatusText(
        string $networkKey,
        array $options,
        string $scope = 'global',
        int $userId = 0
    ): string {
        $template = $this->context->resolveTemplate($options, $networkKey);
        $limit = $this->context->getCharacterLimitForNetwork($networkKey, $options);

        $text = $this->renderer->render(
            __('Test: ', 'postcaster') . $template,
            $this->contextBuilder->buildExampleValues($networkKey, $options, $scope, $userId),
            $limit
        )->getText();

        return $this->prepareExamplePreviewText($networkKey, $options, $text, $scope, $userId);
    }

    /**
     * @return string[]
     */
    public function buildTestMentionCandidates(
        string $networkKey,
        array $options,
        string $scope = 'global',
        int $userId = 0
    ): array {
        $template = __('Test: ', 'postcaster') . $this->context->resolveTemplate($options, $networkKey);
        $values = $this->contextBuilder->buildExampleValues($networkKey, $options, $scope, $userId);

        return $this->context->extractPlaceholderMentionCandidates($template, [
            '@site' => str_starts_with((string) ($values['@site'] ?? ''), '@') ? (string) $values['@site'] : '',
            '@author' => str_starts_with((string) ($values['@author'] ?? ''), '@') ? (string) $values['@author'] : '',
        ]);
    }

    public function buildExampleStatusText(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): string {
        return $this->buildExamplePreviewData($networkKey, $template, $scope, $userId)['text'];
    }

    public function getExamplePost(string $scope = 'global', int $userId = 0): ?WP_Post
    {
        return $this->contextBuilder->getExamplePost($scope, $userId);
    }

    public function buildExamplePreviewItems(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): array {
        $preview = $this->buildExamplePreviewData($networkKey, $template, $scope, $userId);

        if ($networkKey === null || $networkKey === '') {
            $values = $this->contextBuilder->buildExampleValues(null, $this->settings->get(), $scope, $userId);

            return [[
                'network' => '',
                'label' => '',
                'header' => $this->scaffold->buildHeaderData(
                    '',
                    __('General preview', 'postcaster'),
                    $values,
                    $scope === 'personal'
                ),
                'text' => (string) ($preview['text'] ?? ''),
                'image' => is_array($preview['image'] ?? null) ? $preview['image'] : null,
                'card' => is_array($preview['card'] ?? null) ? $preview['card'] : null,
                'warning' => null,
            ]];
        }

        $network = $this->networks->get($networkKey);
        $header = $network
            ? $this->scaffold->buildHeaderData(
                $networkKey,
                $network->getLabel(),
                $this->contextBuilder->buildExampleValues($networkKey, $this->settings->get(), $scope, $userId),
                $scope === 'personal'
            )
            : null;

        return [[
            'network' => $networkKey,
            'label' => $network ? $network->getLabel() : '',
            'header' => $header,
            'text' => (string) ($preview['text'] ?? ''),
            'image' => is_array($preview['image'] ?? null) ? $preview['image'] : null,
            'card' => is_array($preview['card'] ?? null) ? $preview['card'] : null,
            'warning' => is_string($preview['warning'] ?? null) ? $preview['warning'] : null,
        ]];
    }

    public function buildExamplePreviewData(
        ?string $networkKey = null,
        ?string $template = null,
        string $scope = 'global',
        int $userId = 0
    ): array {
        $options = $this->settings->get();
        if ($scope === 'personal' && $userId > 0) {
            $context = $this->targets->getPersonalEditorContext($userId, $options);
            if ($context !== null) {
                $options = (array) ($context['options'] ?? $options);
                $profile = (array) ($context['profile'] ?? []);
                if ($networkKey !== null) {
                    $network = $this->networks->get($networkKey);
                    if ($network !== null) {
                        $options = $network->mergeProfileIntoOptions($options, $profile);
                    }
                }
            }
        }

        $effectiveTemplate = $template !== null && trim($template) !== ''
            ? $this->renderer->decode(trim($template))
            : $this->context->resolveTemplate($options, $networkKey);

        $text = $this->renderer->render(
            $effectiveTemplate,
            $this->contextBuilder->buildExampleValues($networkKey, $options, $scope, $userId),
            PHP_INT_MAX
        )->getText();

        $preparedText = $networkKey !== null
            ? $this->prepareExamplePreviewText($networkKey, $options, $text, $scope, $userId)
            : $text;
        $image = null;
        $examplePost = $this->contextBuilder->getExamplePost($scope, $userId);
        if ($examplePost instanceof WP_Post) {
            $image = $this->scaffold->buildImageData($examplePost->ID);
        }

        $includeFeaturedImage = false;
        $renderedText = $preparedText;
        $warning = null;
        if ($networkKey !== null) {
            $exampleNetwork = $this->networks->get($networkKey);
            if ($exampleNetwork !== null) {
                $includeFeaturedImage = ($options[$exampleNetwork->optionKey('include_featured_image')] ?? '0') === '1';
                if ($examplePost instanceof WP_Post) {
                    $preparedText = $exampleNetwork->finalizePostText($examplePost, $options, $preparedText, $includeFeaturedImage);

                    // Suppress the standalone image when the network would
                    // neither render a card nor attach the asset directly,
                    // so the preview does not promise media the timeline
                    // would not actually receive.
                    $rendersCard = $exampleNetwork->shouldRenderPreviewCard($examplePost, $renderedText, $options, $includeFeaturedImage);
                    $attachesAsset = $exampleNetwork->shouldAttachAsset($examplePost, $renderedText, $options, $includeFeaturedImage);
                    if (!$rendersCard && !$attachesAsset) {
                        $image = null;
                    }

                    $warning = $exampleNetwork->getPreviewWarning($examplePost, $renderedText, $options, $includeFeaturedImage);
                }
            }
        }

        return [
            'text' => $preparedText,
            'image' => $image,
            'card' => $examplePost instanceof WP_Post
                ? $this->scaffold->buildCardData($networkKey ?? '', $examplePost, $renderedText, $image, $options, $includeFeaturedImage)
                : null,
            'warning' => $warning,
        ];
    }

    public function getContextPreviewTexts(WP_Post $post, string $context = 'global', ?string $templateOverride = null): array
    {
        return $this->getContextPreviewData($post, $context, $templateOverride)['items'];
    }

    /**
     * Generic combined preview for the "All accounts" view.
     *
     * Renders the template with the first target's options for placeholder
     * substitution, but skips network-specific post-processing (e.g. Bluesky's
     * URL strip) and uses the generic "URL in text → card" rule. The result
     * represents what most networks would publish, not any single network's
     * exact rendering.
     */
    public function getCombinedPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        $targets = $this->context->getContextTargets($post, $context);
        if ($targets === []) {
            return ['items' => [], 'text' => '', 'image' => null, 'card' => null];
        }

        $first = $targets[0];
        $networkKey = (string) $first['network_key'];
        $targetKey = (string) $first['target_key'];
        $targetOptions = (array) $first['options'];

        // The combined preview must reflect the scope-wide / general template,
        // never a per-network override. Resolve explicitly so single-network
        // scopes don't accidentally render the lone network's per-network
        // template override.
        $effectiveTemplate = $templateOverride;
        if ($effectiveTemplate === null) {
            $scopeTemplate = trim((string) $this->postMeta->getPostTemplate($post->ID, $context));
            $effectiveTemplate = $scopeTemplate !== ''
                ? $scopeTemplate
                : $this->context->resolveTemplate($targetOptions, null);
        }

        $message = $this->context->buildRenderedPostMessage(
            $post,
            $networkKey,
            $targetKey,
            $targetOptions,
            $this->renderer->decode(trim((string) $effectiveTemplate))
        );
        $renderedText = $message->getText();

        $image = $includeFeaturedImageOverride === false ? null : $this->scaffold->buildImageData($post->ID);
        $card = $this->scaffold->buildCardData('', $post, $renderedText, $image);

        $label = __('All accounts', 'postcaster');
        $item = [
            'network' => '',
            'label' => $label,
            // Pass null for network/target so the values do not pull a
            // network-specific @handle into the combined header — the
            // "all accounts" preview must not pretend to speak from a
            // single network's account.
            'header' => $this->scaffold->buildHeaderData(
                '',
                $label,
                $this->contextBuilder->buildPostValues($post, null, null, $targetOptions),
                $targetKey !== 'global'
            ),
            'text' => $renderedText,
            'card' => $card,
        ];

        return [
            'items' => [$item],
            'text' => $renderedText,
            'image' => $image,
            'card' => $card,
        ];
    }

    public function getContextPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        $previews = [];

        foreach ($this->context->getContextTargets($post, $context) as $target) {
            $networkKey = (string) $target['network_key'];
            $targetKey = (string) $target['target_key'];
            $targetOptions = (array) $target['options'];
            $network = $this->networks->get($networkKey);
            if (!$network) {
                continue;
            }

            $preview = $this->buildPreviewForTarget(
                $post,
                $networkKey,
                $targetKey,
                $targetOptions,
                $network->getLabel(),
                $templateOverride !== null ? $this->renderer->decode(trim($templateOverride)) : null
            );

            $preview['label'] = $this->buildTargetPreviewLabel($network->getLabel(), $targetKey);
            $previews[] = $preview;
        }

        return [
            'items' => $previews,
            'text' => (string) ($previews[0]['text'] ?? ''),
            'image' => $this->scaffold->shouldPreviewFeaturedImageForContext($post, $context, $includeFeaturedImageOverride)
                ? $this->scaffold->buildImageData($post->ID)
                : null,
            'card' => $previews[0]['card'] ?? null,
        ];
    }

    /**
     * Build a per-network grouping of preview items for a given scope.
     *
     * Returns a network-keyed array; each entry has 'label' and 'preview' (the
     * same shape as a single getContextPreviewData() item).
     *
     * @return array<string, array{label: string, preview: array}>
     */
    public function getPerNetworkPreviewData(
        WP_Post $post,
        string $context = 'global',
        ?string $templateOverride = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        $perNetwork = [];

        foreach ($this->context->getContextTargets($post, $context) as $target) {
            $networkKey = (string) $target['network_key'];
            $targetKey = (string) $target['target_key'];
            $targetOptions = (array) $target['options'];
            $network = $this->networks->get($networkKey);
            if (!$network) {
                continue;
            }

            // First target per network wins — additional ones are merged into
            // the same group via items[].
            if (!isset($perNetwork[$networkKey])) {
                $perNetwork[$networkKey] = [
                    'label' => $network->getLabel(),
                    'items' => [],
                ];
            }

            $preview = $this->buildPreviewForTarget(
                $post,
                $networkKey,
                $targetKey,
                $targetOptions,
                $network->getLabel(),
                $templateOverride !== null ? $this->renderer->decode(trim($templateOverride)) : null,
                $includeFeaturedImageOverride
            );
            $preview['label'] = $this->buildTargetPreviewLabel($network->getLabel(), $targetKey);

            $perNetwork[$networkKey]['items'][] = $preview;
        }

        return $perNetwork;
    }

    public function describeResolvedContextTemplate(WP_Post $post, string $context, array $contextDescription): array
    {
        $resolvedTemplates = [];

        foreach ($this->context->getContextTargets($post, $context) as $target) {
            $resolvedTemplates[] = $this->context->resolveTargetTemplateDescription(
                (string) $target['network_key'],
                (string) $target['target_key'],
                (array) $target['options']
            );
        }

        return $this->templateDescriptions->collapseDescriptions($resolvedTemplates, $contextDescription);
    }

    public function describePostPreviewTemplate(WP_Post $post): array
    {
        $postTemplate = $this->renderer->decode(trim($this->postMeta->getPostTemplate($post->ID)));

        if ($postTemplate !== '') {
            return [
                'label' => __('Own article template', 'postcaster'),
                'template' => $postTemplate,
            ];
        }

        return $this->templateDescriptions->describeGeneralTemplate($this->settings->get());
    }

    /**
     * @param array<string, array<string, array>> $targets
     */
    public function buildTargetPreviews(WP_Post $post, array $targets, string $rawPostTemplate): array
    {
        $postTemplate = $this->renderer->decode(trim($rawPostTemplate));
        $previews = [];

        foreach ($targets as $networkKey => $networkTargets) {
            $network = $this->networks->get((string) $networkKey);
            if (!$network) {
                continue;
            }

            foreach ($networkTargets as $targetKey => $targetOptions) {
                $previews[] = $this->buildPreviewForTarget(
                    $post,
                    (string) $networkKey,
                    (string) $targetKey,
                    (array) $targetOptions,
                    $network->getLabel(),
                    $postTemplate
                );
            }
        }

        return $previews;
    }

    private function buildPreviewForTarget(
        WP_Post $post,
        string $networkKey,
        string $targetKey,
        array $targetOptions,
        string $networkLabel,
        ?string $postTemplate = null,
        ?bool $includeFeaturedImageOverride = null
    ): array {
        $message = $this->context->buildRenderedPostMessage($post, $networkKey, $targetKey, $targetOptions, $postTemplate);
        $limit = $this->context->getCharacterLimitForNetwork($networkKey, $targetOptions);
        $network = $this->networks->get($networkKey);
        $prepared = $network !== null
            ? $this->context->buildPreparedTextData($post, $network, $networkKey, $targetOptions, $message)
            : [
                'text' => $message->getText(),
                'length' => $message->getLength(),
                'fits' => $message->fits(),
                'truncated' => $message->wasTruncated(),
                'limit' => $limit,
            ];

        $shouldIncludeFeaturedImage = false;
        $renderedText = $prepared['text'];
        if ($network !== null) {
            $shouldIncludeFeaturedImage = $includeFeaturedImageOverride ?? $this->context->shouldIncludeFeaturedImageForTarget($post, $network, $targetKey, $targetOptions);
            $prepared['text'] = $network->finalizePostText($post, $targetOptions, $prepared['text'], $shouldIncludeFeaturedImage);
            $prepared['length'] = mb_strlen((string) $prepared['text'], 'UTF-8');
            $prepared['fits'] = $prepared['length'] <= $prepared['limit'];
        }

        $warning = $network !== null
            ? $network->getPreviewWarning($post, $renderedText, $targetOptions, $shouldIncludeFeaturedImage)
            : null;

        $image = $shouldIncludeFeaturedImage ? $this->scaffold->buildImageData($post->ID) : null;

        return [
            'network' => $networkKey,
            'network_label' => $networkLabel,
            'target_key' => $targetKey,
            'header' => $this->scaffold->buildHeaderData(
                $networkKey,
                $networkLabel,
                $this->contextBuilder->buildPostValues($post, $networkKey, $targetKey, $targetOptions),
                $targetKey !== 'global'
            ),
            'limit' => $prepared['limit'],
            'length' => $prepared['length'],
            'fits' => $prepared['fits'],
            'truncated' => $prepared['truncated'],
            'text' => $prepared['text'],
            'image' => $image,
            'card' => $this->scaffold->buildCardData(
                $networkKey,
                $post,
                $renderedText,
                $image,
                $targetOptions,
                $shouldIncludeFeaturedImage
            ),
            'warning' => $warning,
        ];
    }

    private function prepareExamplePreviewText(
        string $networkKey,
        array $options,
        string $text,
        string $scope,
        int $userId
    ): string {
        $network = $this->networks->get($networkKey);
        if ($network === null) {
            return $text;
        }

        $post = $this->contextBuilder->getExamplePost($scope, $userId);
        if (!$post instanceof WP_Post) {
            return $text;
        }

        return $this->context->prepareNetworkPostText($network, $post, $options, $text);
    }

    private function buildTargetPreviewLabel(string $networkLabel, string $targetKey): string
    {
        $user = $this->context->resolveTargetUser($targetKey);
        if ($user && !empty($user->display_name)) {
            return sprintf(
                /* translators: 1: network label, 2: user display name. */
                __('%1$s (%2$s)', 'postcaster'),
                $networkLabel,
                $user->display_name
            );
        }

        return $networkLabel;
    }

}
