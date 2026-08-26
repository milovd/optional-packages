<?php

declare(strict_types=1);

namespace Agovena\Extensions\Convoy;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpConvoyApi extends AbstractHttpServerApi implements ConvoyApi
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
