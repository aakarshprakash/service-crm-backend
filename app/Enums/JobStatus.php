<?php

namespace App\Enums;

enum JobStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Pending = 'pending';
    case PartPending = 'part_pending';
    case CancelRequested = 'cancel_requested';
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
            self::Open => in_array($to, [self::InProgress, self::PartPending, self::CancelRequested, self::Cancelled], true),
            self::InProgress => in_array($to, [self::Pending, self::PartPending, self::CancelRequested, self::Completed, self::Cancelled], true),
            self::Pending => in_array($to, [self::InProgress, self::PartPending, self::CancelRequested, self::Cancelled], true),
            // Part received: back to waiting for a visit, or the technician brings it along and starts straight away.
            self::PartPending => in_array($to, [self::Open, self::Pending, self::InProgress, self::CancelRequested, self::Cancelled], true),
            // A technician asked to cancel: the office approves it, or sends the job back.
            self::CancelRequested => in_array($to, [self::Pending, self::Cancelled], true),
            default => false,
        };
    }
}
