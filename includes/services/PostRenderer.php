<?php

namespace Justbee\PostCaster\Services;

use Justbee\PostCaster\Templates\RenderedMessage;
use Justbee\PostCaster\Templates\TemplateRenderer;
use Justbee\PostCaster\Templates\TemplateSegment;

if (!defined('ABSPATH')) {
    exit;
}

final class PostRenderer
{
    private TemplateRenderer $renderer;

    /** @var array<string, string> */
    private array $decodeCache = [];

    public function __construct(TemplateRenderer $renderer)
    {
        $this->renderer = $renderer;
    }

    public function render(string $template, array $values, int $limit): RenderedMessage
    {
        return $this->renderer->render(
            $template,
            $values,
            $this->placeholderDefinitions(),
            $limit,
            fn(string $text): string => $this->normalize($text)
        );
    }

    public function decode(string $text): string
    {
        if (isset($this->decodeCache[$text])) {
            return $this->decodeCache[$text];
        }

        $decoded = $text;

        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode(wp_specialchars_decode($decoded, ENT_QUOTES), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }

            $decoded = $next;
        }

        return $this->decodeCache[$text] = $decoded;
    }

    private function normalize(string $text): string
    {
        $normalized = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $this->decode(trim($normalized));
    }

    /** @return array<string, array{policy: string, strategy: string, priority: int}> */
    private function placeholderDefinitions(): array
    {
        return [
            'title' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            'site' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            'post' => $this->definition(TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_ELLIPSIS, 1),
            'excerpt' => $this->definition(TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_ELLIPSIS, 1),
            'category' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            'cat_desc' => $this->definition(TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_ELLIPSIS, 1),
            'date' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            'modified' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            'url' => $this->definition(TemplateSegment::POLICY_FORBIDDEN, TemplateSegment::STRATEGY_NONE, 99),
            'author' => $this->definition(TemplateSegment::POLICY_NEUTRAL, TemplateSegment::STRATEGY_ELLIPSIS, 2),
            '@site' => $this->definition(TemplateSegment::POLICY_FORBIDDEN, TemplateSegment::STRATEGY_NONE, 99),
            '@author' => $this->definition(TemplateSegment::POLICY_FORBIDDEN, TemplateSegment::STRATEGY_NONE, 99),
            'tags' => $this->definition(TemplateSegment::POLICY_PREFER, TemplateSegment::STRATEGY_DROP_ITEMS, 1),
        ];
    }

    /** @return array{policy: string, strategy: string, priority: int} */
    private function definition(string $policy, string $strategy, int $priority): array
    {
        return [
            'policy' => $policy,
            'strategy' => $strategy,
            'priority' => $priority,
        ];
    }
}
