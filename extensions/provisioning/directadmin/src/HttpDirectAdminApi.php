<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use Agovena\Modules\Provisioning\Support\AbstractHttpServerApi;

final class HttpDirectAdminApi extends AbstractHttpServerApi implements DirectAdminApi
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
