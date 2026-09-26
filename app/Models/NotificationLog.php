<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'customer_id', 'type', 'channel', 'recipient', 'title', 'message', 'data', 'status', 'error', 'sent_at', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
