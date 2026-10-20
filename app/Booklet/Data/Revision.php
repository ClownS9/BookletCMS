<?php

namespace App\Booklet\Data;

use Illuminate\Support\Carbon;

/**
 * One saved edition of something. The data bag is the full snapshot of the
 * item at that moment (its attributes and data), as Statamic stores it.
 */
class Revision extends Item
{
    public static function repositoryName(): string
    {
        return 'revisions';
    }

    public function id(): ?string
    {
        return $this->attribute('id');
    }

    public function revisionKey(): string
    {
        return $this->attribute('key');
    }

    public function action(): string
    {
        return $this->attribute('action', 'revision');
    }

    public function user(): ?string
    {
        return $this->attribute('user');
    }

    public function message(): ?string
    {
        return $this->attribute('message');
    }

    public function snapshot(): array
    {
        return $this->data();
    }

    public function date(): ?Carbon
    {
        $at = $this->get('date') ?? $this->lastModified();

        return $at ? Carbon::parse($at) : null;
    }
}
