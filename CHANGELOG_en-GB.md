# 1.0.0

- First version: calculate shipping costs from imported carrier purchase tariffs (JSON or CSV)
- Calculation core without Shopware dependency: parcel building, letter parcel/small parcel/DPD/DHL, bulky/oversize, toll, energy, peak and peak-in-peak, island, customs and currency surcharges, abroad (no VAT outside the EU), free shipping for manufacturers/tags
- Six shipping methods created by the plugin (inactive); unsuitable methods are hidden in the checkout
- Freight via the Cargoboard API with cache and fallback price; without a key “on request”
- Checkout: price, estimated delivery date (public holidays per German state), notes, recommendation, “Request delivery by tour” button
- Order: result as custom fields, “Shipping costs” card with purchase/sale/difference, optional e-mail on negative margin
- Administration: tariff import/export and price check per sales channel
- Product export: Twig function `nova_versand_label()` for the Google shipping label
