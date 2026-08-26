<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtfusion;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpVirtfusionApi extends AbstractHttpServerApi implements VirtfusionApi
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
