<?php

declare(strict_types=1);

namespace Agovena\Modules\Subscriptions\Http\Controllers\Api;

use Agovena\Modules\Subscriptions\Models\Subscription;
use Illuminate\Http\JsonResponse;

final class SubscriptionApiController
{
    public function index(): JsonResponse
    {
        $customer = authenticated_customer();
        $rows = Subscription::query()
            ->with('product')
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Subscription $row): array => $this->serialize($row))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(int $subscription): JsonResponse
    {
        $customer = authenticated_customer();
        $row = Subscription::query()
            ->with('product')
            ->where('customer_id', $customer->id)
            ->whereKey($subscription)
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($row)]);
    }

    /** @return array<string, mixed> */
    private function serialize(Subscription $row): array
    {
        return [
            'id' => $row->id,
            'number' => $row->number,
            'status' => $row->status->value,
            'product' => $row->product?->name,
            'interval' => $row->interval->value,
            'interval_count' => $row->interval_count,
            'price_amount' => $row->price_amount,
            'currency' => $row->currency,
            'current_period_end' => $row->current_period_end?->toIso8601String(),
            'next_billing_at' => $row->next_billing_at?->toIso8601String(),
            'cancel_at_period_end' => $row->cancel_at_period_end,
        ];
    }
}
