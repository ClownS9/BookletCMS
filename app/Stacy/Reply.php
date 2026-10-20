<?php

namespace App\Stacy;

/**
 * One answer from a model: some text, and/or tools it wants to use.
 */
final class Reply
{
    /**
     * @param  ToolCall[]  $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls = [],
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
