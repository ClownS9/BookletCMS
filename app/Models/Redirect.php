<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An old address that forwards to a new one (see App\Support\Redirects).
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $from_path
 * @property string $to_path
 * @property int $status
 * @property string|null $entry_id
 * @property bool $automatic
 * @property int $hits
 * @property \Illuminate\Support\Carbon|null $last_hit_at
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Redirect extends Model
{
    protected $fillable = ['from_path', 'to_path', 'status', 'entry_id', 'automatic', 'hits', 'last_hit_at', 'created_by'];

    protected $casts = ['automatic' => 'boolean', 'last_hit_at' => 'datetime'];
}
