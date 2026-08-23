# PostNL Shipping Extension

First-party Agovena Shipping Extension. Implements `ShippingCarrier`,
`QuotesShippingRates`, `CreatesCarrierShipments`, and `TracksShipments`.

It is **not** a Module. The Shipping Module stays generic.

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
5. Checkout continues to use configured Shipping methods for rates. Live quotes
   are available through the carrier contract when the Checkout API returns them;
   otherwise fulfillment uses the default product code (3085 Standard NL).

## Data boundaries

Provider barcodes, labels, and product codes stay in this Extension (plus the
generic Shipment `carrier_id` / `external_ref` / `tracking_*` / `label_path`
columns). Orders, products, and addresses do not get PostNL-specific columns.

Street and house number are parsed from the generic `line1` address field
inside this Extension.

## Idempotency

Creating a shipment for an order that already has a PostNL barcode returns the
existing mapping. Retries do not create a second provider shipment.

## Labels

Label PDFs are stored on the private local disk and are downloadable from Admin
fulfillment only. Customers see tracking, not the label file.
