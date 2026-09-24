# PayPal payment extension

PayPal Checkout Orders and provider-managed Billing Subscriptions for Agovena with:

- Redirect checkout via Orders v2 for one-time payments
- Automatic capture after a verified `CHECKOUT.ORDER.APPROVED` webhook
- Webhook signature verification through PayPal `verify-webhook-signature`
- Full and partial capture refunds
- Refunds for subscription sales through PayPal's sale refund endpoint
- Provider-managed subscriptions through an existing active PayPal Billing Plan
- Plan validation for amount, currency, interval, quantity, and trial configuration
- Subscription renewal settlement through `PAYMENT.SALE.COMPLETED` webhooks
- Subscription lifecycle events through `BILLING.SUBSCRIPTION.*` webhooks
- Period-end cancellation represented by PayPal suspension, with activate-based resume
- Idempotency through `PayPal-Request-Id` on provider-mutating requests
- One PayPal checkout option with the extension-owned `ag:payment-method/paypal` icon
- Unknown provider outcomes deferred to payment or refund reconciliation
- Secrets stored in extension settings (encrypted when marked secret)

For automatic subscriptions, configure an existing active `subscription_plan_id`. The PayPal plan price, currency, billing interval, quantity, and trial must match the Agovena subscribable product. A product capability may override the global plan with `paypal_plan_id` in its `subscribable` configuration.

Configure webhook URL: `/webhooks/payments/paypal`

External PayPal sandbox approval, webhook delivery, refund execution, renewals, and merchant approval remain deployment verification steps. Local tests cover the adapter contracts and fake-provider lifecycle, not live provider execution.
