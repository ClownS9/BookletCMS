<?php

namespace App\Booklet\Data;

class GlobalSet extends Item
{
    public static function repositoryName(): string
    {
        return 'global_sets';
    }

    public function handle(): string
    {
        return $this->attribute('handle');
    }

    public function title(): string
    {
        return (string) ($this->attribute('title') ?? $this->get('title') ?? ucfirst($this->handle()));
    }
}
