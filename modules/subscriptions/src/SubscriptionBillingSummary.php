<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions;

use DateTimeInterface;

final readonly class SubscriptionBillingSummary
{
    public function __construct(
        public string $renewalMode,
        public string $gatewayId,
        public string $gatewayLabel,
        public bool $authorizationAvailable,
        public ?string $lastError,
        public ?DateTimeInterface $nextRetryAt,
        public int $chargeAttempts,
        public bool $requireManualPayment,
    ) {}

    public function isAutomatic(): bool
    {
        return $this->renewalMode === 'automatic';
    }
}
