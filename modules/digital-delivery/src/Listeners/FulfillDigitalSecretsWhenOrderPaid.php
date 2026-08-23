<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Listeners;

use Agovena\Modules\DigitalDelivery\DigitalSecretFulfillmentService;
use App\Events\OrderPaid;

final class FulfillDigitalSecretsWhenOrderPaid
{
    public function __construct(
        private readonly DigitalSecretFulfillmentService $secrets,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->secrets->fulfillPaidOrder($event->order);
    }
}
