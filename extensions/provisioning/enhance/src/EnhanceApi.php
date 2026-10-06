<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * Enhance orchd API seam. Every method maps to one documented operation; see the README
 * for the operationIds. Org ids are UUIDs; the reseller org is the `account_id` setting.
 */
interface EnhanceApi extends ServerApi
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return list<int> Ids of the plans the reseller org offers (getPlans). */
    public function planIds(): array;

    /** Creates a customer org under the reseller org and returns its id (createCustomer). */
    public function createCustomer(string $name): string;

    /** Subscribes the customer org to a reseller plan and returns the subscription id (createCustomerSubscription). */
    public function createSubscription(string $orgId, int $planId): int;

    /** @return list<string> Statuses of the customer org's subscriptions to the reseller (getCustomerSubscriptions). */
    public function subscriptionStatuses(string $orgId): array;

    /** @return array{planId: int, subscriberId: string, vendorId: string, deleted: bool, suspended: bool} (getSubscription) */
    public function subscription(string $orgId, int $subscriptionId): array;

    /** @param array{isSuspended?: bool, planId?: int} $changes (updateSubscription) */
    public function updateSubscription(string $orgId, int $subscriptionId, array $changes): void;

    /** Soft deletes the subscription and the websites under it (deleteSubscription). */
    public function deleteSubscription(string $orgId, int $subscriptionId): void;

    /** Creates a website for the domain under the subscription and returns its id (createWebsite). */
    public function createWebsite(string $orgId, string $domain, int $subscriptionId): string;

    /** @return list<string> Statuses of the org's websites, optionally of one subscription (getWebsites). */
    public function websiteStatuses(string $orgId, ?int $subscriptionId = null): array;

    /** Soft deletes the customer org (deleteOrg). */
    public function deleteOrg(string $orgId): void;
}
