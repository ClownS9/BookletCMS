<?php

namespace App\Booklet\Data;

use App\Booklet\Facades\Booklet;
use Illuminate\Support\Carbon;

class Entry extends Item
{
    public static function repositoryName(): string
    {
        return 'entries';
    }

    public function id(): ?string
    {
        return $this->attribute('id');
    }

    public function collectionHandle(): string
    {
        return $this->attribute('collection');
    }

    public function collection(): ?Collection
    {
        return Booklet::collections()->find($this->collectionHandle());
    }

    public function blueprint(): ?Blueprint
    {
        return Booklet::blueprints()->forEntry($this);
    }

    public function site(): string
    {
        return $this->attribute('site', config('booklet.default_site'));
    }

    public function slug(): ?string
    {
        return $this->attribute('slug');
    }

    public function uri(): ?string
    {
        return $this->attribute('uri');
    }

    public function title(): string
    {
        return (string) $this->get('title', $this->slug() ?? '');
    }

    public function published(): bool
    {
        return (bool) $this->attribute('published', true);
    }

    public function date(): ?Carbon
    {
        $date = $this->attribute('date');

        return $date ? Carbon::parse($date) : null;
    }

    /**
     * Draft, Scheduled or Published, as the admin's stickers show it.
     */
    public function status(): string
    {
        if (! $this->published()) {
            return 'draft';
        }

        $date = $this->date();

        // A post's date is its date; a page has one only while it's waiting to go out.
        return $date && $date->isFuture() ? 'scheduled' : 'published';
    }

    public function revisionKey(): string
    {
        return "collections/{$this->collectionHandle()}/{$this->site()}/{$this->id()}";
    }
}
