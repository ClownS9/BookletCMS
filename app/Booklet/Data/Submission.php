<?php

namespace App\Booklet\Data;

class Submission extends Item
{
    public static function repositoryName(): string
    {
        return 'form_submissions';
    }

    public function id(): ?string
    {
        return $this->attribute('id');
    }

    public function form(): string
    {
        return $this->attribute('form');
    }
}
