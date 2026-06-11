<?php

declare(strict_types=1);

use Justbee\PostCaster\Models\PostMetaModel;
use Justbee\PostCaster\Models\SettingsModel;
use Justbee\PostCaster\Models\UserProfileModel;
use Justbee\PostCaster\Services\MediaService;
use Justbee\PostCaster\Services\NetworkRegistry;
use Justbee\PostCaster\Services\PostRenderer;
use Justbee\PostCaster\Services\PostTemplateContextBuilder;
use Justbee\PostCaster\Services\PublisherService;
use Justbee\PostCaster\Services\PublishQueueService;
use Justbee\PostCaster\Services\PublishTargetResolver;
use Justbee\PostCaster\Services\TemplateDescriptionService;
use Justbee\PostCaster\Templates\TemplateFitter;
use Justbee\PostCaster\Templates\TemplateParser;
use Justbee\PostCaster\Templates\TemplateRenderer;

trait BuildsPublisherStack
{
    protected FakeNetworkPublisher $fake;
    protected PublisherService $publisher;
    protected PublishQueueService $queue;
    protected PostMetaModel $postMeta;

    protected function buildPublisherStack(array $optionOverrides = []): void
    {
        $this->fake = new FakeNetworkPublisher('fake');
        $networks = new NetworkRegistry([$this->fake]);

        $settings = new SettingsModel($networks);
        update_option(SettingsModel::OPTION_NAME, array_merge([
            'enabled' => '1',
            'personal_networks_enabled' => '0',
            'debug' => '0',
            'post_types' => ['post'],
            'template_enabled' => '1',
            'template' => $settings->getDefaultTemplate(),
            $this->fake->optionKey('enabled') => '1',
            $this->fake->optionKey('template_enabled') => '0',
            $this->fake->optionKey('template') => '',
            $this->fake->optionKey('include_featured_image') => '0',
            $this->fake->optionKey('character_limit') => '500',
        ], $optionOverrides));

        $profiles = new UserProfileModel($settings, $networks);
        $this->postMeta = new PostMetaModel();
        $media = new MediaService();
        $targets = new PublishTargetResolver($profiles, $networks, $settings);
        $contextBuilder = new PostTemplateContextBuilder($settings, $profiles, $networks);
        $descriptions = new TemplateDescriptionService($networks, $settings->getDefaultTemplate());
        $renderer = new PostRenderer(new TemplateRenderer(new TemplateParser(), new TemplateFitter()));

        $this->publisher = new PublisherService(
            $settings,
            $this->postMeta,
            $media,
            $networks,
            $targets,
            $contextBuilder,
            $descriptions,
            $renderer
        );
        $this->queue = new PublishQueueService($this->publisher, $this->postMeta);
    }
}
