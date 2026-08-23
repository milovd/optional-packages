# Mollie Payment Extension

First-party Agovena Payment Extension. Implements `PaymentGateway` plus optional
`OffersCheckoutMethods`, `SynchronizesPayments`, `CancelsPayments`, and
`ChargesRecurringPayments`.

## Merchant setup

1. Admin → Extensions → install and enable **Mollie**
2. Save a `test_` or `live_` API key (encrypted at rest; never redisplayed)
3. Optional: `AGOVENA_EXT_MOLLIE_API_KEY` environment override
4. Point Mollie webhooks at `/webhooks/payments/mollie`
5. Run **Test connection** to discover enabled methods

Mollie methods (iDEAL, Bancontact, cards, PayPal, …) are checkout methods of this
Extension, not separate Extensions.

## Developer notes

- Return URLs are UX only. Webhook fetch + `SynchronizesPayments` are authoritative.
- Mollie webhooks POST `id`; Agovena fetches the payment with the merchant API key.
  There is no HMAC to invent.
- Provider payment ids live on `payment_attempts.external_id`.
- Customer/mandate ids live in Extension table `mollie_mandates`.
- Core and Modules must not import `Mollie\Api\*` or this namespace except via
  the generic payment contracts.

## Recurring

`ChargesRecurringPayments` can create an off-session recurring payment when a
mandate exists. Subscriptions request that charge through Core
`ChargeRecurringPayment` and never import Mollie types.
