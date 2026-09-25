# PayPal payment extension

PayPal Checkout voor Agovena met:

- Redirect checkout via Orders v2 voor eenmalige payments
- Orders v2 vaulting bij automatische recurring checkout
- Merchant-initiated recurring charges met een versleuteld opgeslagen PayPal vault ID
- Automatische capture na een geverifieerde `CHECKOUT.ORDER.APPROVED` webhook
- Webhook signature verification via PayPal `verify-webhook-signature`
- Full en partial capture refunds
- Idempotency via `PayPal-Request-Id` op muterende provider requests
- Eén PayPal checkoutoptie met de extension-owned `ag:payment-method/paypal` icon
- Revocation van lokale recurring authorization bij vault-token deletion events
- Onbekende provideruitkomsten naar payment- of refund-reconciliation
- Secrets in extension settings, encrypted wanneer als secret gemarkeerd

Automatische recurring orders slaan de PayPal funding source op tijdens de eerste checkout met `store_in_vault = ON_SUCCESS`. Renewals gebruiken de opgeslagen vault authorization met `stored_credential` en worden door Agovena Core gepland en uitgevoerd. Er is geen vooraf aangemaakte PayPal Billing Plan nodig.

Configureer webhook URL: `/webhooks/payments/paypal`

Externe PayPal Sandbox- of live approval, webhook delivery, vaulting, refunds en renewals blijven deployment-verificatiestappen. Lokale tests controleren de adaptercontracten, idempotency, statusmapping, token persistence en failure states, maar vormen geen bewijs van een live provideraccount.
