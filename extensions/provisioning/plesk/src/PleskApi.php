<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * Plesk XML API seam. The generic ServerApi lifecycle methods stay unavailable;
 * every method below maps to one documented customer, webspace or service-plan operation.
 * A documented "object does not exist" result (1013) throws errors.not_found.
 *
 * @phpstan-type PleskSubscription array{id: int, name: string, status: int, owner_id: int, plan_guids: list<string>}
 */
interface PleskApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return array<string, string> Plan name => plan GUID of the requester's service plans. */
    public function servicePlans(): array;

    public function customerByLogin(string $login): int;

    /** @param array{login: string, name: string, password: string, email: string|null} $customer */
    public function addCustomer(array $customer): int;

    public function deleteCustomer(int $customerId): void;

    /** @param array{name: string, owner_id: int, ip: string, plan: string, ftp_login: string, ftp_password: string} $subscription */
    public function addSubscription(array $subscription): int;

    /** @return PleskSubscription */
    public function subscriptionByName(string $name): array;

    /** @return PleskSubscription */
    public function subscription(int $subscriptionId): array;

    public function setSubscriptionStatus(int $subscriptionId, int $status): void;

    public function deleteSubscription(int $subscriptionId): void;

    public function switchPlan(int $subscriptionId, string $planGuid): void;

    /** @return list<int> */
    public function subscriptionsOwnedBy(int $customerId): array;
}
