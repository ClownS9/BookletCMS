<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A post that went out to subscribers by email (one row each, so none goes twice).
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $entry_id
 * @property string|null $title
 * @property int $recipients
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class PostEmail extends Model
{
    protected $fillable = ['entry_id', 'title', 'recipients', 'status', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];
}
