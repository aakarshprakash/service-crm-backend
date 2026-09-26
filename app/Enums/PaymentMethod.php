<?php

namespace App\Enums;

enum PaymentMethod: string
{
    // Offline methods: collected manually by the service agent (or at the office).
    case Cash = 'cash';
    case Upi = 'upi';
    case Cheque = 'cheque';
    case BankTransfer = 'bank_transfer';
    // Nothing collected now; posts to the customer ledger.
    case Credit = 'credit';
    // Online via payment gateway (optional, per tenant).
    case Online = 'online';

    /** Methods an agent can record when collecting in the field. */
    public static function offline(): array
    {
        return [self::Cash->value, self::Upi->value, self::Cheque->value, self::BankTransfer->value];
    }

    /** Physical instruments the agent must hand over at daily cash close. */
    public function isInHand(): bool
    {
        return in_array($this, [self::Cash, self::Cheque], true);
    }
}
