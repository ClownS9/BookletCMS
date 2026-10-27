<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to a webhook: waiting, sent, or given up on.
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event
 * @property string|null $subject  the entry, comment or message it's about
 * @property array $payload
 * @property string $status  pending, sent or failed
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $response_note
 * @property \Illuminate\Support\Carbon $due_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Webhook|null $webhook
 */
class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_id', 'event', 'subject', 'payload', 'status', 'attempts', 'response_code', 'response_note', 'due_at', 'sent_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_code' => 'integer',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Webhook, $this> */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
