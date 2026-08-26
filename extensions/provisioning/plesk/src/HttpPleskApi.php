<?php

declare(strict_types=1);

namespace Agovena\Extensions\Plesk;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpPleskApi extends AbstractHttpServerApi implements PleskApi
{
    protected function collectionPath(): string
    {
        return '/api/v2/servers';
    }

    protected function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-API-Key' => trim((string) ($this->connection['api_token'] ?? '')),
        ];
    }
}
