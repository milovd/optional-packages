<?php

declare(strict_types=1);

namespace Agovena\Modules\Events\Listeners;

use Agovena\Modules\Events\EventService;
use App\Events\OrderPaid;

final class IssueTicketsWhenOrderPaid
{
    public function __construct(private readonly EventService $events) {}

    public function handle(OrderPaid $event): void
    {
        $this->events->issueFromPaidOrder($event->order);
    }
}
