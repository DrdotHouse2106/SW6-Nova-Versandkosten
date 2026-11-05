# 1.5.2

- Fixed: `GET/POST /store-api/shipping-method` failed with “Invalid struct: Entity at index …” as soon as the plugin hid unsuitable shipping methods (the search result was only cleaned halfway). Affected apps and headless frontends, not the storefront

# 1.5.1

- Fixed: preselection kept a pickup (0.00) the customer had not chosen, “pickup at events” or a third-party default method – the cart started with pickup. Without the customer’s own choice the cheapest possible parcel option is now always preselected. Methods the customer chose (including pickup, add-on, tour) stay

# 1.5.0

- New: **letter parcel / small parcel by shipping property.** New setting “Values for letter parcel / small parcel” (product data tab, default: Warenpost, Kleinpaket, BüWa, Brief). If a product has values in the property but none of these, letter parcel and small parcel are not offered for the whole cart – not via “small shipment without dimensions” either. Products without the property are not affected; an empty field switches the rule off

# 1.4.1

- Fixed: the plugin shipping methods carried the description “Price calculated by Nova Versandkosten.” since installation – it showed in checkout and in the order confirmation. The update removes exactly this default text (custom descriptions stay), new installations no longer set a description

# 1.4.0

Security release (review of 3 Nov 2026). **After the update:** update the plugin (the migration runs), clear the cache, check the order card “Shipping costs” on an older order. Anyone who read the purchase price from the custom field `nova_versandkosten_purchase_net` now reads it via the admin route (see README).

- Security (high): **purchase prices are no longer visible to customers.** The cart extension `novaVersandkosten` – returned by the Store API (`GET /store-api/checkout/cart`) to every visitor – contains display data only (available, sale price, hints, delivery, parcel count, carrier name). Purchase net, parcels with purchase prices and tariff lines, tariff product, Cargoboard quotation ID and internal reasons stay in request memory. Tour data on the cart without variant and deviation in km. Reasons in blocking errors (also Store API) are generic
- Security (high): **purchase price no longer in the order's custom fields** (returned to the customer by `GET /store-api/order`). New table `nova_vk_order_cost` (purchase total and per parcel, tariff lines, Cargoboard quotation ID); the order card “Shipping costs” shows the purchase as before (via `GET /api/_action/nova-versandkosten/order/{id}`, privilege `order:read`). The migration moves the purchase data of all existing orders into the table and removes `nova_versandkosten_purchase_net`, `purchaseNet` per parcel and `freightQuotationId` from the custom fields; the custom field “Purchase net (estimated)” is dropped. Parcels, dimensions, weights, sale and “dimensions missing” stay in the custom fields for ERP and delivery notes. Margin e-mail unchanged
- Security (medium): **shipping category, packing and “free shipping” from the database** instead of the line item payload a customer can send via the Store API (product custom fields, properties, manufacturer, tags; variants inherit from the parent). Also for the items of old orders when adding to an order. All shipping-cost-aware positions with delivery information count (like Shopware's delivery builder), not only type “product”
- Security (medium): **“shipping costs on request” – the payment method is validated when ordering** (cart validator), not only in the checkout's payment list; ordering with PayPal/card via the Store API is no longer possible
- Security (medium): **fail closed without valid tariffs** – all parcel shipping methods of the plugin (including collection, request, add-on) are blocked and hidden instead of selectable at the placeholder price 0.00; the tour stays (own prices). Carts without shippable goods (e.g. vouchers only) as before. Overview and Tariffs tab mention the block; an invalid stored tariff file also triggers an admin notification (at most every 6 hours)
- Security (medium): **add to an order only if its shipping is secured** – paid, partially paid, authorised or in progress, or a pay-after-shipping method (invoice, direct debit, cash on delivery). New setting “Also add to unpaid prepayment/online orders” (default off). If the old order is cancelled after an add-on, the add-on orders get an internal comment and the administration a notification. No longer to tour or collection orders
- Security (medium): **auto-cancel of “shipping costs on request”** only for priced orders and counted from the price date (as announced in the customer e-mail), never for unpriced or already shipped orders
- Security (medium): **CSV export of tour subscribers** defuses every text column (name, customer number …) against formulas, also after spaces and for tab/line breaks
- Security (low): role “view tours” without a blanket `order:read` (it allowed reading all orders via the admin API). For “tour deliveries” also grant “View orders”; **existing roles keep their stored privileges until saved again** – please open and save roles with “view tours” once
- Security (low): links in e-mails (tour notification confirm/unsubscribe/keep, add-to-order link) open a page with a button; only the click (POST) changes something – mail scanners and link previews trigger nothing. Links in existing e-mails keep working
- Security (low): “send notification” reserves each subscriber before sending – a double click or two admins never mail anyone twice
- Security (low): energy fetch reads at most 3 MB per page, shorter time limits (12 s per page instead of 20 s)
- Security (low): add to order for logged-in customers only via the customer ID, no longer via an equal e-mail address (guest orders via link or order number + e-mail + postcode)
- Security (low): a route variant that a tour date is fixed to can no longer be deleted (the date would stay “fixed” without a variant)
- Errors during the update (migration, postcode import) are logged instead of swallowed; no source map in the package
- Deliberately not in this release: validation of tour entities on writes via the generic admin API (only roles with tour write privileges or integrations; read paths already discard invalid values), a per-e-mail limit for tour sign-ups (captcha + IP limit exist; README note on `TRUSTED_PROXIES`), plausibility of the sum DPD road + EFS, background queue for “fetch now”, `List-Unsubscribe` headers

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
