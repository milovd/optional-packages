<?php

declare(strict_types=1);

namespace Agovena\Extensions\CPanel;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * WHM API 1 seam. Generic ServerApi methods map to WHM functions:
 * createServer => createacct, getServer => accountsummary,
 * findServerByExternalId => listaccts (exact user search), suspend => suspendacct,
 * unsuspend => unsuspendacct, terminate => removeacct, changePlan => changepackage.
 */
interface CPanelApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return list<string> Names of the packages the token may assign (listpkgs want=creatable). */
    public function creatablePackages(): array;
}
