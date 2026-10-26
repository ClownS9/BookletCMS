<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a built page (or the blog page) looked like when it was published.
 *
 * @property int $id
 * @property int $page_build_id
 * @property array<int, array<string, mixed>> $sections
 * @property Carbon $published_at
 * @property int|null $user_id
 * @property bool $scheduled
 */
class PageBuildVersion extends Model
{
    /** How many are kept for each page (the oldest go first). */
    public const KEEP = 50;

    public $timestamps = false;

    protected $fillable = ['page_build_id', 'sections', 'published_at', 'user_id', 'scheduled'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'published_at' => 'datetime', 'scheduled' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
