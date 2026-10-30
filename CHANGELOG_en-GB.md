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
