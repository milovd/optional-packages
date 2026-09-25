# Tebex payment extension

Tebex Checkout for Agovena with:

- One checkout option: `tebex:tebex`
- The official Tebex icon-mark SVG in the Core payment-method catalog
- Provider-hosted checkout opened by redirect
- Custom order-priced items without local product-to-package mapping
- Signed `X-Signature` webhook verification
- Signed `validation.webhook` handshake handling
- Basket-to-payment reconciliation before a payment is finalized
- Full refunds using the verified Tebex `tbx-` transaction
- Provider-managed recurring lifecycle for one supported subscription item
- Idempotent local webhook event handling
- Reconciliation for unknown provider outcomes

Tebex owns the hosted payment surface, available payment methods and customer payment collection. Agovena does not render card fields or expose `tebex:card`, `tebex:paypal` or other provider submethods.

The adapter requires `project_id`, `secret_key` and `webhook_secret`. It uses Tebex Checkout API `/checkout`, payment and recurring-payment endpoints, and the webhook endpoint `/webhooks/payments/tebex`.

Recurring checkout is limited to one subscription item with quantity one, no trial and the supported interval mapping. Cancellation is period-end. Partial refunds are not supported by this integration.

Tebex account approval, Sandbox checkout, real webhooks, refunds and recurring lifecycle behavior remain deployment verification steps. Local tests and fake API responses do not prove a live Tebex account.
