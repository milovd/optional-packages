<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Listeners;

use Agovena\Modules\Events\EventService;
use App\Events\OrderPlacing;
use App\Models\Product;

final class AssertEventCapacityBeforeOrderPlacing
{
    public function __construct(private readonly EventService $events) {}

    public function handle(OrderPlacing $event): void
    {
        foreach ($event->lines as $line) {
            $product = Product::query()->with('capabilities')->find($line->productId);
            if ($product === null || ! $product->hasCapability('event_ticket')) {
                continue;
            }

            $this->events->assertAvailable($product, $line->quantity);
        }
    }
}
