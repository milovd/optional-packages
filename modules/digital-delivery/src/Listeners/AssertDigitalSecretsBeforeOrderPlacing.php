<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Listeners;

use Agovena\Modules\DigitalDelivery\DigitalSecretFulfillmentService;
use App\Events\OrderPlacing;

final class AssertDigitalSecretsBeforeOrderPlacing
{
    public function __construct(
        private readonly DigitalSecretFulfillmentService $secrets,
    ) {}

    public function handle(OrderPlacing $event): void
    {
        $this->secrets->assertPoolAvailableForCart($event->lines);
    }
}
