<?php

namespace Justbee\PostCaster\Templates;

if (!defined('ABSPATH')) {
    exit;
}

final class RenderedMessage
{
    public function __construct(
        private string $text,
        private int $length,
        private int $limit,
        private bool $fits,
        private bool $truncated
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function fits(): bool
    {
        return $this->fits;
    }

    public function wasTruncated(): bool
    {
        return $this->truncated;
    }
}
