<?php

namespace Justbee\PostCaster\Templates;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateRenderer
{
    public function __construct(
        private TemplateParser $parser,
        private TemplateFitter $fitter
    ) {
    }

    /**
     * @param array<string, string> $values
     * @param array<string, array{policy:string, strategy:string, priority:int}> $definitions
     */
    public function render(
        string $template,
        array $values,
        array $definitions,
        int $limit,
        ?callable $normalizer = null
    ): RenderedMessage {
        $segments = $this->parser->parse($template, $values, $definitions);
        $originalLength = $this->fitter->segmentsLength($segments);
        $fittedSegments = $this->fitter->fit($segments, $limit);
        $text = $this->joinSegments($fittedSegments);

        if ($normalizer !== null) {
            $text = (string) $normalizer($text);
        }

        $length = mb_strlen($text, 'UTF-8');

        return new RenderedMessage(
            $text,
            $length,
            $limit,
            $limit <= 0 || $length <= $limit,
            $length < $originalLength
        );
    }

    /**
     * @param TemplateSegment[] $segments
     */
    private function joinSegments(array $segments): string
    {
        $text = '';

        foreach ($segments as $segment) {
            $text .= $segment->getText();
        }

        return $text;
    }
}
