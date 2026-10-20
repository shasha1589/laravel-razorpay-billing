<?php

declare(strict_types=1);

namespace Shasha1589\RazorpayBilling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A received Razorpay webhook, keyed by X-Razorpay-Event-Id for idempotency.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookEvent extends Model
{
    protected $table = 'razorpay_webhook_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /** When Razorpay created the event, falling back to when it was received. */
    public function occurredAt(): Carbon
    {
        $createdAt = $this->payload['created_at'] ?? null;

        return is_int($createdAt) ? Carbon::createFromTimestamp($createdAt) : ($this->created_at ?? Carbon::now());
    }

    /**
     * The entity of a given type in the payload, e.g. entity('subscription').
     *
     * @return array<string, mixed>|null
     */
    public function entity(string $type): ?array
    {
        $payload = $this->payload['payload'] ?? null;
        $wrapper = is_array($payload) ? ($payload[$type] ?? null) : null;
        $entity = is_array($wrapper) ? ($wrapper['entity'] ?? null) : null;

        /** @var array<string, mixed>|null $entity */
        return is_array($entity) ? $entity : null;
    }
}
