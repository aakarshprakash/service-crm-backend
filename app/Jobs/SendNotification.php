<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\NotificationLog;
use App\Services\Messaging\MessagingManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $logId)
    {
        $this->onQueue('notifications');
    }

    public function handle(MessagingManager $messaging): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || $log->status === 'sent') {
            return;
        }

        try {
            $result = $messaging->channel($log->channel)->send((string) $log->recipient, $log->message, [
                'title' => $log->title,
                'data' => $log->data ?? [],
            ]);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        if ($result['ok']) {
            $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);

            return;
        }

        $log->update(['status' => 'failed', 'error' => mb_substr((string) $result['error'], 0, 500)]);

        // Drop dead FCM tokens instead of retrying forever.
        if ($log->channel === 'push' && (($result['invalid_token'] ?? false) || str_contains((string) $result['error'], 'not found'))) {
            DeviceToken::where('token', $log->recipient)->delete();

            return;
        }
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 120);
        }
    }
}
