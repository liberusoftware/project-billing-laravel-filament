<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Enums;

enum PaymentLifecycleState: string
{
    case Draft = 'draft';
    case RequiresPaymentMethod = 'requires_payment_method';
    case RequiresCustomerAction = 'requires_customer_action';
    case Processing = 'processing';
    case Authorized = 'authorized';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case Voided = 'voided';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case DisputeWon = 'dispute_won';
    case DisputeLost = 'dispute_lost';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
