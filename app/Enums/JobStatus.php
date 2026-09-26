<?php

namespace App\Enums;

enum JobStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** Allowed transitions per SRS §12.1. */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Open => in_array($to, [self::InProgress, self::Cancelled], true),
            self::InProgress => in_array($to, [self::Pending, self::Completed, self::Cancelled], true),
            self::Pending => in_array($to, [self::InProgress, self::Cancelled], true),
            default => false,
        };
    }
}
