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
- **Surcharges**: toll, security fee, energy surcharge (% per carrier, **fetched daily from DHL and
  DPD** or entered manually), peak and
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
  `sperrgut`, `spedition`, `abholung` or `anfrage`.
- **Add to an open order** (new in 1.1.0): customers and guests add items to an order that has not
  been shipped yet and only pay the difference in shipping costs.
- **Shipping costs on request** (new in 1.1.0): order without a shipping price; the shop enters the
  shipping costs afterwards, the customer receives the total and a payment link by e-mail; unpaid
  requests are cancelled after X days.

Real purchase prices do not belong in the plugin: each shop imports its own tariffs. All prices in
this document and in `examples/` are **made-up placeholders**.

## Installation

**Via Nova Updater:** *Extensions → My extensions → Plugin catalogue* → “Nova Versandkosten” →
*Install*, then activate.

**Via ZIP:** upload `NovaVersandkosten-<version>.zip` from the
[releases](https://github.com/DrdotHouse2106/SW6-Nova-Versandkosten/releases) under *Extensions →
My extensions → Upload extension*, install and activate. Reload the administration (Ctrl+F5).

On installation the plugin creates eight **inactive** shipping methods (not assigned to any sales
channel): *Warenpost*, *DHL Kleinpaket*, *DPD Paket*, *DHL Paket*, *Spedition* (freight), *Abholung*
(collection), *Versandkosten auf Anfrage* (`nova_versandkosten_anfrage`) and *Zu meiner Bestellung
beipacken* (`nova_versandkosten_beipack`) – identified by the technical name `nova_versandkosten_…` –
plus custom fields on products and orders, the mail template “Nova Versandkosten: Versandkosten
nachberechnet” (German/English) and two daily scheduled tasks (`nova_versandkosten.energy_fetch`,
`nova_versandkosten.request_cancel`; they need a running scheduled task runner). Updating to 1.1.0
only adds what is missing. Name, description, image, position and rules can be changed freely; only the
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
5. **Check product data:** the **weight** (kg) is usually enough; packed length/width/height (mm) are
   only worth maintaining for borderline items. Category via the custom field “Versandeinordnung”
   (paket, sperrgut, spedition, abholung, anfrage) or the property (default “Versandart”). Missing
   dimensions cost nothing extra: the category applies (parcel = within normal limits). The order is
   still flagged “dimensions missing” (custom field); setting “Show ‘dimensions missing’ note”
   (default on) controls the internal note in the administration and internal e-mails – customers
   never see it.
6. **Price check:** *Extensions → Nova Versandkosten → Price check*.
7. **Test orders** in every channel; check the “Shipping costs” card on the order.
8. **Energy surcharges:** *Extensions → Nova Versandkosten → Energy surcharges → Fetch now*; then it
   runs daily. Before November enter the peak-in-peak period.
9. **Optional:** activate “shipping costs on request” and “add to my open order” (see below).

## Add to an open order

Shipping method `nova_versandkosten_beipack` (“Zu meiner Bestellung beipacken”). Only **own** orders:
customers choose from their orders at checkout; guests use the link from the order confirmation
(Shopware's secret `deepLinkCode`) or enter order number + e-mail + postcode (max. 5 attempts per hour
and IP). The order must be open: delivery state “open”, not cancelled/completed, not older than X days
(default 14), same sales channel and delivery country. Everything is checked again when the order is
placed.

**Price** = shipping for (items of the old order + earlier add-on orders + cart) with the same option
as the old order (if it no longer fits or is unknown: the cheapest matching one) **minus** shipping
already paid, at least 0.00. Examples (placeholders): old small parcel 7.50, together DPD 7.50 → 0.00;
old Warenpost 5.00, together small parcel 7.50 → 2.50.

The new order gets the delivery address of the old one, a customer comment starting with
“BEIPACK zu Bestellung &lt;no&gt;”, references in both orders' custom fields and a note in the old order's
internal comment.

Link for the order confirmation mail (*Settings → Email templates → Order confirmation*):

```twig
{% if order.deepLinkCode %}
<p>Forgot something?
    <a href="{{ rawUrl('frontend.nova_versandkosten.beipack.link', { deepLinkCode: order.deepLinkCode }, salesChannel.domains|first.url) }}">Add it to this order now</a>
</p>
{% endif %}
```

The same link appears automatically on the checkout finish page once the shipping method is active.

## Shipping costs on request

Shipping method `nova_versandkosten_anfrage`. Offered (settings per sales channel) when freight has no
price, in addition to priced freight (“Shipping costs seem too high? We are happy to check a cheaper
solution.”), for countries without tariff, for items “on request only”, or always (off by default).
The order is placed with shipping 0.00 and open payment; checkout and finish page say: “Shipping costs
will be calculated afterwards. Your order only becomes binding once you pay the total amount.”

- **Payment methods at checkout:** only methods that do not collect money when ordering (setting;
  empty = prepayment). Do not add PayPal/cards – they would charge immediately without shipping.
  Paying later works the Shopware 6.7 way: customers via *Account → Orders → Change payment method*
  (`/account/order/edit/<id>`), guests via `/account/order/<deepLinkCode>` (sign-in with e-mail +
  billing postcode). Offered there: all payment methods with “Allow payment change after checkout”.
- **Shop:** receives an e-mail “Versandkosten eintragen für Bestellung &lt;no&gt;” with a link to the
  administration. On the order (*Details*) the card “Shipping costs on request”: enter the shipping
  costs → *Apply shipping costs and notify customer*. The plugin creates a version of the order, sets
  the shipping costs as manual price, recalculates the order (taxes from the positions), merges the
  version and adjusts the open payment amount. The customer gets the mail with total and payment link.
- **Auto-cancel:** unpaid request orders are cancelled after X days (default 14, 0 = off).
- Snippet for the order confirmation mail:
  `{% if order.customFields.nova_versandkosten_anfrage|default(false) %}…{% endif %}`
- **Terms and conditions:** such an order is only a request until the total is paid. *Nova
  Rechtstexte* (1.8.4+) provides the block `[[wenn versandanfrage]]`. No legal advice.

## Automatic energy surcharges

Daily scheduled task + *Fetch now* button read the public tables of
[DHL](https://www.dhl.de/energiezuschlag) (“Zuschlagshöhe ab 1./11./21.” = long-term + short-term
component), [DPD](https://www.dpd.de/energiezuschlag) (“Energiezuschlag Straße”) and the DPD
[Emergency Fuel Surcharge](https://www.dpd.com/de/de/support/versandbestimmungen/emergency-fuel-surcharge/)
(added to road, can be switched off). Whole tables with “valid from” are stored, so the value valid on
the day applies. Plausibility check 0–60 %; on errors the last value stays, an administration
notification (optionally an e-mail) is created. Per carrier “automatic” or “manual”.

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
- Energy surcharges: only the public pages of DHL and DPD are requested, without customer or shop data.
- Add to order: only the chosen order ID is stored with the cart; for the attempt limit a hash of the
  IP address (max. one hour in the cache).

## Planned

Own price list with volumetric weight · express/time-definite delivery, parcel shop delivery,
worldwide tariffs with volumetric weight · variable international transport surcharge · contact form instead of e-mail for tour requests, shipping info page
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
