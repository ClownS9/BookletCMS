<?php

namespace App\Booklet\Data;

class Fieldset extends Item
{
    public static function repositoryName(): string
    {
        return 'fieldsets';
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
