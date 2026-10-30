# Nova Versandkosten (shipping costs)

[![Buy me a coffee](https://img.shields.io/badge/☕_Buy_me_a_coffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

*[Deutsche Version](README.md)*

Shopware 6.7 plugin that calculates the shipping costs of each cart from **your own carrier purchase
tariffs** instead of rigid price tables. It builds parcels from weight and dimensions, picks the right
tier per carrier (letter parcel “Warenpost”, small parcel, parcel, bulky/oversize), adds all
surcharges and only offers the shipping methods that really work for the cart – with price,
estimated delivery date and notes.

## Features

- **Calculation core in plain PHP** (`src/Calculator`, no Shopware dependency): same input → same
  price; easy to port to other systems (e.g. an ERP).
- **Parcel building**: the largest item defines the parcel size, further items are added up to the
  maximum weight (default 25 kg), bulky goods as single parcel or “combinable”.
- **Tiers per carrier**: Warenpost (goods value limit), small parcel, DPD, DHL – with maximum
  dimensions, girth, bulky/oversize surcharges from the tariff file.
- **Surcharges**: toll, security fee, energy surcharge (% per carrier, maintained monthly), peak and
  peak-in-peak (optionally passed on, with a note in the checkout), island surcharges by postcode,
  customs clearance and currency surcharge per country.
- **Sale price per sales channel**: fixed prices in the home country (each further parcel costs
  another parcel price), bulky/oversize = purchase price gross on top, abroad = purchase price gross +
  surcharge, **outside the EU without VAT**, free-shipping rule for manufacturers or tags.
- **Freight** via the Cargoboard API (price + transit time, surcharge, cache, fallback price on
  errors); without an API key “on request”.
- **Checkout**: price, “Estimated delivery Tue, 04.11. – Wed, 05.11.” (processing time, cut-off,
  transit time, public holidays per German state, own closing days), notes, recommendation, button
  “Request delivery by tour”. Unsuitable shipping methods are hidden.
- **Order**: carrier, product, parcels (count, dimensions, weight), purchase, sale and “dimensions
  missing” as custom fields – the basis for ERP and labels. “Shipping costs” card on the order
  (difference red on negative margin), optional e-mail on negative margin.
- **Tariff import** as JSON or CSV (Excel) in the administration, stored in the database.
- **Price check**: all fixed prices per channel against the current purchase price.
- **Google/product export**: Twig function `nova_versand_label(product, context)` → `paket`,
  `sperrgut`, `spedition` or `abholung`.

Real purchase prices do not belong in the plugin: each shop imports its own tariffs. All prices in
this document and in `examples/` are **made-up placeholders**.

## Installation

**Via Nova Updater:** *Extensions → My extensions → Plugin catalogue* → “Nova Versandkosten” →
*Install*, then activate.

**Via ZIP:** upload `NovaVersandkosten-<version>.zip` from the
[releases](https://github.com/DrdotHouse2106/SW6-Nova-Versandkosten/releases) under *Extensions →
My extensions → Upload extension*, install and activate. Reload the administration (Ctrl+F5).

On installation the plugin creates six **inactive** shipping methods (not assigned to any sales
channel): *Warenpost*, *DHL Kleinpaket*, *DPD Paket*, *DHL Paket*, *Spedition* (freight), *Abholung*
(collection) – identified by the technical name `nova_versandkosten_…` – plus custom fields on
products and orders. Name, description, image, position and rules can be changed freely; only the
technical name must stay. An existing shipping method can be taken over by giving it one of these
technical names.

## Setup step by step

1. **Import tariffs:** *Extensions → Nova Versandkosten → Tariffs* → choose a JSON or CSV file (or
   paste it) → *Check* → *Import*. Template: [`examples/tarife-beispiel.json`](examples/tarife-beispiel.json)
   / [`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv).
2. **Settings per sales channel:** *Extensions → My extensions → Nova Versandkosten → Configure*.
3. **Activate shipping methods:** *Settings → Shipping*. The price “0.00” in the price matrix is only a
   placeholder; the plugin sets the real price. Keep tax type “Auto”.
4. **Assign them to the sales channel** and choose a default shipping method.
5. **Check product data:** weight (kg) and packed length/width/height (mm). Category via the custom
   field “Versandeinordnung” or the property (default “Versandart”). Missing dimensions → defaults per
   category and the order is flagged “dimensions missing”.
6. **Price check:** *Extensions → Nova Versandkosten → Price check*.
7. **Test orders** in every channel; check the “Shipping costs” card on the order.
8. **Monthly:** update energy surcharges; before November enter the peak-in-peak period.

## Calculation rule

1. **Build parcels:** freight item in the cart → freight/collection only; “collection only” item →
   collection only. Otherwise bulky single parcels per piece, “combinable” bulky pieces of the same
   item share parcels up to the maximum weight; other items (largest first) go into the largest parcel
   as long as it stays below the maximum weight, else a new parcel. The parcel grows per edge to the
   largest item inside. Pieces are indivisible.
2. **Matching tier per carrier:** Warenpost/small parcel only for a single parcel, no bulky goods,
   within the dimensions and goods value limit. Bulky/oversize tiers apply when one condition is met
   (the last matching tier replaces earlier ones) and the parcel fits their maximum. If no carrier
   fits, freight is offered.
3. **Purchase net per parcel** = base price + tier surcharge + fixed surcharges + percentage surcharges
   (of the base price) + energy + peak + peak-in-peak.
4. **Sale per parcel:** home country with fixed price: fixed price + (bulky/oversize + surcharges
   marked “passOn”) × VAT + peak × VAT (if passed on). Home country without fixed price: purchase ×
   VAT. EU abroad: purchase × VAT + surcharge abroad; outside the EU: purchase (no VAT) + surcharge.
   Free-shipping rule: 0.00.
5. **Rounding:** commercial rounding to cents after VAT (integer arithmetic internally).
6. **Freight:** Cargoboard price net × VAT (EU) + surcharge.
7. **Recommendation:** DHL outside the EU (customs), otherwise the cheapest option.

Examples with placeholder tariffs: see the German README and
[`tests/Fixtures/testfaelle-beispiel.json`](tests/Fixtures/testfaelle-beispiel.json).

## Tariff format

A tariff file contains **purchase prices net in euro** per shipping product, as JSON or CSV. The
import detects the format and reports all errors. Field reference and CSV columns: see the German
README (section “Tarifformat”); the field names are English. Convert without Shopware:
`php scripts/tarif-konvertieren.php in.json out.csv`.

- `option`: `warenpost`, `kleinpaket`, `dpd` or `dhl` (the shipping method in the shop)
- `zones[]`: `countries` + either `steps` (`maxKg` → `price`) or `perPiece` + `perKg` (+ `roundKg`) / `per100g`
- `oversize[]`: `if` conditions (`lengthOver`, `lengthAtLeast`, `sideOver`, `volumeOverL`, `girthOver`,
  `exceedsDimsCm`, `weightOverKg`, `bulky`) and `max` (`lengthCm`, `girthCm`, `dimsCm`, `kg`)
- `surcharges[]`: `fixed`, `percent`, `energy`, `peak`, `peakInPeak`; optional `countries`, `postcodes`, `passOn`

## Privacy

- The calculation runs in the shop; tariffs and settings are stored in the shop database.
- Only for **freight goods** and only with an API key the plugin asks Cargoboard. Transmitted:
  postcode and country of sender and recipient, weights, dimensions, load carrier, private customer
  yes/no, outside the EU the goods value (customs). **No name, no street, no e-mail.**
- “Request delivery by tour” only opens the customer's e-mail program.

## Planned

Own price list with volumetric weight · express/time-definite delivery, parcel shop delivery,
worldwide tariffs with volumetric weight · variable international transport surcharge, automatic
energy surcharge retrieval · contact form instead of e-mail for tour requests, shipping info page
element · delivery times to Google Merchant Center · default shipping method per country ·
Cargoboard booking and labels (belongs to the ERP).

## Development

```bash
php phpunit.phar                     # unit tests of the calculation core (no Shopware)
php scripts/testfaelle.php           # example test cases (expected/actual table)
NOVA_VK_TARIFE=/path/tariffs.json NOVA_VK_FAELLE=/path/cases.json php scripts/testfaelle.php
```

The administration is checked in pre-built under `src/Resources/public/administration`; rebuild it
after changes as described in the German README.

## License

MIT – see [LICENSE](LICENSE).
