# PayPal payment extension

PayPal Checkout Orders integration for Agovena with:

- Redirect checkout via Orders v2
- Automatic capture after a verified `CHECKOUT.ORDER.APPROVED` webhook
- Webhook signature verification (`verify-webhook-signature`)
- Refunds against capture IDs
- Status sync via order lookup
- One PayPal checkout option; no configurable submethod selection
- No recurring charges or pending-payment cancellation
- Secrets stored in extension settings (encrypted when marked secret)

Configure webhook URL: `/webhooks/payments/paypal`
