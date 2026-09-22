# Stripe Payment Extension

Production-ready first-party Agovena Payment Extension for Stripe Checkout. The current integration supports test mode and live mode, provider-discovered Checkout methods, country-aware filtering, signed webhooks, idempotent event processing, status synchronization, refunds, and supported off-session charges.

Stripe Checkout remains the hosted payment surface. Agovena servers never collect raw card details.

## Merchant setup

1. Admin -> Extensions -> enable **Stripe**.
2. Use `sk_test_...` and a matching `whsec_...` secret while testing.
3. Configure `secret_key` and `webhook_secret` in protected Extension settings.
4. Use `enabled_methods` to restrict the provider-discovered Checkout methods, or leave it empty to use all currently available methods.
5. Use this webhook route:

```text
POST https://shop.example.com/webhooks/payments/stripe
```

Optional environment overrides are available for secret stores:

```dotenv
AGOVENA_EXT_STRIPE_SECRET_KEY=sk_test_...
AGOVENA_EXT_STRIPE_WEBHOOK_SECRET=whsec_...
```

Never commit real values or place them in screenshots, logs, tickets, or chat. Stored Extension secrets are encrypted and are not redisplayed.

## Webhooks

Stripe signs events with `Stripe-Signature`. The Extension verifies the raw request body with the official Stripe PHP SDK before mapping it into Core's `HandlePaymentWebhook` pipeline. Return URLs are UX only and do not mark an order paid.

Configure the events used by the integration:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `charge.refunded`

Events are stored idempotently by gateway and Stripe event ID. Duplicate delivery does not create a second payment, fulfillment action, or refund. Events without a known Agovena payment attempt are deferred for reconciliation.

## Stripe CLI local forwarding

Install and authenticate the official Stripe CLI:

```bash
npm install -g @stripe/cli
stripe login
```

Forward test events to a local Agovena server:

```bash
stripe listen --forward-to http://127.0.0.1:8000/webhooks/payments/stripe
```

The CLI prints a temporary `whsec_...` signing secret. Use it only for this local forwarding session. Do not use it as the Dashboard endpoint secret, and never share it. Stop forwarding with `Ctrl+C`.

Use triggers for signature and mapper smoke tests:

```bash
stripe trigger payment_intent.succeeded
stripe trigger payment_intent.payment_failed
```

CLI triggers do not automatically belong to an existing Agovena order. A real order test must start a test Checkout from Agovena and verify the resulting event and payment status.

## Live mode

For production use, configure a separate `sk_live_...` key and live `whsec_...` endpoint secret. Register the HTTPS route in Stripe Workbench or Webhooks. Stripe CLI forwarding is for local test events and does not replace the live Dashboard endpoint.

Rotate API keys and webhook secrets in a controlled maintenance window. Agovena supports one active webhook secret per configuration, so coordinate the Stripe rotation and the Agovena setting update, then verify a delivery.

## Recurring and refunds

Reusable payment method IDs stay in the Extension table `stripe_payment_authorizations`. Automatic renewal is limited to methods that Stripe supports for reusable off-session charges. Bancontact and iDEAL are one-time Checkout methods in this integration.

Full and partial refunds use Stripe's Refunds API and remain linked to normal Agovena refund administration. Unknown provider outcomes stay pending for reconciliation.

## Verification

CI uses a fake Stripe HTTP client. Live Stripe credentials are not required and must not be committed. Provider-live verification remains an operator step using the documented test and live checklist.
