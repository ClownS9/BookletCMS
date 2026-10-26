<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A page saved as a layout: its sections, to start other pages from.
 *
 * @property int $id
 * @property string $name
 * @property array<int, array<string, mixed>> $sections
 * @property int|null $created_by
 */
class PageLayout extends Model
{
    /** How many can be kept. */
    public const MAX = 40;

    protected $fillable = ['name', 'sections', 'created_by'];

    protected function casts(): array
    {
        return ['sections' => 'array'];
    }
}
