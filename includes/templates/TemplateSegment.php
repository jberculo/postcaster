<?php

namespace Justbee\PostCaster\Templates;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateSegment
{
    public const POLICY_PREFER = 'prefer';
    public const POLICY_NEUTRAL = 'neutral';
    public const POLICY_FORBIDDEN = 'forbidden';

    public const STRATEGY_NONE = 'none';
    public const STRATEGY_ELLIPSIS = 'ellipsis';
    public const STRATEGY_DROP_ITEMS = 'drop_items';

    public function __construct(
        private string $text,
        private string $policy,
        private string $strategy,
        private int $priority,
        private string $source
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getPolicy(): string
    {
        return $this->policy;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function withText(string $text): self
    {
        return new self(
            $text,
            $this->policy,
            $this->strategy,
            $this->priority,
            $this->source
        );
    }

    public function length(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }

    public function isForbidden(): bool
    {
        return $this->policy === self::POLICY_FORBIDDEN;
    }
}
