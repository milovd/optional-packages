<?php

declare(strict_types=1);

namespace Agovena\Modules\Provisioning\Http\Controllers\Api;

use Agovena\Modules\Provisioning\Models\ServiceInstance;
use Illuminate\Http\JsonResponse;

final class ServiceApiController
{
    public function index(): JsonResponse
    {
        $customer = authenticated_customer();
        $rows = ServiceInstance::query()
            ->with('product')
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (ServiceInstance $row): array => $this->serialize($row))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(int $instance): JsonResponse
    {
        $customer = authenticated_customer();
        $row = ServiceInstance::query()
            ->with('product')
            ->where('customer_id', $customer->id)
            ->whereKey($instance)
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($row)]);
    }

    /** @return array<string, mixed> */
    private function serialize(ServiceInstance $row): array
    {
        return [
            'id' => $row->id,
            'number' => $row->number,
            'status' => $row->status->value,
            'product' => $row->product?->name,
            'external_ref' => $row->external_ref,
            'activated_at' => $row->activated_at?->toIso8601String(),
            'suspended_at' => $row->suspended_at?->toIso8601String(),
        ];
    }
}
