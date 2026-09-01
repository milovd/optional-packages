<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital\Http\Controllers;

use Agovena\Modules\Digital\DigitalDeliveryService;
use Agovena\Modules\Digital\Models\DigitalEntitlement;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadController
{
    public function __invoke(string $token, DigitalDeliveryService $digital): StreamedResponse
    {
        $customer = authenticated_customer();

        $entitlement = DigitalEntitlement::query()
            ->with('asset')
            ->where('token', $token)
            ->where(function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id)
                    ->orWhere(function ($legacy) use ($customer): void {
                        $legacy->whereNull('customer_id')
                            ->where('customer_email', $customer->email);
                    });
            })
            ->firstOrFail();

        try {
            return $digital->download($entitlement);
        } catch (ValidationException $e) {
            abort(403, $e->errors()['download'][0] ?? __('digital::errors.download_unavailable'));
        }
    }
}
