<?php

declare(strict_types=1);

namespace Agovena\Modules\DigitalDelivery\Http\Controllers\Api;

use Agovena\Modules\DigitalDelivery\DigitalSecretFulfillmentService;
use Agovena\Modules\DigitalDelivery\Models\DigitalSecretDelivery;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Customer-owned deliveries only. The merchant pool is never exposed here.
 */
final class SecretApiController
{
    public function index(DigitalSecretFulfillmentService $secrets): JsonResponse
    {
        /** @var Customer $customer */
        $customer = authenticated_customer();

        $rows = $secrets->deliveriesForCustomer($customer)
            ->with('product')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(static function (DigitalSecretDelivery $row) use ($customer): array {
                return [
                    'id' => $row->id,
                    'product' => $row->product?->name,
                    'status' => $row->status,
                    'value_hint' => $row->value_hint,
                    // Plaintext is returned only to the owning customer on a delivered row.
                    'value' => $row->isReadableBy($customer) ? $row->plainValue() : null,
                    'granted_at' => $row->granted_at?->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }
}
