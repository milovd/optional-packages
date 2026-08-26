<?php

declare(strict_types=1);

namespace Agovena\Modules\Domains\Listeners;

use Agovena\Modules\Domains\DomainService;
use App\Events\OrderPaid;

final class CreateDomainRegistrationsWhenOrderPaid
{
    public function __construct(
        private readonly DomainService $domains,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->domains->createFromPaidOrder($event->order);
    }
}
