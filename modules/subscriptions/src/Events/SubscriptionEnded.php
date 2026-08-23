<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Events;

use Agovena\Modules\Subscriptions\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SubscriptionEnded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
