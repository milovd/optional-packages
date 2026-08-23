<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Listeners;

use Agovena\Modules\Digital\DigitalDeliveryService;
use App\Events\OrderPaid;

final class GrantDigitalEntitlementsWhenOrderPaid
{
    public function __construct(
        private readonly DigitalDeliveryService $digital,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->digital->grantForPaidOrder($event->order);
    }
}
