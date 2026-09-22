# Stripe Payment Extension

First-party Agovena Payment Extension. Implements `PaymentGateway` plus optional
`OffersCheckoutMethods`, `ConfiguresCheckoutMethods`, `SynchronizesPayments`,
`CancelsPayments`, `ChargesRecurringPayments`, and
`OffersReusablePaymentAuthorization`.

Stripe Checkout remains the hosted payment surface. Agovena servers never
collect raw card details. Stripe Payment Method Configurations are discovered
from the provider API. The Admin can enable or disable each currently
available Checkout method, for example card, iDEAL or Bancontact. Selecting
`stripe:bancontact` starts a Checkout Session restricted to Bancontact.

## Merchant setup

1. Admin -> Extensions -> enable **Stripe**
2. Save a `sk_test_` or `sk_live_` secret key (encrypted; never redisplayed)
3. Save the webhook signing secret (`whsec_...`)
4. Point Stripe webhooks at `/webhooks/payments/stripe`
5. Optional: `AGOVENA_EXT_STRIPE_SECRET_KEY` and `AGOVENA_EXT_STRIPE_WEBHOOK_SECRET`

The method list comes from Stripe's active Payment Method Configuration. An
empty method selection exposes every method Stripe reports as available. Stripe
hosts the official payment surface and its provider-side method presentation.

## Webhooks

Stripe signs events with `Stripe-Signature`. This Extension verifies the
payload with the official Stripe PHP SDK before mapping into the generic
`HandlePaymentWebhook` pipeline. Return URLs are UX only.

## Recurring

Reusable payment-method ids stay in the Extension table
`stripe_payment_authorizations`. Subscriptions charge through the generic
`ChargeRecurringPayment` action and never import Stripe types. Automatic
renewal is limited to methods that Stripe supports for reusable off-session
charges. Bancontact and iDEAL can be used for one-time Checkout payments but
are not silently treated as recurring authorizations.

## Refunds

Full and partial refunds use Stripe's Refunds API and remain linked to the
normal Agovena refund administration. Unknown provider outcomes stay pending
for reconciliation.

## Tests

CI uses a fake Stripe HTTP client. Live Stripe credentials are not required
and must not be committed.
