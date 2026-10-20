<?php

namespace App\Booklet\Data;

class Term extends Item
{
    public static function repositoryName(): string
    {
        return 'terms';
    }

    public function taxonomy(): string
    {
        return $this->attribute('taxonomy');
    }

    public function slug(): string
    {
        return $this->attribute('slug');
    }

    public function site(): string
    {
        return $this->attribute('site', config('booklet.default_site'));
    }

    public function title(): string
    {
        return (string) $this->get('title', $this->slug());
    }
}
