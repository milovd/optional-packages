<?php

declare(strict_types=1);

namespace Agovena\Modules\Digital;

use Agovena\Modules\Digital\Models\DigitalAsset;
use Agovena\Modules\Digital\Models\DigitalEntitlement;
use App\Agovena\Notifications\SendsCataloguedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DigitalDeliveryService
{
    public function grantForPaidOrder(Order $order): void
    {
        $granted = DB::transaction(function () use ($order): int {
            $items = OrderItem::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->get();
            $granted = 0;

            foreach ($items as $item) {
                if ($item->product_id === null) {
                    continue;
                }

                $product = Product::query()->with('capabilities')->find($item->product_id);
                if ($product === null || ! $product->hasCapability('digital')) {
                    continue;
                }

                $assets = DigitalAsset::query()
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->get();

                foreach ($assets as $asset) {
                    if (DigitalEntitlement::query()
                        ->where('order_id', $order->id)
                        ->where('digital_asset_id', $asset->id)
                        ->exists()) {
                        continue;
                    }

                    DigitalEntitlement::query()->create([
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'product_id' => $product->id,
                        'digital_asset_id' => $asset->id,
                        'customer_id' => $order->customer_id,
                        'customer_email' => $order->customer_email,
                        'token' => Str::random(48),
                        'download_limit' => $asset->download_limit,
                        'download_count' => 0,
                        'granted_at' => now(),
                    ]);
                    $granted++;
                }
            }

            return $granted;
        });

        if ($granted > 0) {
            app(SendsCataloguedMail::class)->toOrderCustomer(
                $order->customer_id,
                (string) $order->customer_email,
                'digital_entitlement_granted',
                [
                    'name' => (string) $order->customer_name,
                    'number' => $order->number,
                    'detail' => $order->number,
                    'action_url' => Route::has('customer.downloads')
                        ? route('customer.downloads')
                        : url('/'),
                    'action_label' => __('notifications.digital_entitlement_granted.action'),
                ],
            );
        }
    }

    public function download(DigitalEntitlement $entitlement): StreamedResponse
    {
        if (! $entitlement->canDownload()) {
            throw ValidationException::withMessages([
                'download' => __('digital::errors.download_unavailable'),
            ]);
        }

        $asset = $entitlement->asset;
        if ($asset === null || ! $asset->is_active) {
            throw ValidationException::withMessages([
                'download' => __('digital::errors.download_unavailable'),
            ]);
        }

        if (! Storage::disk($asset->disk)->exists($asset->path)) {
            throw ValidationException::withMessages([
                'download' => __('digital::errors.file_missing'),
            ]);
        }

        DB::transaction(function () use ($entitlement): void {
            /** @var DigitalEntitlement $locked */
            $locked = DigitalEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();
            if (! $locked->canDownload()) {
                throw ValidationException::withMessages([
                    'download' => __('digital::errors.download_unavailable'),
                ]);
            }
            $locked->download_count++;
            $locked->save();
        });

        return Storage::disk($asset->disk)->download($asset->path, $asset->filename);
    }
}
