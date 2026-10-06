<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;
use Illuminate\Validation\ValidationException;

final class HttpEnhanceApi extends AbstractHttpServerApi implements EnhanceApi
{
    private const SUBSCRIPTION_STATUSES = ['active', 'deleted'];

    private const WEBSITE_STATUSES = ['active', 'disabled', 'deleted'];

    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        $accountId = $this->orgId((string) ($this->connection['account_id'] ?? ''));
        $org = $this->call('GET', '/orgs/'.$accountId);
        if (($org['id'] ?? null) !== $accountId || ($org['status'] ?? null) !== 'active') {
            throw new ServerProviderException('errors.malformed');
        }

        return [];
    }

    /** @return list<int> */
    public function planIds(): array
    {
        $ids = [];
        foreach ($this->items($this->accountPath().'/plans') as $plan) {
            $id = $plan['id'] ?? null;
            if (! is_int($id) || $id < 1) {
                throw new ServerProviderException('errors.malformed');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    public function createCustomer(string $name): string
    {
        return $this->createdUuid($this->call('POST', $this->accountPath().'/customers', body: ['name' => $name]));
    }

    public function createSubscription(string $orgId, int $planId): int
    {
        $id = $this->call('POST', $this->customerPath($orgId).'/subscriptions', body: ['planId' => $planId])['id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $id;
    }

    /** @return list<string> */
    public function subscriptionStatuses(string $orgId): array
    {
        return $this->statuses($this->customerPath($orgId).'/subscriptions', [], self::SUBSCRIPTION_STATUSES);
    }

    /** @return array{planId: int, subscriberId: string, vendorId: string, deleted: bool, suspended: bool} */
    public function subscription(string $orgId, int $subscriptionId): array
    {
        $payload = $this->call('GET', $this->subscriptionPath($orgId, $subscriptionId));
        $planId = $payload['planId'] ?? null;
        $subscriberId = $payload['subscriberId'] ?? null;
        $vendorId = $payload['vendorId'] ?? null;
        $status = $payload['status'] ?? null;
        $suspendedBy = $payload['suspendedBy'] ?? null;
        if (($payload['id'] ?? null) !== $subscriptionId
            || ! is_int($planId)
            || ! is_string($subscriberId)
            || ! is_string($vendorId)
            || ! in_array($status, self::SUBSCRIPTION_STATUSES, true)
            || ($suspendedBy !== null && ! is_string($suspendedBy))
        ) {
            throw new ServerProviderException('errors.malformed');
        }

        return [
            'planId' => $planId,
            'subscriberId' => strtolower($subscriberId),
            'vendorId' => strtolower($vendorId),
            'deleted' => $status === 'deleted',
            'suspended' => is_string($suspendedBy) && $suspendedBy !== '',
        ];
    }

    /** @param array{isSuspended?: bool, planId?: int} $changes */
    public function updateSubscription(string $orgId, int $subscriptionId, array $changes): void
    {
        $this->expectEmpty($this->call('PATCH', $this->subscriptionPath($orgId, $subscriptionId), body: $changes));
    }

    public function deleteSubscription(string $orgId, int $subscriptionId): void
    {
        $this->expectEmpty($this->call('DELETE', $this->subscriptionPath($orgId, $subscriptionId)));
    }

    public function createWebsite(string $orgId, string $domain, int $subscriptionId): string
    {
        return $this->createdUuid($this->call('POST', '/orgs/'.$this->orgId($orgId).'/websites', body: [
            'domain' => $domain,
            'subscriptionId' => $subscriptionId,
        ]));
    }

    /** @return list<string> */
    public function websiteStatuses(string $orgId, ?int $subscriptionId = null): array
    {
        $query = $subscriptionId === null ? [] : ['subscriptionId' => $subscriptionId];

        return $this->statuses('/orgs/'.$this->orgId($orgId).'/websites', $query, self::WEBSITE_STATUSES);
    }

    public function deleteOrg(string $orgId): void
    {
        $this->expectEmpty($this->call('DELETE', '/orgs/'.$this->orgId($orgId)));
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (app()->environment('demo')) {
            throw new ServerProviderException('errors.demo_disabled');
        }
        if (trim((string) ($this->connection['api_token'] ?? '')) === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $endpoint = EnhanceEndpoint::normalize((string) ($this->connection['api_url'] ?? ''));
        try {
            return $this->withConnection(['api_url' => $endpoint] + $this->connection)
                ->request($method, '/api'.$path, $query, $body);
        } catch (ValidationException) {
            throw new ServerProviderException('errors.invalid_mapping');
        }
    }

    /**
     * Reads every page of a documented `{items, total}` listing.
     *
     * @param  array<string, scalar>  $query
     * @return list<array<mixed>>
     */
    private function items(string $path, array $query = []): array
    {
        $items = [];
        do {
            $page = $this->call('GET', $path, $items === [] ? $query : $query + ['offset' => count($items)]);
            $chunk = $page['items'] ?? null;
            $total = $page['total'] ?? null;
            if (! is_array($chunk) || ! array_is_list($chunk) || ! is_int($total) || ($chunk === [] && count($items) < $total)) {
                throw new ServerProviderException('errors.malformed');
            }
            foreach ($chunk as $item) {
                if (! is_array($item)) {
                    throw new ServerProviderException('errors.malformed');
                }
                $items[] = $item;
            }
        } while (count($items) < $total);

        if (count($items) !== $total) {
            throw new ServerProviderException('errors.malformed');
        }

        return $items;
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  list<string>  $documented
     * @return list<string>
     */
    private function statuses(string $path, array $query, array $documented): array
    {
        return array_map(static function (array $item) use ($documented): string {
            $status = $item['status'] ?? null;
            if (! is_string($status) || ! in_array($status, $documented, true)) {
                throw new ServerProviderException('errors.malformed');
            }

            return $status;
        }, $this->items($path, $query));
    }

    private function accountPath(): string
    {
        return '/orgs/'.$this->orgId((string) ($this->connection['account_id'] ?? ''));
    }

    private function customerPath(string $orgId): string
    {
        return $this->accountPath().'/customers/'.$this->orgId($orgId);
    }

    private function subscriptionPath(string $orgId, int $subscriptionId): string
    {
        if ($subscriptionId < 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        return '/orgs/'.$this->orgId($orgId).'/subscriptions/'.$subscriptionId;
    }

    private function orgId(string $orgId): string
    {
        $orgId = strtolower(trim($orgId));
        if (preg_match(self::UUID, $orgId) !== 1) {
            throw new ServerProviderException('errors.invalid_mapping');
        }

        return $orgId;
    }

    /** @param array<string, mixed> $payload */
    private function createdUuid(array $payload): string
    {
        $id = $payload['id'] ?? null;
        if (! is_string($id) || preg_match(self::UUID, $id) !== 1) {
            throw new ServerProviderException('errors.malformed');
        }

        return $id;
    }

    /**
     * Documented update and delete operations answer 204 without a body; anything else fails closed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function expectEmpty(array $payload): void
    {
        if ($payload !== []) {
            throw new ServerProviderException('errors.malformed');
        }
    }
}
