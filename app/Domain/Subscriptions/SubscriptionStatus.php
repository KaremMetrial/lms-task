<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
