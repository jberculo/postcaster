<?php

namespace Justbee\PostCaster\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateDescriptionService
{
    private NetworkRegistry $networks;
    private string $defaultTemplate;

    public function __construct(NetworkRegistry $networks, string $defaultTemplate)
    {
        $this->networks = $networks;
        $this->defaultTemplate = $defaultTemplate;
    }

    public function describeGeneralTemplate(array $globalOptions, array $profile = []): array
    {
        return $this->resolveTemplateDescriptionFromOptions($globalOptions, $profile);
    }

    public function describeGeneralFallbackTemplate(): array
    {
        return $this->getPluginDefaultTemplateDescription();
    }

    public function describeNetworkTemplate(string $networkKey, array $globalOptions, array $profile = []): array
    {
        return $this->resolveTemplateDescriptionFromOptions($globalOptions, $profile, $networkKey);
    }

    public function describeNetworkFallbackTemplate(string $networkKey, array $globalOptions, array $profile = []): array
    {
        if ($profile !== []) {
            $network = $this->networks->get($networkKey);
            if ($network) {
                $profile[$network->optionKey('template_enabled')] = '0';
                $profile[$network->optionKey('template')] = '';
            }

            return $this->describeNetworkTemplate($networkKey, $globalOptions, $profile);
        }

        return $this->describeGeneralTemplate($globalOptions);
    }

    public function collapseDescriptions(array $descriptions, array $fallback): array
    {
        if ($descriptions === []) {
            return $fallback;
        }

        $uniqueTemplates = [];
        foreach ($descriptions as $description) {
            $signature = md5(
                (string) ($description['label'] ?? '')
                . "\n"
                . (string) ($description['template'] ?? '')
            );
            $uniqueTemplates[$signature] = $description;
        }

        if (count($uniqueTemplates) === 1) {
            return array_values($uniqueTemplates)[0];
        }

        return $fallback;
    }

    private function resolveTemplateDescriptionFromOptions(array $globalOptions, array $profile = [], ?string $networkKey = null): array
    {
        $isPersonalContext = $profile !== [];
        $network = $networkKey !== null ? $this->networks->get($networkKey) : null;
        $templateEnabledKey = $network ? $network->optionKey('template_enabled') : null;
        $templateKey = $network ? $network->optionKey('template') : null;

        if ($network && $isPersonalContext) {
            $profileNetworkTemplate = $this->getEnabledTemplateValue($profile, $templateEnabledKey, $templateKey);
            if ($profileNetworkTemplate !== '') {
                return [
                    'label' => __('Own personal network template', 'postcaster'),
                    'template' => $profileNetworkTemplate,
                ];
            }
        }

        if ($isPersonalContext) {
            $profileGeneralTemplate = $this->getEnabledTemplateValue($profile, 'profile_template_enabled', 'profile_template');
            if ($profileGeneralTemplate !== '') {
                return [
                    'label' => __('Own personal general template', 'postcaster'),
                    'template' => $profileGeneralTemplate,
                ];
            }
        }

        if ($network) {
            $globalNetworkTemplate = $this->getEnabledTemplateValue($globalOptions, $templateEnabledKey, $templateKey);
            if ($globalNetworkTemplate !== '') {
                return [
                    'label' => __('Own network template', 'postcaster'),
                    'template' => $globalNetworkTemplate,
                ];
            }
        }

        $globalGeneralTemplate = $this->getEnabledTemplateValue($globalOptions, 'template_enabled', 'template');
        if ($globalGeneralTemplate !== '') {
            return [
                'label' => __('Own general template', 'postcaster'),
                'template' => $globalGeneralTemplate,
            ];
        }

        return $this->getPluginDefaultTemplateDescription();
    }

    private function getEnabledTemplateValue(array $options, string $enabledKey, string $templateKey): string
    {
        if (($options[$enabledKey] ?? '0') !== '1') {
            return '';
        }

        return trim((string) ($options[$templateKey] ?? ''));
    }

    private function getPluginDefaultTemplateDescription(): array
    {
        return [
            'label' => __('Inherited from plugin default template', 'postcaster'),
            'template' => $this->defaultTemplate,
        ];
    }
}
