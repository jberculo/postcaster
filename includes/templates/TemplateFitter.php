<?php

namespace Justbee\PostCaster\Templates;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateFitter
{
    /**
     * @param TemplateSegment[] $segments
     * @return TemplateSegment[]
     */
    public function fit(array $segments, int $limit): array
    {
        if ($limit <= 0 || $this->segmentsLength($segments) <= $limit) {
            return $segments;
        }

        foreach ([1, 2, 3] as $priority) {
            while ($this->segmentsLength($segments) > $limit) {
                $candidateIndex = $this->findShrinkableSegmentIndex($segments, $priority);
                if ($candidateIndex === null) {
                    break;
                }

                $remainingToRemove = $this->segmentsLength($segments) - $limit;
                $segment = $segments[$candidateIndex];
                $updatedText = $this->shrinkSegmentText($segment, $remainingToRemove);

                if ($updatedText === $segment->getText()) {
                    break;
                }

                $segments[$candidateIndex] = $segment->withText($updatedText);
            }
        }

        return $segments;
    }

    /**
     * @param TemplateSegment[] $segments
     */
    public function segmentsLength(array $segments): int
    {
        $length = 0;

        foreach ($segments as $segment) {
            $length += $segment->length();
        }

        return $length;
    }

    /**
     * @param TemplateSegment[] $segments
     */
    private function findShrinkableSegmentIndex(array $segments, int $priority): ?int
    {
        foreach ($segments as $index => $segment) {
            if ($segment->getPriority() !== $priority || $segment->isForbidden()) {
                continue;
            }

            if ($this->canShrinkSegment($segment)) {
                return $index;
            }
        }

        return null;
    }

    private function canShrinkSegment(TemplateSegment $segment): bool
    {
        $text = $segment->getText();
        $strategy = $segment->getStrategy();

        if ($text === '' || $strategy === TemplateSegment::STRATEGY_NONE) {
            return false;
        }

        if ($strategy === TemplateSegment::STRATEGY_DROP_ITEMS) {
            return count(array_filter(explode(' ', trim($text)))) > 1;
        }

        return $segment->length() > 0;
    }

    private function shrinkSegmentText(TemplateSegment $segment, int $remainingToRemove): string
    {
        if ($segment->getStrategy() === TemplateSegment::STRATEGY_DROP_ITEMS) {
            return $this->shrinkTagList($segment->getText(), $remainingToRemove);
        }

        if ($segment->getStrategy() === TemplateSegment::STRATEGY_ELLIPSIS) {
            return $this->shrinkWithEllipsis($segment->getText(), $remainingToRemove);
        }

        return $segment->getText();
    }

    private function shrinkTagList(string $text, int $remainingToRemove): string
    {
        $tags = array_values(array_filter(explode(' ', trim($text)), static fn(string $tag): bool => $tag !== ''));
        if (count($tags) <= 1) {
            return $text;
        }

        while (count($tags) > 1 && mb_strlen(implode(' ', $tags), 'UTF-8') > max(0, mb_strlen($text, 'UTF-8') - $remainingToRemove)) {
            array_pop($tags);
        }

        $result = implode(' ', $tags);

        return $result !== '' ? $result : $text;
    }

    private function shrinkWithEllipsis(string $text, int $remainingToRemove): string
    {
        if ($text === '') {
            return $text;
        }

        $currentLength = mb_strlen($text, 'UTF-8');
        $targetLength = max(0, $currentLength - max(1, $remainingToRemove));

        if ($targetLength <= 0) {
            return '';
        }

        if ($targetLength >= $currentLength) {
            return $text;
        }

        if ($targetLength <= 3) {
            return rtrim(mb_substr($text, 0, $targetLength, 'UTF-8'));
        }

        $trimmed = rtrim(mb_substr($text, 0, $targetLength - 3, 'UTF-8'));
        $lastSpace = mb_strrpos($trimmed, ' ', 0, 'UTF-8');

        if ($lastSpace !== false && $lastSpace > 0) {
            $trimmed = rtrim(mb_substr($trimmed, 0, $lastSpace, 'UTF-8'));
        }

        if ($trimmed === '') {
            $trimmed = rtrim(mb_substr($text, 0, $targetLength - 3, 'UTF-8'));
        }

        return $trimmed === '' ? '' : $trimmed . '...';
    }
}
