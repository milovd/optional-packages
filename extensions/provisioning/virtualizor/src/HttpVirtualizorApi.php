<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpVirtualizorApi extends AbstractHttpServerApi implements VirtualizorApi
{
    protected function collectionPath(): string
    {
        return '/api/v1/servers';
    }

    protected function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.trim((string) ($this->connection['api_token'] ?? '')),
            'X-API-Secret' => trim((string) ($this->connection['api_secret'] ?? '')),
        ];
    }
}
