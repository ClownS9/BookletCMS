<?php

namespace App\Stacy;

final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}
