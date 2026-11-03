# 1.3.1

- New: **preselect the cheapest possible shipping method** (Prices tab, per sales channel, default on): cart, off-canvas cart and checkout automatically select the cheapest possible parcel option of the plugin (letter parcel, small parcel, DPD, DHL, freight) instead of always the sales channel default. Equal price: the current one stays. A method the customer chose stays (remembered in the session; changing the country does not count as a choice); after the order is placed, preselection starts again
- Fixed: a method that is no longer possible (e.g. letter parcel after raising the quantity, parcel for freight goods, country not enabled) stayed in the cart as “blocked” with 0.00 when Shopware's own switch failed on an old shipping method. Now it switches at once to the cheapest possible plugin method – if there is none, to “Shipping costs on request” – with Shopware's notice “shipping method changed”. Third-party methods (pickup, events …) stay untouched as long as they are possible; add-on and tour are only switched when blocked

# 1.3.0

- New: **one module for everything** – *Orders → Shipping costs* (also a tile under *Settings → Shipping costs*) with tabs Overview · Prices · Surcharges & energy · Countries & delivery time · Product data · Freight · Request & add-on · Tours (tour dates, routes, price profiles, tour deliveries, notification, settings) · Tariffs · Price check. Replaces the menu entries *Extensions → Nova Versandkosten* and *Orders → Tours*; old bookmarks redirect
- New: **settings in the module** – per tab the matching cards of the plugin configuration with sales channel switch and inheritance (“All sales channels”, chain icon). Same values as the plugin configuration (`NovaVersandkosten.config`), no migration; the plugin configuration shows a link to the module at the top. Cards in `config.xml` now have names; “Surcharges” is split into “Peak surcharges” and “Energy surcharges”, “Request and control” into “Request delivery by tour” and “Control”, “DPD outside the EU” moved to countries – field names unchanged
- New: **overview** – tariffs imported?, energy surcharges up to date?, price check warnings, active Nova shipping methods per sales channel, scheduled tasks (status, last/next run), upcoming tour dates
- New: sub-tab **tour deliveries** (choose a date, loading order) directly in the tours
- New: **tariff upload** via choose file or drag & drop (.csv/.json), checked right away; CSV with semicolons in UTF-8 (with/without BOM) or Windows-1252 (Excel “CSV (comma delimited)”). Previously the file was always read as UTF-8 (umlauts from Excel CSV broken), there was no feedback after choosing and the same file could not be chosen again
- New: **templates** in the tariffs tab – example files CSV/JSON (placeholder prices), blank CSV template with one commented example row per row type (`#` = comment), collapsible column reference, prominent “Export current tariffs (CSV)” for editing in Excel
- New: **small shipment without dimensions** (per sales channel, default “fits Warenpost up to 1 kg”): if all items are category parcel without dimensions, Warenpost/small parcel only check weight, goods value and “one parcel” instead of the default dimensions 30 × 20 × 15 cm (which never fitted). Items with dimensions are still checked exactly
- New: **fixed price with weight limit** – per fixed price “applies up to X kg” (empty = always); heavier parcels cost purchase gross or purchase gross + surcharge (setting). The price check uses the same rule and shows “Purchase gross (above X kg)”
- Permissions unchanged (settings or role “Tours”); the menu entry appears with either read permission

# 1.2.1

- New: **Price profiles for tours** – the tour price can depend on who drives. Under *Orders → Tours → Price profiles* each profile has a name (internal only), a price for small parts/parcels/bulky goods with maximum deviation and freight tiers (e.g. `30=190`, `60=290`; empty = no freight goods). The profile is chosen on the tour date; checkout, the check when ordering, reach of the tour notification, tour deliveries and the hint on “shipping costs on request” use the date's profile. The profile name is never shown to customers
- The existing “Own tours” settings remain the **default profile** – without own profiles nothing changes
- New table `nova_vk_tour_price_profile` and column `price_profile_id` on the tour date (deleted profile → default profile); existing “Tours” roles get the price profile permissions; uninstalling without “keep data” also removes the new table

# 1.2.0

- New: **Own delivery tours** – routes (templates) with one or more variants and ordered waypoints (entered as postcode or place), tour dates with a route or own waypoints (one-off trip, optionally saved as template), date, order deadline (default 2 days before), “with trailer”, status open/fixed/closed. New administration *Orders → Tours* with role permissions
- New: shipping method **“Lieferung mit unserer Tour”** (`nova_versandkosten_tour`, created inactive) – only if an open/fixed date fits (deadline, deviation, trailer for freight goods, domestic). Deviation = straight-line distance of the delivery postcode to the variant's line × detour factor (default 1.3). Prices per sales channel (default small parts/parcels/bulky 50 € up to 10 km, freight goods with trailer only 190 € up to 30 km, 290 € up to 60 km), the highest category in the cart counts. Open date: all variants count, **the first booking fixes the variant**
- New: mandatory date choice at checkout (date and price only, no route), checked again when ordering, Store API routes; customer comment “TOUR: <weekday, date>”, custom fields (date, variant, deviation, category), list **“tour deliveries”** per date sorted along the route for loading
- New: hint on “shipping costs on request” orders when the delivery address is close to a route or bookable tour date
- New: **Tour notification** – switch per delivery address in the customer account (also Store API), form for guests as Shopping Experience element/block with double opt-in, Shopware captcha, honeypot and rate limiter; unsubscribe via signed link in every e-mail, deletion of unconfirmed sign-ups after 7 days and inactive subscribers after 24 months (configurable); administration with subscriber list, filter by route/radius, CSV export, preview “X subscribers along the route” and button “Send notification” (once per date and subscriber); mail templates de/en
- New: German postcode coordinates included (GeoNames, CC BY 4.0, see NOTICE) – no external service
- Uninstalling without “keep data” also removes the new tables

# 1.1.0

- New: **Add to an open order** – shipping method “Zu meiner Bestellung beipacken” (created inactive). Customers choose an own order that has not been shipped; guests use the link from the order confirmation (Shopware's order code) or order number + e-mail + postcode (max. 5 attempts per hour and IP). Price = shipping for old + new items minus shipping already paid, at least 0.00. Checked again when ordering, delivery address of the old order, comment “BEIPACK zu Bestellung <no>”, references on both orders, note in the old order's internal comment, link “Forgot something?” on the finish page
- New: **Shipping costs on request** – shipping method (created inactive) for freight without price, in addition to priced freight, countries without tariff, items “on request only” or always. Order with 0.00 shipping, clear note at checkout and on the finish page, only payment methods without immediate charge (configurable, default prepayment), e-mail to the shop, card on the order to enter the shipping costs (Shopware recalculation, taxes and open payment adjusted), mail template for the customer with total and payment link, automatic cancellation of unpaid requests after X days
- New: **Automatic energy surcharges** from DHL (long- + short-term component) and DPD (road + Emergency Fuel Surcharge) – daily and via “Fetch now”, plausibility check 0–60 %, last value kept on errors (notification, optional e-mail), automatic/manual per carrier, value, source and fetch time shown
- New: shipping category “On request only” (custom field and property values), Google label `anfrage`
- New: setting “Show ‘dimensions missing’ note” (default on, internal only); missing dimensions still cost nothing extra – the category applies
- Updates add missing custom fields and select options; existing settings stay
- Recalculating an order in the administration no longer overwrites the stored shipping data

# 1.0.0

- First version: calculate shipping costs from imported carrier purchase tariffs (JSON or CSV)
- Calculation core without Shopware dependency: parcel building, letter parcel/small parcel/DPD/DHL, bulky/oversize, toll, energy, peak and peak-in-peak, island, customs and currency surcharges, abroad (no VAT outside the EU), free shipping for manufacturers/tags
- Six shipping methods created by the plugin (inactive); unsuitable methods are hidden in the checkout
- Freight via the Cargoboard API with cache and fallback price; without a key “on request”
- Checkout: price, estimated delivery date (public holidays per German state), notes, recommendation, “Request delivery by tour” button
- Order: result as custom fields, “Shipping costs” card with purchase/sale/difference, optional e-mail on negative margin
- Administration: tariff import/export and price check per sales channel
- Product export: Twig function `nova_versand_label()` for the Google shipping label
