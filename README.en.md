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
  “Request delivery by tour”. Unsuitable shipping methods are hidden; the **cheapest possible method is
  preselected** and one that is no longer possible is switched at once (from 1.3.1).
- **Order**: carrier, product, parcels (count, dimensions, weight), purchase, sale and “dimensions
  missing” as custom fields – the basis for ERP and labels. “Shipping costs” card on the order
  (difference red on negative margin), optional e-mail on negative margin.
- **Administration** (new in 1.3.0): one module *Orders → Shipping costs* with tabs for overview,
  settings, tours, tariffs and price check (see “Administration”).
- **Tariff import** as JSON or CSV (Excel) via file upload, drag & drop or paste, stored in the
  database; templates and export for editing in Excel.
- **Price check**: all fixed prices per channel against the current purchase price.
- **Google/product export**: Twig function `nova_versand_label(product, context)` → `paket`,
  `sperrgut`, `spedition`, `abholung` or `anfrage`.
- **Add to an open order** (new in 1.1.0): customers and guests add items to an order that has not
  been shipped yet and only pay the difference in shipping costs.
- **Shipping costs on request** (new in 1.1.0): order without a shipping price; the shop enters the
  shipping costs afterwards, the customer receives the total and a payment link by e-mail; unpaid
  requests are cancelled after X days.
- **Own delivery tours** (new in 1.2.0): routes with variants and waypoints, tour dates (with/without
  trailer, order deadline), shipping method “Lieferung mit unserer Tour” only for addresses close to
  the route, price by category and deviation, date choice at checkout, list “tour deliveries” for
  loading, hint on “shipping costs on request” orders within reach of a route.
  **Price profiles** (new in 1.2.1): tour price depending on who drives – chosen per tour date.
- **Tour notification** (new in 1.2.0): customers (switch per delivery address in the account) and
  guests (form with double opt-in and captcha) are informed at the push of a button when a tour date
  comes near them – without revealing the route.
- **German postcode coordinates included** (GeoNames, CC BY 4.0) – no external service.

Real purchase prices do not belong in the plugin: each shop imports its own tariffs. All prices in
this document and in `examples/` are **made-up placeholders**.

## Installation

**Via Nova Updater:** *Extensions → My extensions → Plugin catalogue* → “Nova Versandkosten” →
*Install*, then activate.

**Via ZIP:** upload `NovaVersandkosten-<version>.zip` from the
[releases](https://github.com/DrdotHouse2106/SW6-Nova-Versandkosten/releases) under *Extensions →
My extensions → Upload extension*, install and activate. Reload the administration (Ctrl+F5).

On installation the plugin creates nine **inactive** shipping methods (not assigned to any sales
channel): *Warenpost*, *DHL Kleinpaket*, *DPD Paket*, *DHL Paket*, *Spedition* (freight), *Abholung*
(collection), *Versandkosten auf Anfrage* (`nova_versandkosten_anfrage`), *Zu meiner Bestellung
beipacken* (`nova_versandkosten_beipack`) and *Lieferung mit unserer Tour* (`nova_versandkosten_tour`) –
identified by the technical name `nova_versandkosten_…` – plus custom fields on products and orders,
the tables for routes, tour dates, subscribers and postcode coordinates (loaded from the included file
on install/update), the mail templates “Versandkosten nachberechnet”, “Tour-Benachrichtigung
bestätigen” and “Tour in Ihrer Nähe” (German/English) and three daily scheduled tasks
(`nova_versandkosten.energy_fetch`, `nova_versandkosten.request_cancel`,
`nova_versandkosten.tour_cleanup`; they need a running scheduled task runner). Updates only add what is
missing. Uninstalling without “keep data” removes the tour tables, custom fields, mail templates and
settings; shipping methods are only deactivated. Name, description, image, position and rules can be changed freely; only the
technical name must stay. An existing shipping method can be taken over by giving it one of these
technical names.

## Administration

Everything in one place: **Orders → Shipping costs** (also as a tile under *Settings → Shipping
costs*). Tab and sub-tab are part of the address, so bookmarks work
(`#/nova/versandkosten/index/<tab>`).

| Tab | Content |
|---|---|
| Overview | Status at a glance: tariffs imported?, energy surcharges up to date?, price check warnings, active `nova_versandkosten_…` shipping methods per sales channel, scheduled tasks (status, last/next run), upcoming tour dates – with links to the right tab |
| Prices | fixed prices (each with optional “applies up to X kg”), surcharge abroad, pass on bulky, goods value limits, maximum weight, VAT, home country, preselect the cheapest possible shipping method |
| Surcharges & energy | current energy surcharges (*Fetch now*), peak/peak-in-peak, energy surcharge automatic/manual |
| Countries & delivery time | allowed countries, free shipping, DPD outside the EU, delivery date |
| Product data | property and value names of the category, default dimensions/weight, small shipment without dimensions |
| Freight | Cargoboard |
| Request & add-on | shipping costs on request, add to an open order, “request delivery by tour” button |
| Tours | sub-tabs tour dates, routes, price profiles, tour deliveries, notification, settings |
| Tariffs | upload a file (drag & drop or choose) or paste, check/import, export current tariffs as CSV/JSON, templates and column reference |
| Price check | fixed prices per channel against purchase, “control” settings (margin, missing dimensions) |

**Settings tabs:** choose the sales channel at the top – “All sales channels” holds the defaults; per
channel a field inherits the default while the chain icon is closed. These are **the same values** as
in the plugin configuration (*Extensions → My extensions → Nova Versandkosten → Configure*, which now
shows a link to the module at the top), stored in `system_config` under `NovaVersandkosten.config.*`.
*Save* stores the changes of all tabs and sales channels.

**Permissions** unchanged: settings, tariffs, energy surcharges and price check need the “Settings”
permission, tours the role *Orders → Tours*. The menu entry appears with either read permission; tabs
without permission are hidden.

**Old paths** (up to 1.2.1): *Extensions → Nova Versandkosten* and *Orders → Tours* are no longer menu
entries; old bookmarks redirect into the module.

## Setup step by step

1. **Import tariffs:** *Orders → Shipping costs → Tariffs* → drop a CSV or JSON file or *Choose file*
   (checked right away; or paste the content) → *Import*. Template: [`examples/tarife-beispiel.json`](examples/tarife-beispiel.json)
   / [`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv).
2. **Settings per sales channel:** *Orders → Shipping costs*, tabs *Prices*, *Surcharges & energy*,
   *Countries & delivery time*, *Product data*, *Freight*, *Request & add-on* (choose the sales channel
   at the top, then *Save*). The same fields remain in the plugin configuration (*Extensions → My
   extensions → Nova Versandkosten → Configure*).
3. **Activate shipping methods:** *Settings → Shipping*. The price “0.00” in the price matrix is only a
   placeholder; the plugin sets the real price. Keep tax type “Auto”.
4. **Assign them to the sales channel** and choose a default shipping method.
5. **Check product data:** the **weight** (kg) is usually enough; packed length/width/height (mm) are
   only worth maintaining for borderline items. Category via the custom field “Versandeinordnung”
   (paket, sperrgut, spedition, abholung, anfrage) or the property (default “Versandart”). Missing
   dimensions cost nothing extra: the category applies (parcel = within normal limits). The order is
   still flagged “dimensions missing” (custom field); setting “Show ‘dimensions missing’ note”
   (default on) controls the internal note in the administration and internal e-mails – customers
   never see it. **Small shipment without dimensions** (new in 1.3.0, tab *Product data*): the default
   parcel dimensions (30 × 20 × 15 cm) never fit Warenpost (≤ 5 cm) or small parcel (≤ 8 cm). With
   “Small shipment without dimensions” = *fits Warenpost* (default) or *fits small parcel* up to a
   total weight (default 1 kg), a cart whose items are **all** category “parcel” **without**
   dimensions only checks weight, goods value limit and “one parcel” for these options. As soon as an
   item has dimensions (or is bulky), everything is checked exactly as before.
6. **Price check:** *Orders → Shipping costs → Price check*.
7. **Test orders** in every channel; check the “Shipping costs” card on the order.
8. **Energy surcharges:** *Orders → Shipping costs → Surcharges & energy → Fetch now*; then it
   runs daily. Before November enter the peak-in-peak period.
9. **Optional:** activate “shipping costs on request” and “add to my open order” (see below).
10. **Optional: own tours** – routes and dates under *Orders → Shipping costs → Tours*, activate the shipping method
    “Lieferung mit unserer Tour” (see below).

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

## Own delivery tours

Shops that deliver themselves (own van, with or without trailer) offer customers **along the route**
the shipping method **“Lieferung mit unserer Tour”** (`nova_versandkosten_tour`, created inactive).
Customers never see the route – only the date and the price.

| Term | Meaning |
|---|---|
| **Route** | template for regular trips: name, start, destination and one or more **variants** |
| **Variant** | one way to the destination with ordered **waypoints** (entered as postcode or place → coordinate). Example: route “Sampletown – Exampleville”, variant A “via Northam”, variant B “via Southby” |
| **Tour date** | date (typically Fri/Sat/Sun, any day), route **or** own waypoints (one-off trip), order deadline (default 2 days before, last order day inclusive), switch **“with trailer”**, status |
| **Status** | **open** = no booking yet, all variants count · **fixed** = variant fixed, only customers along it · **closed** = no more bookings |
| **Deviation** | straight-line distance from the postcode centre of the delivery address to the variant's line (polyline of the waypoints) × **detour factor** (default 1.3), in km |

**The first booking fixes the variant** – the one with the smallest deviation for that customer. The
administration can fix or change the variant at any time.

**Prices** (per sales channel, settings “Own tours”): small parts, parcels, bulky goods **50 €** up to
**10 km**; freight goods (only for dates **with trailer**) **190 €** up to 30 km, **290 €** up to 60 km;
beyond: no tour. The **highest category** in the cart sets the price; items “collection only” or “on
request only” exclude the tour. Domestic addresses only (countries configurable, default DE). No
tariff file needed.

**Price profiles (who drives):** the tour price can depend on who drives. *Orders → Shipping costs → Tours →
Price profiles*: name (internal only, e.g. “Profile A” / “Profile B” – **never shown to customers**), price
for small parts/parcels/bulky goods up to a maximum deviation, freight tiers (“up to km = price” per
line, e.g. `30=190` and `60=290`; empty = no freight goods with this profile) and order. Example
(placeholders): profile A freight `60=190`, profile B `30=190` and `60=290` – freight 45 km beside the
route costs 190 € with A and 290 € with B. Choose the profile **on the tour date**; without a choice
the **default profile** = the “Own tours” settings (per sales channel) applies, so nothing changes
compared to 1.2.0 without own profiles. The date's profile is used everywhere: checkout price and
availability, check when ordering, reach of the tour notification, “out of range” marking in the tour
deliveries and the hint on “shipping costs on request” (routes without a date: largest reach of all
profiles). Detour factor and delivery countries stay as in the settings; profiles apply to all sales
channels. Deleting a profile makes its dates fall back to the default profile; existing orders keep
their price.

**Setup:** activate and assign the shipping method · settings *Orders → Shipping costs → Tours →
Settings* · *Tours → Routes → New route* (waypoints via “postcode or place” search, ↑/↓ to reorder) · *Tours → Tour dates → New tour date*
(route or own waypoints, date, deadline, trailer, optional price profile; one-off trips can be saved as
route template) · role permissions under *Orders → Tours* (price profiles: view/edit/delete with the
same roles; existing roles are extended on the update to 1.2.1).

**Checkout:** shown only if at least one open/fixed date fits (deadline not passed, deviation within
limits, trailer for freight, country allowed, postcode known). The customer must choose the date
(“Sat, 14/11/2026 – 50.00 €”); no route, no places. Everything is checked again when ordering.
Store API: `GET /store-api/nova-versandkosten/tour`, `POST /store-api/nova-versandkosten/tour/select`
(`{"tourDateId": "…"}`).

**Order:** customer comment starts with **“TOUR: Sa, 14.11.2026”**; custom fields
`nova_versandkosten_tour_termin`, `_datum`, `_variante`, `_variante_name`, `_abweichung_km`,
`_einordnung`. The tour date shows **“tour deliveries”** (order, customer, street, postcode/place,
position along the route, deviation, category, states) sorted along the route for loading. Orders
“shipping costs on request” show whether the address is close to an active route or bookable tour
date.

## Tour notification

Customers and prospects are told when a tour date comes near them – the e-mail only names the **date
and “near you (postcode area 12…)”**, never the route. Switch on in the settings “Tour notification”.

- **Customer account:** *My account → Tour notification* – switch per delivery address (signed in,
  so no double opt-in; sent to the account's current e-mail). Store API:
  `GET/POST /store-api/nova-versandkosten/tour-notification`.
- **Guests:** Shopping Experience element/block **“Tour notification”** or own include
  `{% sw_include '@Storefront/storefront/nova-versandkosten/tour-notify-form.html.twig' with { formId: 'product' } %}`:
  e-mail + postcode + consent checkbox, **double opt-in** (link valid 7 days), Shopware captchas (same
  route attribute as the contact form, so e.g. Nova Captcha applies), honeypot, origin check, rate
  limiter (3 per hour and IP, 100 per day and channel).
- **Duration:** active until unsubscribed (signed link in every e-mail). Unconfirmed sign-ups are
  deleted after 7 days, active subscribers after **24 months without activity** (configurable, 0 =
  never). Scheduled task `nova_versandkosten.tour_cleanup` (daily).
- **Administration:** *Orders → Shipping costs → Tours → Notification*: all subscribers, filter by route and radius,
  search, **CSV export**, delete. On a tour date: **“X subscribers along the route”** (all variants
  while open) and the button **“Send notification”** – never automatic, at most one e-mail per date and
  subscriber. Mail templates de/en are created on installation.
- **Privacy policy:** *Nova Rechtstexte* (1.8.5+) provides the block `[[wenn tourbenachrichtigung]]`,
  detected automatically when the plugin is active and the notification is switched on.

## Postcode coordinates (source and licence)

The plugin ships `src/Resources/geo/postcodes-de.tsv.gz` (German postcodes with place, state and
coordinate). Source: **GeoNames** postal codes (`DE.zip`,
[download.geonames.org/export/zip](https://download.geonames.org/export/zip/), as of 30/10/2026),
licence **Creative Commons Attribution 4.0** ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)).
Modifications: business entries removed as place names, coordinates rounded to 4 decimals
(`scripts/plz-aufbereiten.php`). Attribution also in [NOTICE](NOTICE).

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
   Free-shipping rule: 0.00. **Fixed price with weight limit** (new in 1.3.0, per fixed price
   “applies up to X kg”, empty = always): a heavier parcel costs purchase × VAT instead of the fixed
   price – or purchase × VAT + surcharge per parcel, depending on the setting “Above the weight limit
   of a fixed price”. The price check uses the same rule.
5. **Rounding:** commercial rounding to cents after VAT (integer arithmetic internally).
6. **Freight:** Cargoboard price net × VAT (EU) + surcharge.
7. **Recommendation:** DHL outside the EU (customs), otherwise the cheapest option.

Examples with placeholder tariffs: see the German README and
[`tests/Fixtures/testfaelle-beispiel.json`](tests/Fixtures/testfaelle-beispiel.json).

## Tariff format

A tariff file contains **purchase prices net in euro** per shipping product, as JSON or CSV. The
import detects the format and reports all errors. In the *Tariffs* tab you can drop/choose a file
(Excel CSV with semicolons, UTF-8 with or without BOM or Windows-1252), download the example files or
a blank template (header + one commented example row per row type; rows starting with `#` are
comments), open a column reference and export the current tariffs as CSV to edit them in Excel and
import them again. Field reference and CSV columns: see the German
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
- Own tours: the deviation is calculated in the shop from the included postcode file – **no external
  service**. Customers never see routes or dates other than their own choice.
- Tour notification: e-mail, postcode (with place and centre), for customers the link to account and
  address, source, timestamps (sign-up, confirmation, last activity), language, sales channel and
  which date e-mails were sent. The confirmation token is stored as a hash only. Legal basis: consent
  (Art. 6(1)(a) GDPR), withdrawal at any time via the unsubscribe link or in the account.

## Planned

Own price list with volumetric weight · express/time-definite delivery, parcel shop delivery,
worldwide tariffs with volumetric weight · variable international transport surcharge · contact form instead of e-mail for tour requests, shipping info page
element · delivery times to Google Merchant Center · default shipping method per country ·
Cargoboard booking and labels (belongs to the ERP) · own tours: **real detour via a routing service**
(e.g. OpenRouteService, only coordinates would be sent) instead of straight line × detour factor,
recurring dates, time windows per stop, route map, tour plan PDF, postcodes of other countries.

## Development

```bash
php phpunit.phar                     # unit tests of the calculation core (no Shopware)
php scripts/testfaelle.php           # example test cases (expected/actual table)
NOVA_VK_TARIFE=/path/tariffs.json NOVA_VK_FAELLE=/path/cases.json php scripts/testfaelle.php
```

The administration is checked in pre-built under `src/Resources/public/administration`; rebuild it
after changes as described in the German README (the build needs `examples/` next to `src/`, the
tariff templates are bundled from there).

## License

MIT – see [LICENSE](LICENSE). The included postcode coordinates come from GeoNames (CC BY 4.0) –
see [NOTICE](NOTICE).
