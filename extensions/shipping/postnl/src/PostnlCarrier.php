<?php

declare(strict_types=1);

namespace Agovena\Extensions\Postnl;

use App\Agovena\Checkout\ShippingDestination;
use App\Agovena\Extensions\ExtensionSettingsRepository;
use App\Agovena\Payments\HealthResult;
use App\Agovena\Shipping\CarrierShipmentResult;
use App\Agovena\Shipping\Contracts\CreatesCarrierShipments;
use App\Agovena\Shipping\Contracts\QuotesCartRates;
use App\Agovena\Shipping\Contracts\QuotesShippingRates;
use App\Agovena\Shipping\Contracts\ShippingCarrier;
use App\Agovena\Shipping\Contracts\TracksShipments;
use App\Agovena\Shipping\ShippingRateQuote;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class PostnlCarrier implements CreatesCarrierShipments, QuotesCartRates, QuotesShippingRates, ShippingCarrier, TracksShipments
{
    public const ID = 'postnl';

    public function __construct(
        private readonly ExtensionSettingsRepository $settings,
        private readonly ?PostnlApi $api = null,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'postnl::messages.carrier.label';
    }

    public function health(): HealthResult
    {
        if ($this->apiKey() === null) {
            return HealthResult::fail(__('postnl::messages.health.missing_key'));
        }
        if ($this->customerCode() === '' || $this->customerNumber() === '') {
            return HealthResult::fail(__('postnl::messages.health.missing_customer'));
        }

        try {
            $this->client()->barcode([
                'CustomerCode' => $this->customerCode(),
                'CustomerNumber' => $this->customerNumber(),
                'Type' => '3S',
                'Serie' => '000000000-999999999',
            ]);
        } catch (PostnlProviderException $exception) {
            return HealthResult::fail(__($exception->errorKey));
        }

        $mode = (bool) $this->settings->get('postnl', 'sandbox', true) ? 'sandbox' : 'live';

        return HealthResult::ok(__('postnl::messages.health.ok', ['mode' => $mode]));
    }

    public function quote(Order $order): array
    {
        return $this->quoteCart([], $this->destinationFromOrder($order), $order->currency);
    }

    public function quoteCart(array $lines, ShippingDestination $destination, string $currency): array
    {
        if (! $destination->isComplete()) {
            return [];
        }

        try {
            $parsed = PostnlStreetParser::parse($destination->line1);
            $options = $this->client()->checkout([
                'OrderDate' => now()->toIso8601String(),
                'ShippingDuration' => 1,
                'CutOffTimes' => [['Day' => '00', 'Available' => true, 'Time' => '23:00:00']],
                'HolidaySorting' => true,
                'Options' => ['Daytime'],
                'Destination' => [
                    'AddressType' => '01',
                    'City' => $destination->city,
                    'Countrycode' => $destination->country,
                    'Street' => $parsed['street'],
                    'HouseNr' => $parsed['house'],
                    'Zipcode' => preg_replace('/\s+/', '', $destination->postalCode),
                ],
            ]);
        } catch (PostnlProviderException) {
            return [];
        }

        $quotes = [];
        foreach ($options as $option) {
            $code = (string) ($option['ProductCode'] ?? $option['productCode'] ?? '');
            if ($code === '') {
                continue;
            }
            $amount = $this->minorAmount($option['Price'] ?? $option['price'] ?? 0, $currency);
            $quotes[] = new ShippingRateQuote(
                carrierId: self::ID,
                serviceCode: $code,
                serviceLabel: $this->serviceLabel($code, (string) ($option['Description'] ?? $option['description'] ?? $code)),
                amount: $amount,
                currency: $currency,
                transitDays: isset($option['DeliveryDays']) ? (int) $option['DeliveryDays'] : null,
                estimatedDelivery: isset($option['DeliveryDate']) ? (string) $option['DeliveryDate'] : null,
            );
        }

        return $quotes;
    }

    public function createShipment(Order $order, string $serviceCode): CarrierShipmentResult
    {
        $existing = $this->existing($order->id);
        if ($existing !== null) {
            return $this->resultFromRow($existing);
        }

        $address = $this->shippingAddress($order);
        $code = $serviceCode !== '' ? $serviceCode : $this->defaultProductCode();

        try {
            $parsed = PostnlStreetParser::parse($address['line1']);
            if ($address['country'] !== 'NL' && $code === '3085') {
                throw PostnlProviderException::failed('postnl::messages.errors.unsupported_destination', 422);
            }
            $barcodePayload = $this->client()->barcode([
                'CustomerCode' => $this->customerCode(),
                'CustomerNumber' => $this->customerNumber(),
                'Type' => '3S',
                'Serie' => '000000000-999999999',
            ]);
            $barcode = (string) ($barcodePayload['Barcode'] ?? $barcodePayload['barcode'] ?? '');
            if ($barcode === '') {
                throw PostnlProviderException::failed('postnl::messages.errors.create_failed');
            }

            $created = $this->client()->createShipment([
                'Customer' => array_filter([
                    'Address' => [
                        'AddressType' => '02',
                        'City' => $address['city'],
                        'Countrycode' => $address['country'],
                        'Street' => $parsed['street'],
                        'HouseNr' => $parsed['house'],
                        'Zipcode' => preg_replace('/\s+/', '', $address['postal']),
                    ],
                    'CollectionLocation' => $this->collectionLocation(),
                    'CustomerCode' => $this->customerCode(),
                    'CustomerNumber' => $this->customerNumber(),
                    'Email' => $order->customer_email,
                ]),
                'Message' => [
                    'MessageID' => (string) $order->id,
                    'MessageTimeStamp' => now()->format('d-m-Y H:i:s'),
                    'Printertype' => 'GraphicFile|PDF',
                ],
                'Shipments' => [[
                    'Addresses' => [[
                        'AddressType' => '01',
                        'City' => $address['city'],
                        'CompanyName' => $address['company'],
                        'Countrycode' => $address['country'],
                        'FirstName' => $address['name'],
                        'HouseNr' => $parsed['house'],
                        'HouseNrExt' => $parsed['addition'],
                        'Street' => $parsed['street'],
                        'Zipcode' => preg_replace('/\s+/', '', $address['postal']),
                    ]],
                    'Barcode' => $barcode,
                    'Contacts' => [[
                        'ContactType' => '01',
                        'Email' => $order->customer_email,
                    ]],
                    'Dimension' => [
                        'Weight' => max(1, $this->weightGrams($order)),
                    ],
                    'ProductCodeDelivery' => $code,
                ]],
            ], 'postnl-order-'.$order->id);
        } catch (PostnlProviderException $exception) {
            throw ValidationException::withMessages([
                'shipping' => __($exception->errorKey),
            ]);
        }

        $labelPath = $this->storeLabel($order->id, $barcode, $created);
        $this->storeRow($order->id, $barcode, $code, $labelPath, 'created');

        return new CarrierShipmentResult(
            externalId: $barcode,
            trackingNumber: $barcode,
            trackingUrl: $this->trackingUrl($barcode, $address['postal'], $address['country']),
            labelPath: $labelPath,
            status: 'processing',
        );
    }

    public function cancelShipment(string $externalId): void
    {
        throw ValidationException::withMessages([
            'shipping' => __('postnl::messages.errors.cancel_unsupported'),
        ]);
    }

    public function tracking(string $externalId): array
    {
        try {
            $remote = $this->client()->status($externalId);
        } catch (PostnlProviderException $exception) {
            throw ValidationException::withMessages([
                'shipping' => __($exception->errorKey),
            ]);
        }

        $phase = strtolower((string) ($remote['Status']['PhaseCode'] ?? $remote['status'] ?? 'processing'));
        $status = match (true) {
            str_contains($phase, 'deliver') || $phase === '7' => 'delivered',
            str_contains($phase, 'transit') || in_array($phase, ['2', '3', '4', '5'], true) => 'shipped',
            str_contains($phase, 'cancel') => 'cancelled',
            default => 'processing',
        };

        $row = PostnlShipment::query()->where('barcode', $externalId)->first();
        $postal = '';
        $country = 'NL';
        if ($row !== null) {
            $row->provider_status = $phase;
            $row->save();
            $mapped = Order::query()->find($row->order_id);
            if ($mapped !== null) {
                $postal = (string) ($mapped->shipping_postal_code ?: $mapped->billing_postal_code);
                $country = strtoupper((string) ($mapped->shipping_country ?: $mapped->billing_country ?: 'NL'));
            }
        }

        return [
            'status' => $status,
            'tracking_number' => $externalId,
            'tracking_url' => $this->trackingUrl($externalId, $postal, $country),
        ];
    }

    private function client(): PostnlApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        return app(HttpPostnlApi::class);
    }

    private function existing(int $orderId): ?PostnlShipment
    {
        if (! Schema::hasTable('postnl_shipments')) {
            return null;
        }

        return PostnlShipment::query()->where('order_id', $orderId)->first();
    }

    private function storeRow(int $orderId, string $barcode, string $code, string $labelPath, string $status): void
    {
        if (! Schema::hasTable('postnl_shipments')) {
            return;
        }

        PostnlShipment::query()->updateOrCreate(
            ['order_id' => $orderId],
            [
                'barcode' => $barcode,
                'product_code' => $code,
                'label_path' => $labelPath,
                'provider_status' => $status,
            ],
        );
    }

    private function resultFromRow(PostnlShipment $row): CarrierShipmentResult
    {
        return new CarrierShipmentResult(
            externalId: $row->barcode,
            trackingNumber: $row->barcode,
            trackingUrl: $this->trackingUrl($row->barcode, '', 'NL'),
            labelPath: $row->label_path,
            status: 'processing',
        );
    }

    /**
     * @param  array<string, mixed>  $created
     */
    private function storeLabel(int $orderId, string $barcode, array $created): string
    {
        $shipments = $created['ResponseShipments'] ?? $created['responseShipments'] ?? [];
        $first = is_array($shipments) ? ($shipments[0] ?? null) : null;
        $labels = is_array($first) ? ($first['Labels'] ?? $first['labels'] ?? []) : [];
        $label = is_array($labels) ? ($labels[0] ?? null) : null;
        $content = is_array($label) ? ($label['Content'] ?? $label['content'] ?? null) : null;
        if (! is_string($content) || $content === '') {
            throw ValidationException::withMessages([
                'shipping' => __('postnl::messages.errors.label_failed'),
            ]);
        }

        $binary = base64_decode($content, true);
        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'shipping' => __('postnl::messages.errors.label_failed'),
            ]);
        }

        $path = 'shipping-labels/postnl/'.$orderId.'-'.$barcode.'.pdf';
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    /**
     * @return array{name: string, company: ?string, line1: string, city: string, postal: string, country: string}
     */
    private function shippingAddress(Order $order): array
    {
        $line1 = (string) ($order->shipping_line1 ?: $order->billing_line1);
        $city = (string) ($order->shipping_city ?: $order->billing_city);
        $postal = (string) ($order->shipping_postal_code ?: $order->billing_postal_code);
        $country = strtoupper((string) ($order->shipping_country ?: $order->billing_country ?: 'NL'));

        if ($line1 === '' || $city === '' || $postal === '') {
            throw ValidationException::withMessages([
                'shipping' => __('postnl::messages.errors.invalid_address'),
            ]);
        }

        return [
            'name' => (string) ($order->shipping_name ?: $order->billing_name ?: $order->customer_name),
            'company' => $order->shipping_company ?: $order->billing_company,
            'line1' => $line1,
            'city' => $city,
            'postal' => $postal,
            'country' => $country,
        ];
    }

    private function destinationFromOrder(Order $order): ShippingDestination
    {
        return new ShippingDestination(
            country: strtoupper((string) ($order->shipping_country ?: $order->billing_country ?: 'NL')),
            postalCode: (string) ($order->shipping_postal_code ?: $order->billing_postal_code),
            city: (string) ($order->shipping_city ?: $order->billing_city),
            line1: (string) ($order->shipping_line1 ?: $order->billing_line1),
        );
    }

    private function weightGrams(Order $order): int
    {
        $order->loadMissing('items');
        $grams = 0;
        foreach ($order->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $product = Product::query()->with('capabilities')->find($item->product_id);
            $weight = 0;
            if ($product !== null) {
                $row = $product->capability('shippable');
                if ($row !== null) {
                    $config = is_array($row->config) ? $row->config : [];
                    $weight = (int) ($config['weight_grams'] ?? 0);
                }
            }
            $grams += max(0, $weight) * $item->quantity;
        }

        return $grams > 0 ? $grams : 500;
    }

    private function trackingUrl(string $barcode, string $postal, string $country): string
    {
        $postcode = preg_replace('/\s+/', '', $postal) ?: '1000AA';

        return 'https://jouw.postnl.nl/track-and-trace/'.$barcode.'-'.strtoupper($country).'-'.$postcode;
    }

    private function serviceLabel(string $code, string $fallback): string
    {
        $key = 'postnl::messages.services.'.$code;

        return Lang::has($key) ? (string) __($key) : $fallback;
    }

    private function minorAmount(mixed $price, string $currency): int
    {
        if (is_int($price)) {
            return $price;
        }
        if (is_float($price) || (is_string($price) && is_numeric($price))) {
            return (int) round(((float) $price) * 100);
        }

        return 0;
    }

    private function apiKey(): ?string
    {
        $key = $this->settings->get('postnl', 'api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function customerCode(): string
    {
        return strtoupper(trim((string) $this->settings->get('postnl', 'customer_code', '')));
    }

    private function customerNumber(): string
    {
        return trim((string) $this->settings->get('postnl', 'customer_number', ''));
    }

    private function collectionLocation(): ?string
    {
        $value = trim((string) $this->settings->get('postnl', 'collection_location', ''));

        return $value !== '' ? $value : null;
    }

    private function defaultProductCode(): string
    {
        $code = trim((string) $this->settings->get('postnl', 'default_product_code', '3085'));

        return $code !== '' ? $code : '3085';
    }
}
