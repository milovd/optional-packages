# PostNL Shipping Extension

First-party Agovena Shipping Extension. Implements `ShippingCarrier`,
`QuotesShippingRates`, `CreatesCarrierShipments`, and `TracksShipments`.

It is **not** a Module. Core Physical Commerce stays provider-agnostic.

## Why PostNL

PostNL is the practical first carrier for Agovena’s current Dutch/EU target
market. The Labelling, barcode, and shipping-status REST APIs are publicly
documented and can be exercised with mocked HTTP in CI. No paid proprietary
SDK is required.

Live merchant use still needs a PostNL API contract (API key, customer code,
and customer number). Automated tests never call the live API.

## Merchant setup

1. Admin → Extensions → enable **PostNL**
2. Save API key (encrypted; never redisplayed), customer code, and customer number
3. Optional collection location and sandbox toggle
4. Optional: `AGOVENA_EXT_POSTNL_API_KEY`
5. Checkout continues to use configured Shipping methods for rates. The PostNL
   Checkout API returns delivery options without tariffs, so Agovena never turns
   an unpriced option into a free shipping quote. Fulfillment uses the selected
   or default product code (3085 Standard NL).

## Provider endpoints

All calls use the documented PostNL REST API with the `apikey` header against
`https://api-sandbox.postnl.nl` (sandbox) or `https://api.postnl.nl` (live).
Redirects are never followed.

| Operation | Endpoint |
|-----------|----------|
| Health check and barcode | `GET /shipment/v1_1/barcode` |
| Shipment with label, pre-announced | `POST /shipment/v2_2/label?confirm=true` |
| Tracking | `GET /shipment/v2/status/barcode/{barcode}` |
| Delivery options | `POST /shipment/v1/checkout` |

Tracking reads `CurrentStatus.Shipment.Status.PhaseCode`: phases 2 and 3 map
to shipped, phase 4 to delivered, and every other phase keeps the shipment in
processing. Cancelling a pre-announced parcel is not supported by the API and
fails with a clear error.

Domestic parcels and EU destinations without customs data are supported.
Shipments that need customs declarations are rejected by PostNL and surface as
a safe validation error.

## Data boundaries

Provider barcodes, labels, and product codes stay in this Extension (plus the
generic Shipment `carrier_id` / `external_ref` / `tracking_*` / `label_path`
columns). Orders, products, and addresses do not get PostNL-specific columns.

Street and house number are parsed from the generic `line1` address field
inside this Extension.

## Idempotency

PostNL documents no idempotency header, so the Extension enforces one parcel
per order locally:

- shipment creation for an order holds a short cache lock; a concurrent attempt
  fails before any provider call;
- the barcode is stored immediately after PostNL confirms the shipment and
  before the label is decoded, so a label failure never leads to a second
  pre-announced parcel on retry;
- creating a shipment for an order that already has a PostNL barcode returns
  the existing mapping;
- without the `postnl_shipments` table the Extension refuses to call PostNL.

A shipment whose label could not be stored keeps status `label_failed` and no
label file. Reprint the label from Mijn PostNL for that barcode.

## Labels

Label PDFs are stored on the private local disk and are downloadable from Admin
fulfillment only. Customers see tracking, not the label file.
