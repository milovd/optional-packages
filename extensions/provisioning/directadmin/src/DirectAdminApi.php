<?php

declare(strict_types=1);

namespace Agovena\Extensions\DirectAdmin;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * findServerByExternalId() returns null only when the user is not owned by the API account.
 */
interface DirectAdminApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return list<string> */
    public function packages(): array;
}
