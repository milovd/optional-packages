<?php

declare(strict_types=1);

namespace Agovena\Extensions\Pterodactyl;

use App\Agovena\Extensions\ExtensionSettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpPterodactylApi implements PterodactylApi
{
    /** @param array<string, mixed> $connection */
    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ?array $connection = null,
    ) {}

    public function withConnection(array $settings): PterodactylApi
    {
        return new self($this->settings, $settings !== [] ? $settings : null);
    }

    public function connectionTest(): array
    {
        return $this->application('GET', '/api/application/servers', ['per_page' => 1]);
    }

    public function getDeployableNodes(int $locationId, int $memory, int $disk): array
    {
        $nodes = [];
        $page = 1;
        $totalPages = 1;

        do {
            $payload = $this->application('GET', '/api/application/nodes', [
                'filter[location_id]' => $locationId,
                'per_page' => 100,
                'page' => $page,
            ]);
            $items = $payload['data'] ?? [];
            $pagination = $payload['meta']['pagination'] ?? [];
            if (! is_array($items) || ! array_is_list($items) || ! is_array($pagination)) {
                throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
            }

            foreach ($items as $item) {
                $attributes = is_array($item) ? ($item['attributes'] ?? $item) : null;
                if (! is_array($attributes) || ! $this->isDeployableNode($attributes)) {
                    continue;
                }
                $nodeId = $this->positiveInteger($attributes['id'] ?? null);
                if ($nodeId === null) {
                    throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
                }

                $capacity = $this->nodeCapacity($attributes, $memory, $disk);
                if ($capacity > 0) {
                    $nodes[] = [
                        'id' => $nodeId,
                        'capacity' => $capacity,
                        'attributes' => $attributes,
                    ];
                }
            }

            $totalPages = max(1, (int) ($pagination['total_pages'] ?? $page));
            $page++;
        } while ($page <= min($totalPages, 100));

        return $nodes;
    }

    /** @param array<string, mixed> $attributes */
    private function isDeployableNode(array $attributes): bool
    {
        if (! array_key_exists('maintenance_mode', $attributes)) {
            return false;
        }

        $maintenance = filter_var($attributes['maintenance_mode'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $maintenance === false;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    public function getCapacityVector(int $locationId): array
    {
        $memory = 0;
        $disk = 0;
        foreach ($this->getDeployableNodes($locationId, 1, 1) as $node) {
            $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            $resources = $this->nodeAvailableResources($attributes);
            $memory += $resources['memory'];
            $disk += $resources['disk'];
        }

        return ['memory' => $memory, 'disk' => $disk];
    }

    /** @param array<string, mixed> $attributes @return array{memory: int, disk: int} */
    private function nodeAvailableResources(array $attributes): array
    {
        $totalMemory = $this->numeric($attributes['memory'] ?? null);
        $totalDisk = $this->numeric($attributes['disk'] ?? null);
        $memoryOverallocate = $this->numeric($attributes['memory_overallocate'] ?? null);
        $diskOverallocate = $this->numeric($attributes['disk_overallocate'] ?? null);
        $allocated = $attributes['allocated_resources'] ?? [];
        $allocatedMemory = is_array($allocated) ? $this->numeric($allocated['memory'] ?? null) : null;
        $allocatedDisk = is_array($allocated) ? $this->numeric($allocated['disk'] ?? null) : null;
        if (! $this->validCapacityNumber($totalMemory)
            || ! $this->validCapacityNumber($totalDisk)
            || ! $this->validCapacityNumber($allocatedMemory)
            || ! $this->validCapacityNumber($allocatedDisk)
            || ! $this->validOverallocate($memoryOverallocate)
            || ! $this->validOverallocate($diskOverallocate)
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        $maxMemory = $memoryOverallocate < 0 ? PHP_INT_MAX : $totalMemory + ($totalMemory * $memoryOverallocate / 100);
        $maxDisk = $diskOverallocate < 0 ? PHP_INT_MAX : $totalDisk + ($totalDisk * $diskOverallocate / 100);

        return [
            'memory' => (int) floor(max(0, $maxMemory - $allocatedMemory)),
            'disk' => (int) floor(max(0, $maxDisk - $allocatedDisk)),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function nodeCapacity(array $attributes, int $memory, int $disk): int
    {
        if ($memory < 1 || $disk < 1) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        $totalMemory = $this->numeric($attributes['memory'] ?? null);
        $totalDisk = $this->numeric($attributes['disk'] ?? null);
        $memoryOverallocate = $this->numeric($attributes['memory_overallocate'] ?? null);
        $diskOverallocate = $this->numeric($attributes['disk_overallocate'] ?? null);
        $allocated = $attributes['allocated_resources'] ?? [];
        $allocatedMemory = is_array($allocated) ? $this->numeric($allocated['memory'] ?? null) : null;
        $allocatedDisk = is_array($allocated) ? $this->numeric($allocated['disk'] ?? null) : null;
        if (! $this->validCapacityNumber($totalMemory)
            || ! $this->validCapacityNumber($totalDisk)
            || ! $this->validCapacityNumber($allocatedMemory)
            || ! $this->validCapacityNumber($allocatedDisk)
            || ! $this->validOverallocate($memoryOverallocate)
            || ! $this->validOverallocate($diskOverallocate)
        ) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        $maxMemory = $memoryOverallocate < 0
            ? PHP_INT_MAX
            : $totalMemory + ($totalMemory * $memoryOverallocate / 100);
        $maxDisk = $diskOverallocate < 0
            ? PHP_INT_MAX
            : $totalDisk + ($totalDisk * $diskOverallocate / 100);

        $freeMemory = max(0, $maxMemory - $allocatedMemory);
        $freeDisk = max(0, $maxDisk - $allocatedDisk);

        return min(
            (int) floor($freeMemory / $memory),
            (int) floor($freeDisk / $disk),
        );
    }

    private function numeric(mixed $value): ?float
    {
        if (! (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return null;
        }

        $numeric = (float) $value;

        return is_finite($numeric) ? $numeric : null;
    }

    private function validCapacityNumber(?float $value): bool
    {
        return $value !== null && is_finite($value) && $value >= 0;
    }

    private function validOverallocate(?float $value): bool
    {
        return $value !== null && is_finite($value) && $value >= -1;
    }

    public function findServerByExternalId(string $externalId): ?array
    {
        try {
            return $this->unwrapServer(
                $this->application('GET', '/api/application/servers/external/'.rawurlencode($externalId), [
                    'include' => 'node,user,nest,egg,allocations',
                ]),
            );
        } catch (PterodactylProviderException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    public function getServer(int $serverId): array
    {
        return $this->unwrapServer(
            $this->application('GET', '/api/application/servers/'.$serverId, [
                'include' => 'node,user,nest,egg,allocations',
            ]),
        );
    }

    public function getEgg(int $nestId, int $eggId): array
    {
        $payload = $this->application('GET', '/api/application/nests/'.$nestId.'/eggs/'.$eggId, [
            'include' => 'variables',
        ]);
        $attributes = $this->unwrap($payload);
        $attributes['relationships'] = $this->normalizeRelationships($payload);

        return $attributes;
    }

    public function createServer(array $payload): array
    {
        return $this->unwrapServer(
            $this->application('POST', '/api/application/servers', body: $payload),
        );
    }

    public function suspend(int $serverId): void
    {
        $this->application('POST', '/api/application/servers/'.$serverId.'/suspend');
    }

    public function unsuspend(int $serverId): void
    {
        $this->application('POST', '/api/application/servers/'.$serverId.'/unsuspend');
    }

    public function delete(int $serverId): void
    {
        $this->application('DELETE', '/api/application/servers/'.$serverId);
    }

    public function updateBuild(int $serverId, array $payload): array
    {
        return $this->unwrapServerMutation(
            $this->application('PATCH', '/api/application/servers/'.$serverId.'/build', body: $payload),
        );
    }

    public function updateStartup(int $serverId, array $payload): array
    {
        return $this->unwrapServerMutation(
            $this->application('PATCH', '/api/application/servers/'.$serverId.'/startup', body: $payload),
        );
    }

    public function clientServer(string $identifier): array
    {
        return $this->unwrap(
            $this->client('GET', '/api/client/servers/'.rawurlencode($identifier)),
        );
    }

    public function power(string $identifier, string $signal): void
    {
        $this->client('POST', '/api/client/servers/'.rawurlencode($identifier).'/power', body: [
            'signal' => $signal,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function application(string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->request($method, $path, $this->applicationKey(), $query, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function client(string $method, string $path, ?array $body = null): array
    {
        $key = $this->clientKey();
        if ($key === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.power_unavailable');
        }

        return $this->request($method, $path, $key, body: $body, accept: 'application/json');
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        string $token,
        array $query = [],
        ?array $body = null,
        string $accept = 'application/vnd.pterodactyl.v1+json',
    ): array {
        $url = PterodactylPanelUrl::normalize($this->panelUrl()).$path;
        $timeout = max(1, (int) $this->setting('timeout', 15));
        $verify = filter_var($this->setting('verify_tls', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($verify === null) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.invalid_mapping');
        }

        try {
            $pending = Http::withToken($token)
                ->accept($accept)
                ->timeout($timeout)
                ->withOptions([
                    'verify' => $verify,
                    'allow_redirects' => false,
                ]);

            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $query),
                'POST' => $pending->post($url, $body ?? []),
                'PATCH' => $pending->patch($url, $body ?? []),
                'DELETE' => $pending->delete($url, $body ?? []),
                default => throw PterodactylProviderException::failed('pterodactyl::messages.errors.provider_failed'),
            };
        } catch (PterodactylProviderException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.timeout');
        } catch (Throwable) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.unreachable');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.unauthorized', $response->status());
        }

        if ($response->status() === 404) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.not_found', 404);
        }

        if ($response->failed()) {
            throw PterodactylProviderException::failed($this->errorKeyFromResponse($response), $response->status());
        }

        if ($response->body() === '' || $response->body() === '[]') {
            return [];
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrap(array $payload): array
    {
        $attributes = $payload['attributes'] ?? null;
        if (is_array($attributes)) {
            return $attributes;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            return $data['attributes'];
        }

        if ($payload === []) {
            return [];
        }

        throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapServer(array $payload): array
    {
        $attributes = $this->unwrap($payload);
        $id = $this->positiveInteger($attributes['id'] ?? null);
        if ($id === null || ! is_string($attributes['identifier'] ?? null) || trim($attributes['identifier']) === '') {
            throw PterodactylProviderException::failed('pterodactyl::messages.errors.malformed');
        }
        $attributes['id'] = $id;

        $relationships = $this->normalizeRelationships($payload);
        foreach (['user', 'node', 'nest', 'egg', 'location'] as $relationship) {
            $data = $relationships[$relationship]['data'] ?? null;
            if (! is_array($data)) {
                continue;
            }
            $id = $data['id'] ?? null;
            if (is_string($id) || is_int($id)) {
                $attributes[$relationship.'_id'] = $id;
                $attributes[$relationship] = ['id' => $id];
            }
            $relatedAttributes = $data['attributes'] ?? null;
            if (is_array($relatedAttributes)) {
                $attributes[$relationship] = array_merge($attributes[$relationship] ?? [], $relatedAttributes);
            }
        }

        $node = $attributes['node'] ?? null;
        $nodeLocation = is_array($node) ? ($node['relationships']['location']['data'] ?? null) : null;
        if (is_array($nodeLocation)) {
            $nodeLocationId = $nodeLocation['id'] ?? null;
            if (is_string($nodeLocationId) || is_int($nodeLocationId)) {
                $attributes['node']['location_id'] = $nodeLocationId;
                $attributes['node']['location'] = ['id' => $nodeLocationId] + (
                    is_array($nodeLocation['attributes'] ?? null) ? $nodeLocation['attributes'] : []
                );
            }
        }

        $container = $attributes['container'] ?? null;
        if (is_array($container)) {
            foreach (['image' => 'docker_image', 'startup' => 'startup', 'environment' => 'environment'] as $source => $target) {
                if (! array_key_exists($target, $attributes) && array_key_exists($source, $container)) {
                    $attributes[$target] = $container[$source];
                }
            }
        }

        $deploy = $attributes['deploy'] ?? null;
        if (! array_key_exists('dedicated_ip', $attributes)
            && is_array($deploy)
            && array_key_exists('dedicated_ip', $deploy)
        ) {
            $attributes['dedicated_ip'] = $deploy['dedicated_ip'];
        }

        return $attributes;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeRelationships(array $payload): array
    {
        $resource = is_array($payload['data'] ?? null) && isset($payload['data']['attributes'])
            ? $payload['data']
            : $payload;
        $relationships = is_array($resource['relationships'] ?? null) ? $resource['relationships'] : [];
        $included = is_array($payload['included'] ?? null) ? $payload['included'] : [];

        foreach ($relationships as $name => $relationship) {
            if (! is_array($relationship) || ! array_key_exists('data', $relationship)) {
                continue;
            }
            $relationships[$name]['data'] = $this->hydrateIncluded(
                $relationship['data'],
                $included,
            );
        }

        return $relationships;
    }

    /** @param list<mixed> $included */
    private function hydrateIncluded(mixed $data, array $included): mixed
    {
        if (is_array($data) && array_is_list($data)) {
            return array_map(fn (mixed $item): mixed => $this->hydrateIncluded($item, $included), $data);
        }
        if (! is_array($data)) {
            return $data;
        }

        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        foreach ($included as $resource) {
            if (! is_array($resource) || ($resource['id'] ?? null) !== $id) {
                continue;
            }
            if ($type !== null && ($resource['type'] ?? null) !== $type) {
                continue;
            }

            return array_merge($resource, $data, [
                'attributes' => array_merge(
                    is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [],
                    is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
                ),
                'relationships' => array_merge(
                    is_array($resource['relationships'] ?? null) ? $resource['relationships'] : [],
                    is_array($data['relationships'] ?? null) ? $data['relationships'] : [],
                ),
            ]);
        }

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function unwrapServerMutation(array $payload): array
    {
        return $payload === [] ? [] : $this->unwrapServer($payload);
    }

    private function errorKeyFromResponse(Response $response): string
    {
        $json = $response->json();
        $detail = '';
        if (is_array($json) && isset($json['errors'][0]['detail']) && is_string($json['errors'][0]['detail'])) {
            $detail = strtolower($json['errors'][0]['detail']);
        }

        if (str_contains($detail, 'egg') || str_contains($detail, 'nest') || str_contains($detail, 'location')) {
            return 'pterodactyl::messages.errors.invalid_egg';
        }

        if (str_contains($detail, 'memory') || str_contains($detail, 'disk') || str_contains($detail, 'cpu') || str_contains($detail, 'enough')) {
            return 'pterodactyl::messages.errors.quota';
        }

        return 'pterodactyl::messages.errors.provider_failed';
    }

    private function panelUrl(): string
    {
        return trim((string) $this->setting('panel_url', ''));
    }

    private function applicationKey(): string
    {
        $key = trim((string) $this->setting('application_api_key', ''));
        if ($key === '') {
            throw PterodactylProviderException::failed('pterodactyl::messages.health.missing_key');
        }

        return $key;
    }

    private function clientKey(): ?string
    {
        $key = trim((string) $this->setting('client_api_key', ''));

        return $key !== '' ? $key : null;
    }

    private function setting(string $key, mixed $default = null): mixed
    {
        if ($this->connection !== null) {
            return array_key_exists($key, $this->connection)
                ? $this->connection[$key]
                : $default;
        }

        return $this->settings->get('pterodactyl', $key, $default);
    }
}
