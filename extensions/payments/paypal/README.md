# PayPal payment extension

PayPal Checkout Orders integration for Agovena with:

- Redirect checkout via Orders v2
- Webhook signature verification (`verify-webhook-signature`)
- Refunds against capture IDs
- Status sync via order lookup
- Secrets stored in extension settings (encrypted when marked secret)

Configure webhook URL: `/webhooks/payments/paypal`
