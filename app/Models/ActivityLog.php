<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stamp on the library card: who did what to which thing.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $actor
 * @property string $action
 * @property string $kind
 * @property string $subject_type
 * @property string|null $subject_key
 * @property string|null $subject_label
 * @property array|null $properties
 * @property \Illuminate\Support\Carbon $created_at
 *
 * @property-read User|null $user
 */
class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor', 'action', 'kind', 'subject_type', 'subject_key', 'subject_label', 'properties'];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ActivityLog::record('published', 'publishing', 'entry', $entry->id(), $entry->title());
     */
    public static function record(string $action, string $kind, string $subjectType, ?string $key = null, ?string $label = null, array $properties = []): self
    {
        return static::create([
            'user_id' => request()->user()?->id,
            'action' => $action,
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_key' => $key,
            'subject_label' => $label,
            'properties' => $properties ?: null,
        ]);
    }
}
