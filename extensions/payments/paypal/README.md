# PayPal payment extension

PayPal Checkout for Agovena with:

- One storefront checkout option using `ag:payment-method/paypal`
- The official PayPal SVG asset in the Core payment-method catalog
- PayPal's official JS SDK popup overlay for storefront checkout
- `onApprove`, `onCancel` and `onError` return handling
- Orders v2 for one-time payments and the first automatic recurring payment
- Vault storage with `store_in_vault = ON_SUCCESS`
- Core-managed merchant-initiated renewals using `vault_id` and `stored_credential`
- Capture after verified `CHECKOUT.ORDER.APPROVED` webhook processing
- Full and partial refunds by PayPal capture ID
- Signed webhook verification through PayPal `verify-webhook-signature`
- Idempotency through `PayPal-Request-Id`
- Local authorization revocation after Vault token deletion events
- Reconciliation for unknown provider outcomes

The browser overlay is provider-owned. Agovena does not render card fields and does not treat the browser callback as payment proof. Verified webhooks and provider reconciliation remain authoritative. API clients without an Agovena storefront access token retain the direct PayPal approval redirect.

Automatic recurring orders request Vault storage during the first checkout. Later renewals use the encrypted authorization stored by the extension and are scheduled by Agovena Core. No PayPal Billing Plan ID or `subscription_plan_id` is required.

Configure the payment webhook at `/webhooks/payments/paypal`.

External PayPal Sandbox or live approval, webhook delivery, Vault creation, refunds and renewals remain deployment verification steps. Local tests cover adapter contracts, overlay routing, idempotency, status mapping, token persistence and failure states, but they do not prove a live provider account.
