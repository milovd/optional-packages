<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Http\Controllers\Api;

use Agovena\Modules\Digital\DigitalDeliveryService;
use Agovena\Modules\Digital\Models\DigitalEntitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadApiController
{
    public function index(): JsonResponse
    {
        $customer = authenticated_customer();
        $rows = DigitalEntitlement::query()
            ->with('asset')
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $rows->getCollection()->map(static function (DigitalEntitlement $row): array {
                return [
                    'token' => $row->token,
                    'label' => $row->asset?->label,
                    'filename' => $row->asset?->filename,
                    'download_count' => $row->download_count,
                    'download_limit' => $row->download_limit,
                    'can_download' => $row->canDownload(),
                    'granted_at' => $row->granted_at->toIso8601String(),
                ];
            })->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function file(string $token, DigitalDeliveryService $digital): StreamedResponse
    {
        $customer = authenticated_customer();
        $entitlement = DigitalEntitlement::query()
            ->with('asset')
            ->where('token', $token)
            ->where('customer_id', $customer->id)
            ->firstOrFail();

        try {
            return $digital->download($entitlement);
        } catch (ValidationException $e) {
            abort(409, $e->errors()['download'][0] ?? __('digital::errors.download_unavailable'));
        }
    }
}
