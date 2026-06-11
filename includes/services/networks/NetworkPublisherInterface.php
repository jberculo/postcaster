<?php

namespace Justbee\PostCaster\Services\Networks;

use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

interface NetworkPublisherInterface
{
    public function getKey(): string;

    public function optionKey(string $suffix): string;

    public function getLabel(): string;

    public function getCharacterLimit(): int;

    public function getGlobalDefaults(): array;

    public function getProfileDefaults(): array;

    public function sanitizeGlobal(array $input, array $defaults): array;

    public function sanitizeProfile(array $input, array $defaults): array;

    /**
     * Option key suffixes whose stored values are secrets and must be encrypted at rest.
     *
     * @return string[]
     */
    public function secretFieldKeys(): array;

    public function mergeProfileIntoOptions(array $globalOptions, array $profile): array;

    public function getAdminFields(): array;

    public function getProfileFields(): array;

    public function getSetupNotice(): ?array;

    public function getAccountReference(array $options): string;

    public function formatAccountReference(string $reference): string;

    public function isConfigured(array $options): bool;

    /**
     * Prepare the final network-specific post text.
     *
     * Implementations should not return an empty string when the input text is non-empty.
     */
    public function preparePostText(WP_Post $post, array $options, string $text): string;

    /**
     * Apply network-specific post-processing once the publish-time flags
     * (such as whether a featured image will be attached) are known.
     *
     * Default implementations should return the text unchanged.
     */
    public function finalizePostText(WP_Post $post, array $options, string $text, bool $includeFeaturedImage): string;

    /**
     * Brand colours used for the small avatar tile in PostCaster previews.
     *
     * @return array{background: string, color: string}
     */
    public function getPreviewAvatarColors(): array;

    /**
     * Whether to render a link-card preview for this network alongside the post text.
     *
     * The `$text` argument is the template-rendered text BEFORE network-specific
     * finalize() post-processing, so implementations can honour template intent
     * (e.g. "user removed {url} → no card"). Each network decides for itself —
     * the abstract default returns false; networks must opt in.
     */
    public function shouldRenderPreviewCard(WP_Post $post, string $text, array $options, bool $includeFeaturedImage): bool;

    /**
     * Whether the publisher should receive the featured-image asset for this publish run.
     *
     * Networks that always attach the image inline (Mastodon, LinkedIn) just
     * follow $includeFeaturedImage. Networks that bind the asset to a card
     * embed (Bluesky) may further require that the user kept {url} in the
     * template — see shouldRenderPreviewCard.
     */
    public function shouldAttachAsset(WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): bool;

    /**
     * Optional advisory rendered alongside the per-network preview when a
     * setting may make the live timeline render differently than the
     * preview suggests. Return null when there is nothing to warn about.
     */
    public function getPreviewWarning(WP_Post $post, string $renderedText, array $options, bool $includeFeaturedImage): ?string;

    public function publish(WP_Post $post, array $options, ?array $asset, string $text);

    /**
     * Publish a test post, optionally using an example post context so
     * network-specific preview/card logic can mirror the normal publish flow.
     *
     * @param array{post?: WP_Post|null, asset?: array|null, include_featured_image?: bool} $context
     */
    public function publishTest(array $options, string $text, array $context = []);
}
