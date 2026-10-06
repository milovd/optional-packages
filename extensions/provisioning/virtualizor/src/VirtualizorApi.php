<?php

declare(strict_types=1);

namespace Agovena\Extensions\Virtualizor;

use Agovena\Modules\Provisioning\Support\ServerApi;

/**
 * Virtualizor Admin API seam (index.php?act=...). Each method maps to one documented call.
 *
 * @phpstan-type Plan array{virt: string, enabled: bool, ips: int, space: int, ram: int, bandwidth: int, cores: int}
 * @phpstan-type Vps array{vpsid: string, uid: int, plid: int, virt: string, hostname: string|null, suspended: bool}
 */
interface VirtualizorApi extends ServerApi
{
    /** @param array<string, mixed> $settings */
    public function withConnection(array $settings): static;

    /** @return array<int, Plan> Plans keyed by plid (act=plans). */
    public function plans(): array;

    /** @return list<int> Ids of the users holding exactly this email address (act=users). */
    public function userIdsByEmail(string $email): array;

    /**
     * Creates a VPS (act=addvs) and returns the identifiers Virtualizor assigned.
     *
     * @param  array<string, string>  $form
     * @return array{vpsid: string, uid: int, hostname: string|null}
     */
    public function createVps(array $form): array;

    /** @return Vps The VPS with this id (act=vs search); a missing VPS throws errors.not_found. */
    public function vps(string $vpsId): array;

    public function suspendVps(string $vpsId): void;

    public function unsuspendVps(string $vpsId): void;

    public function deleteVps(string $vpsId): void;

    /** Applies the plan values of plid to the VPS (act=managevps). */
    public function applyPlan(string $vpsId, int $planId): void;
}
