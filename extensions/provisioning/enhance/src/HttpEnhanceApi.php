<?php

declare(strict_types=1);

namespace Agovena\Extensions\Enhance;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpEnhanceApi extends AbstractHttpServerApi implements EnhanceApi
{
    protected function collectionPath(): string
    {
        return '/api/v1/servers';
    }

    protected function headers(): array
    {
        return parent::headers();
    }
}
