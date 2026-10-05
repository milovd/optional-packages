<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;
use Agovena\Modules\Provisioning\Support\ServerProviderException;

final class HttpCPanelApi extends AbstractHttpServerApi implements CPanelApi
{
    /** @return array<string, mixed> */
    public function connectionTest(): array
    {
        if (trim((string) ($this->connection['api_username'] ?? '')) === ''
            || trim((string) ($this->connection['api_token'] ?? '')) === '') {
            throw new ServerProviderException('errors.not_configured');
        }

        $payload = $this->request('GET', '/json-api/listaccts', ['api.version' => 1]);
        if (($payload['metadata']['command'] ?? null) !== 'listaccts'
            || ($payload['metadata']['result'] ?? null) !== 1
            || ! is_array($payload['data']['acct'] ?? null)) {
            throw new ServerProviderException('errors.provider_failed');
        }

        return $payload;
    }

    protected function headers(): array
    {
        $token = trim((string) ($this->connection['api_token'] ?? ''));
        $username = trim((string) ($this->connection['api_username'] ?? ''));

        return [
            'Accept' => 'application/json',
            'Authorization' => 'whm '.$username.':'.$token,
        ];
    }
}
