<?php

declare(strict_types=1);

namespace Agovena\Extensions\PayPal;

use App\Agovena\Orders\StorefrontOrderAccess;
use App\Agovena\Theme\ThemeManager;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Component;

final class PayPalCheckoutPage extends Component
{
    public ?string $clientId = null;

    public bool $sandbox = true;

    public ?string $orderId = null;

    public ?string $returnUrl = null;

    public string $currency = 'USD';

    public function mount(PayPalPaymentGateway $gateway, Request $request, StorefrontOrderAccess $access): void
    {
        $this->clientId = $gateway->clientSideId();
        $this->sandbox = $gateway->isSandbox();
        $this->orderId = $this->validOrderId($request->query('_porder'));

        if ($this->orderId === null) {
            return;
        }

        $attempt = PaymentAttempt::query()
            ->with(['order.payment'])
            ->where('gateway_id', PayPalPaymentGateway::ID)
            ->where('external_id', $this->orderId)
            ->latest('id')
            ->first();
        $order = $attempt?->order;
        if ($attempt === null || $order === null || $order->payment === null) {
            $this->orderId = null;

            return;
        }

        $access->authorize($request, $order);
        $this->returnUrl = $access->paymentStatusUrl($order);
        $this->currency = strtoupper((string) $order->payment->currency);

        if ($order->payment->status === PaymentStatus::Paid) {
            throw new HttpResponseException(new RedirectResponse($access->confirmationUrl($order)));
        }
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();

        return view('paypal::checkout', [
            'theme' => $theme,
            'clientId' => $this->clientId,
            'sandbox' => $this->sandbox,
            'orderId' => $this->orderId,
            'returnUrl' => $this->returnUrl,
            'currency' => $this->currency,
            'locale' => in_array(app()->getLocale(), ['en', 'nl'], true) ? app()->getLocale() : 'en',
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('paypal::messages.checkout.title'),
            'theme' => $theme,
        ]);
    }

    private function validOrderId(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
