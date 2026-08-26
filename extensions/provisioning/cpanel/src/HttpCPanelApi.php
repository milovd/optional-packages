<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpCPanelApi extends AbstractHttpServerApi implements CPanelApi
{
    protected function collectionPath(): string
    {
        return '/api/v1/servers';
    }

    protected function headers(): array
    {
        $token = trim((string) ($this->connection['api_token'] ?? ''));
        $username = trim((string) ($this->connection['api_username'] ?? ''));

        return [
            'Accept' => 'application/json',
            'Authorization' => 'WHM '.$username.':'.$token,
        ];
    }
}
