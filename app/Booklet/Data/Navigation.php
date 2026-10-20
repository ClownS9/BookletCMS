<?php

namespace App\Booklet\Data;

class Navigation extends Item
{
    public static function repositoryName(): string
    {
        return 'navigations';
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
