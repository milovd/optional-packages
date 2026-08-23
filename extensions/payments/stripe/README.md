# Stripe Payment Extension

First-party Agovena Payment Extension. Implements `PaymentGateway` plus optional
`OffersCheckoutMethods`, `SynchronizesPayments`, `CancelsPayments`,
`ChargesRecurringPayments`, and `OffersReusablePaymentAuthorization`.

Stripe Checkout is the hosted payment surface. Agovena servers never collect
raw card details.

## Merchant setup

1. Admin → Extensions → enable **Stripe**
2. Save a `sk_test_` or `sk_live_` secret key (encrypted; never redisplayed)
3. Save the webhook signing secret (`whsec_…`)
4. Point Stripe webhooks at `/webhooks/payments/stripe`
5. Optional: `AGOVENA_EXT_STRIPE_SECRET_KEY` and `AGOVENA_EXT_STRIPE_WEBHOOK_SECRET`

## Webhooks

Stripe signs events with `Stripe-Signature`. This Extension verifies the
payload with the official Stripe PHP SDK before mapping into the generic
`HandlePaymentWebhook` pipeline. Return URLs are UX only.

## Recurring

Reusable payment-method ids stay in the Extension table
`stripe_payment_authorizations`. Subscriptions charge through the generic
`ChargeRecurringPayment` action and never import Stripe types.

## Tests

CI uses a fake Stripe HTTP client. Live Stripe credentials are not required
and must not be committed.
