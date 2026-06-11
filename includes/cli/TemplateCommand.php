<?php

namespace Justbee\PostCaster\Cli;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateCommand extends AbstractCliCommand
{
    public function template(array $args, array $assocArgs): void
    {
        $post = $this->getPostOrExit((int) ($args[0] ?? 0));

        if (isset($assocArgs['set'])) {
            $globalContext = $this->publisher->getGlobalPublishingContext();
            $defaultTemplate = (string) ($globalContext['default_template'] ?? $this->publisher->getDefaultPostTemplate());
            $this->postMeta->savePostTemplate($post->ID, (string) $assocArgs['set'], $defaultTemplate);
            \WP_CLI::success(sprintf(
                /* translators: %d: WordPress post ID. */
                __('Updated the post-specific template for post %d.', 'postcaster'),
                $post->ID
            ));
            return;
        }

        $description = $this->publisher->describePostPreviewTemplate($post);
        \WP_CLI::log(sprintf(
            /* translators: %s: label describing where the resolved template came from. */
            __('Template source: %s', 'postcaster'),
            (string) ($description['label'] ?? '')
        ));
        \WP_CLI::log((string) ($description['template'] ?? ''));
    }
}
