<?php

namespace Justbee\PostCaster\Templates;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateParser
{
    /**
     * @param array<string, string> $values
     * @param array<string, array{policy:string, strategy:string, priority:int}> $definitions
     * @return TemplateSegment[]
     */
    public function parse(string $template, array $values, array $definitions): array
    {
        $segments = [];
        $offset = 0;
        $pattern = $this->buildPlaceholderPattern(array_keys($definitions));

        preg_match_all($pattern, $template, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $match) {
            $placeholder = (string) $matches[1][$index][0];
            $fullMatch = (string) $match[0];
            $position = (int) $match[1];

            if ($position > $offset) {
                $segments[] = $this->createLiteralSegment(substr($template, $offset, $position - $offset));
            }

            $definition = $definitions[$placeholder];
            $segments[] = new TemplateSegment(
                (string) ($values[$placeholder] ?? ''),
                $definition['policy'],
                $definition['strategy'],
                $definition['priority'],
                $placeholder
            );

            $offset = $position + strlen($fullMatch);
        }

        if ($offset < strlen($template)) {
            $segments[] = $this->createLiteralSegment(substr($template, $offset));
        }

        return $segments;
    }

    private function buildPlaceholderPattern(array $placeholders): string
    {
        $quoted = array_map(
            static fn(string $placeholder): string => preg_quote($placeholder, '/'),
            $placeholders
        );

        return '/\{(' . implode('|', $quoted) . ')\}/';
    }

    private function createLiteralSegment(string $text): TemplateSegment
    {
        return new TemplateSegment(
            $text,
            TemplateSegment::POLICY_NEUTRAL,
            TemplateSegment::STRATEGY_ELLIPSIS,
            3,
            'literal'
        );
    }
}
