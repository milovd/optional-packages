<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions;

use Agovena\Modules\Subscriptions\Enums\RenewalStatus;
use Agovena\Modules\Subscriptions\Models\Subscription;
use Agovena\Modules\Subscriptions\Models\SubscriptionRenewal;
use App\Agovena\Payments\PaymentGatewayRegistry;
use App\Agovena\Payments\ResolvesReusablePaymentAuthorizations;
use App\Agovena\Settings\SettingsRepository;
use Illuminate\Support\Facades\Lang;

final class DescribesSubscriptionBilling
{
    public function __construct(
        private readonly ResolvesReusablePaymentAuthorizations $authorizations,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly SettingsRepository $settings,
    ) {}

    public function describe(Subscription $subscription): SubscriptionBillingSummary
    {
        $gatewayId = $this->gatewayId($subscription);
        $authorization = $this->authorizations->forCustomer(
            $gatewayId,
            $subscription->customer_id !== null ? (int) $subscription->customer_id : null,
            (string) $subscription->customer_email,
        );
        $autoChargeEnabled = (bool) $this->settings->get('store', 'subscription_auto_charge', true);
        $automatic = $autoChargeEnabled && $authorization->available;
        $renewals = $subscription->relationLoaded('renewals')
            ? $subscription->renewals
            : $subscription->renewals()->get();
        $pending = $renewals->first(
            static fn (SubscriptionRenewal $renewal): bool => $renewal->status === RenewalStatus::Pending,
        );

        $pendingRenewal = $pending instanceof SubscriptionRenewal ? $pending : null;

        return new SubscriptionBillingSummary(
            renewalMode: $automatic ? 'automatic' : 'manual',
            gatewayId: $gatewayId,
            gatewayLabel: $this->gatewayLabel($gatewayId),
            authorizationAvailable: $authorization->available,
            lastError: is_string($pendingRenewal?->last_error) && $pendingRenewal->last_error !== ''
                ? $pendingRenewal->last_error
                : null,
            nextRetryAt: $pendingRenewal?->next_retry_at,
            chargeAttempts: $pendingRenewal !== null ? (int) $pendingRenewal->charge_attempts : 0,
            requireManualPayment: $pendingRenewal !== null && $pendingRenewal->require_manual_payment,
        );
    }

    private function gatewayId(Subscription $subscription): string
    {
        $stored = trim((string) ($subscription->payment_gateway ?? ''));

        return $stored !== '' ? $stored : 'manual';
    }

    private function gatewayLabel(string $gatewayId): string
    {
        $gateway = $this->gateways->get($gatewayId);
        if ($gateway === null) {
            return $gatewayId;
        }

        $label = $gateway->label();

        return Lang::has($label) ? (string) __($label) : $label;
    }
}
