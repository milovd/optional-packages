<?php

declare(strict_types=1);

namespace Agovena\Extensions\Paddle;

use App\Agovena\Orders\StorefrontOrderAccess;
use App\Agovena\Theme\ThemeManager;
use App\Enums\PaymentStatus;
use App\Models\PaymentAttempt;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Component;

final class PaddleCheckoutPage extends Component
{
    public ?string $clientToken = null;

    public bool $sandbox = true;

    public ?string $transactionId = null;

    public ?string $returnUrl = null;

    public ?string $requestedPaymentMethod = null;

    public function mount(PaddlePaymentGateway $gateway, Request $request, StorefrontOrderAccess $access): void
    {
        $this->clientToken = $gateway->clientSideToken();
        $this->sandbox = $gateway->isSandbox();
        $this->transactionId = $this->validTransactionId($request->query('_ptxn'));
        $this->requestedPaymentMethod = $this->validPaymentMethod($request->query('allowed_payment_methods'));

        if ($this->transactionId === null) {
            return;
        }

        $attempt = PaymentAttempt::query()
            ->with(['order.payment'])
            ->where('gateway_id', PaddlePaymentGateway::ID)
            ->where('external_id', $this->transactionId)
            ->latest('id')
            ->first();
        $order = $attempt?->order;
        if ($order === null) {
            return;
        }

        $this->returnUrl = $access->paymentStatusUrl($order);

        if ($order->payment?->status === PaymentStatus::Paid && $access->allows($request, $order)) {
            throw new HttpResponseException(new RedirectResponse($access->confirmationUrl($order)));
        }
    }

    public function render(ThemeManager $themes)
    {
        $theme = $themes->active();

        return view('paddle::checkout', [
            'theme' => $theme,
            'clientToken' => $this->clientToken,
            'sandbox' => $this->sandbox,
            'transactionId' => $this->transactionId,
            'returnUrl' => $this->returnUrl,
            'allowedPaymentMethods' => $this->requestedPaymentMethod === null
                ? []
                : [$this->requestedPaymentMethod],
        ])->layout($theme->view('layouts.storefront'), [
            'title' => __('paddle::messages.checkout.title'),
            'theme' => $theme,
        ]);
    }

    private function validTransactionId(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\Atxn_[A-Za-z0-9]+\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function validPaymentMethod(mixed $value): ?string
    {
        if (! is_string($value) || ! PaddlePaymentGateway::supportsCheckoutMethod($value)) {
            return null;
        }

        return $value;
    }
}
