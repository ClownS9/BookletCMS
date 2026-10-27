<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An address Booklet calls when something happens (see App\Support\Webhooks).
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $format  json, slack or discord
 * @property array $events
 * @property array|null $collections  only these chapters (entry events), or all
 * @property string $secret  for the X-Booklet-Signature header (kept encrypted)
 * @property bool $active
 * @property int|null $last_status
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 * @property int $failures  failed tries in a row
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Webhook extends Model
{
    protected $fillable = ['name', 'url', 'format', 'events', 'collections', 'secret', 'active', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'collections' => 'array',
            'secret' => 'encrypted',
            'active' => 'boolean',
            'last_status' => 'integer',
            'last_sent_at' => 'datetime',
            'failures' => 'integer',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The address with any key in it hidden (Slack and Discord put theirs in the path). */
    public function shownUrl(): string
    {
        $parts = parse_url($this->url) ?: [];
        $host = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = (string) ($parts['path'] ?? '');

        return $host.(strlen($path) > 18 ? substr($path, 0, 14).'…' : $path).(isset($parts['query']) ? '?…' : '');
    }
}
