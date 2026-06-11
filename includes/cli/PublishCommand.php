<?php

namespace Justbee\PostCaster\Cli;

if (!defined('ABSPATH')) {
    exit;
}

final class PublishCommand extends AbstractCliCommand
{
    public function publish(array $args, array $assocArgs): void
    {
        $post = $this->getPostOrExit((int) ($args[0] ?? 0));
        $includePersonal = isset($assocArgs['include-personal']);
        $allowRepost = isset($assocArgs['force']);

        $diagnosis = $this->publisher->getPublishDiagnosis($post, [
            'include_personal_networks' => $includePersonal,
        ]);
        if (!$diagnosis['should_publish']) {
            \WP_CLI::error(implode(' ', (array) ($diagnosis['reasons'] ?? [])));
        }

        $hadFailures = $this->publisher->publishPost($post, [
            'include_personal_networks' => $includePersonal,
            'allow_repost' => $allowRepost,
        ]);

        if ($hadFailures) {
            \WP_CLI::warning(__('PostCaster published to one or more targets, but at least one target failed. Inspect the logs or article errors.', 'postcaster'));
            return;
        }

        \WP_CLI::success(sprintf(
            /* translators: %d: WordPress post ID. */
            __('Post %d published through PostCaster.', 'postcaster'),
            $post->ID
        ));
    }

    public function preview(array $args, array $assocArgs): void
    {
        $post = $this->getPostOrExit((int) ($args[0] ?? 0));
        $networkFilter = isset($assocArgs['network']) ? sanitize_key((string) $assocArgs['network']) : '';
        $previews = $this->publisher->getPreviewTexts($post, [
            'include_personal_networks' => isset($assocArgs['include-personal']),
        ]);

        if ($networkFilter !== '') {
            $previews = array_values(array_filter($previews, static function (array $preview) use ($networkFilter): bool {
                return ($preview['network'] ?? '') === $networkFilter;
            }));
        }

        if ($previews === []) {
            \WP_CLI::warning(__('No PostCaster preview targets found for this post.', 'postcaster'));
            return;
        }

        foreach ($previews as $preview) {
            \WP_CLI::log(sprintf(
                '[%s/%s] %d/%d characters',
                (string) $preview['network'],
                (string) $preview['target_key'],
                (int) $preview['length'],
                (int) $preview['limit']
            ));
            \WP_CLI::log((string) $preview['text']);
            \WP_CLI::log('');
        }
    }
}
