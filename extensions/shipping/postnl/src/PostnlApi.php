<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

interface PostnlApi
{
    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function barcode(array $query): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createShipment(array $payload, ?string $idempotencyKey = null): array;

    /**
     * @return array<string, mixed>
     */
    public function status(string $barcode): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    public function checkout(array $payload): array;
}
