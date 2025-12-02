<?php

namespace Lkn\BBPix\App\Pix\Repositories;

use DateTime;
use WHMCS\Database\Capsule;

final class WebhookEventRepository extends AbstractDbRepository
{
    protected string $table = 'mod_lknbbpix_webhook_events';

    public function isProcessed(string $eventId): bool
    {
        return (bool) $this->query()->where('event_id', $eventId)->whereIn('status', ['processed'])->count();
    }

    public function markReceived(string $eventId, string $eventType, array $payload): void
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        $this->query()->updateOrInsert(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'received_at' => $now,
                'status' => 'received',
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]
        );
    }

    public function markProcessed(string $eventId): void
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');

        $this->query()->where('event_id', $eventId)->update([
            'processed_at' => $now,
            'status' => 'processed'
        ]);
    }

    public function markFailed(string $eventId): void
    {
        $this->query()->where('event_id', $eventId)->update([
            'status' => 'failed'
        ]);
    }
} 