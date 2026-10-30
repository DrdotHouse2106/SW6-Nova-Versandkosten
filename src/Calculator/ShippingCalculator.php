<?php declare(strict_types=1);

namespace Nova\Versandkosten\Calculator;

use Nova\Versandkosten\Calculator\Tariff\TariffProduct;
use Nova\Versandkosten\Calculator\Tariff\TariffSet;

/**
 * Rechenkern: Warenkorb → mögliche Versandoptionen mit Paketen, Einkauf netto und Verkaufspreis.
 *
 * Reines PHP ohne Shopware-Abhängigkeit (portierbar, z. B. nach Python/ERPNext). Die Regeln stehen
 * im README unter „Rechenregel“; Abschnittsnummern in den Kommentaren beziehen sich darauf.
 */
final class ShippingCalculator
{
    /**
     * @param list<Item> $items
     */
    public function calculate(
        array $items,
        Destination $destination,
        ChannelRules $rules,
        TariffSet $tariffs,
        \DateTimeImmutable $now,
        ?FreightQuoteProvider $freight = null,
        ?DeliveryEstimator $estimator = null,
    ): ShippingResult {
        $packaging = PackageBuilder::build($items, $rules);
        $country = $destination->country;
        $isEu = Countries::isEu($country);
        $domestic = $country === strtoupper($rules->homeCountry);

        $goodsValue = 0.0;
        $allFree = $items !== [];
        $availableFrom = null;
        foreach ($items as $item) {
            $goodsValue += $item->unitPriceGross * $item->quantity;
            $allFree = $allFree && $item->free;
            if ($item->availableFrom !== null && ($availableFrom === null || $item->availableFrom > $availableFrom)) {
                $availableFrom = $item->availableFrom;
            }
        }
        $free = $allFree && \in_array($country, $rules->freeCountries, true);

        $options = [];
        $countryAllowed = $rules->isCountryAllowed($country);

        foreach ([OptionResult::WARENPOST, OptionResult::KLEINPAKET, OptionResult::DPD, OptionResult::DHL] as $key) {
            if ($packaging->mode !== PackageBuilder::MODE_PARCEL) {
                $options[$key] = OptionResult::unavailable($key, $packaging->mode === PackageBuilder::MODE_FREIGHT
                    ? 'Warenkorb enthält Speditionsware' : 'Warenkorb enthält einen Artikel „nur Abholung“');
                continue;
            }
            if (!$countryAllowed) {
                $options[$key] = OptionResult::unavailable($key, 'Land nicht freigeschaltet');
                continue;
            }
            $options[$key] = $this->parcelOption($key, $packaging, $destination, $rules, $tariffs, $now, $goodsValue, $domestic, $isEu, $free);
        }

        // Spedition: Speditionsware im Warenkorb oder kein Paketdienst passt (Abschnitt 2/3)
        $anyParcel = false;
        foreach ($options as $option) {
            $anyParcel = $anyParcel || $option->available;
        }
        $options[OptionResult::FREIGHT] = $this->freightOption($packaging, $destination, $rules, $freight, $goodsValue, $isEu, $anyParcel, $countryAllowed);

        $pickup = new OptionResult(OptionResult::PICKUP);
        $pickup->available = $rules->pickupEnabled;
        $pickup->reason = $rules->pickupEnabled ? '' : 'Abholung im Kanal deaktiviert';
        $pickup->carrierName = '';
        $pickup->vatIncluded = true;
        $options[OptionResult::PICKUP] = $pickup;

        $this->markRecommended($options, $isEu);

        if ($estimator !== null) {
            foreach ($options as $option) {
                if (!$option->available || $option->key === OptionResult::PICKUP) {
                    continue;
                }
                if ($option->transitMin === null) {
                    $transit = $rules->transitDays($option->key, $country);
                    if ($transit === null) {
                        continue;
                    }
                    [$option->transitMin, $option->transitMax] = $transit;
                }
                [$option->deliveryFrom, $option->deliveryTo] = $estimator->estimate($now, $option->transitMin, (int) $option->transitMax, $availableFrom);
            }
        } else {
            foreach ($options as $option) {
                if ($option->available && $option->transitMin === null && $option->key !== OptionResult::PICKUP) {
                    $transit = $rules->transitDays($option->key, $country);
                    if ($transit !== null) {
                        [$option->transitMin, $option->transitMax] = $transit;
                    }
                }
            }
        }

        return new ShippingResult($packaging->mode, $options, $packaging, $goodsValue, $free);
    }

    private function parcelOption(
        string $key,
        Packaging $packaging,
        Destination $destination,
        ChannelRules $rules,
        TariffSet $tariffs,
        \DateTimeImmutable $now,
        float $goodsValue,
        bool $domestic,
        bool $isEu,
        bool $free,
    ): OptionResult {
        $country = $destination->country;
        $products = $tariffs->productsFor($key, $country);
        if ($products === []) {
            return OptionResult::unavailable($key, 'kein Tarif für ' . $country);
        }
        if ($key === OptionResult::DPD && !$isEu && !$rules->offerDpdOutsideEu) {
            return OptionResult::unavailable($key, 'DPD außerhalb der EU deaktiviert');
        }
        if ($packaging->packages === []) {
            return OptionResult::unavailable($key, 'keine Pakete');
        }

        $best = null;
        $reasons = [];
        foreach ($products as $product) {
            $result = $this->priceProduct($key, $product, $packaging, $destination, $rules, $tariffs, $now, $goodsValue, $domestic, $isEu, $free);
            if (!$result->available) {
                $reasons[] = $product->name . ': ' . $result->reason;
                continue;
            }
            if ($best === null || $result->purchaseNet < $best->purchaseNet) {
                $best = $result;
            }
        }

        return $best ?? OptionResult::unavailable($key, implode('; ', $reasons));
    }

    private function priceProduct(
        string $key,
        TariffProduct $product,
        Packaging $packaging,
        Destination $destination,
        ChannelRules $rules,
        TariffSet $tariffs,
        \DateTimeImmutable $now,
        float $goodsValue,
        bool $domestic,
        bool $isEu,
        bool $free,
    ): OptionResult {
        $country = $destination->country;
        $zone = $product->zoneFor($country);
        if ($zone === null) {
            return OptionResult::unavailable($key, 'kein Tarif für ' . $country);
        }

        $limit = $rules->goodsValueLimit($key) ?? $product->maxGoodsValue;
        if ($limit !== null && $goodsValue > $limit + 1e-9) {
            return OptionResult::unavailable($key, \sprintf('Warenwert über %s €', Money::formatCents(Money::eurosToCents($limit))));
        }
        if ($product->singlePackage && \count($packaging->packages) > 1) {
            return OptionResult::unavailable($key, 'nur ein Paket möglich');
        }

        $result = new OptionResult($key);
        $result->carrier = $product->carrier;
        $result->carrierName = $tariffs->carrierName($product->carrier);
        $result->productId = $product->id;
        $result->productName = $product->name;
        $result->vatIncluded = $isEu;

        $month = (int) $now->format('n');
        $peakInPeak = $rules->isPeakInPeak($now);
        $energy = $rules->energyPercent($product->carrier);
        $fixed = $domestic ? $rules->fixedPrice($key) : null;

        foreach ($packaging->packages as $index => $package) {
            $reject = $product->rejectReason($package);
            if ($reject !== null) {
                return OptionResult::unavailable($key, \sprintf('Paket %d %s', $index + 1, $reject));
            }
            $base = $zone->basePrice($package->weightKg);
            if ($base === null) {
                return OptionResult::unavailable($key, \sprintf('Paket %d: Gewicht außerhalb der Preisstaffel', $index + 1));
            }

            $tier = $product->oversizeTierFor($package);
            $oversize = $tier === null ? 0 : $tier->amount;
            $passOn = 0;
            $peak = 0;
            $other = 0;
            $lines = [['name' => 'Grundpreis', 'net' => Money::unitsToFloat($base)]];
            if ($tier !== null) {
                $lines[] = ['name' => $tier->name, 'net' => Money::unitsToFloat($tier->amount)];
            }

            foreach ($product->surcharges as $surcharge) {
                if (!$surcharge->appliesTo($country, $destination->postcode)) {
                    continue;
                }
                $amount = match ($surcharge->type) {
                    'fixed' => $surcharge->amount,
                    'percent' => Money::percentOf($base, $surcharge->percent),
                    'energy' => Money::percentOf($base, $energy),
                    'peak' => \in_array($month, $surcharge->months, true) ? $surcharge->amount : 0,
                    'peakInPeak' => $peakInPeak ? $surcharge->amount : 0,
                    default => 0,
                };
                if ($amount === 0) {
                    continue;
                }
                if ($surcharge->type === 'peak' || $surcharge->type === 'peakInPeak') {
                    $peak += $amount;
                    $result->peakApplied = true;
                } elseif ($surcharge->passOn) {
                    $passOn += $amount;
                } else {
                    $other += $amount;
                }
                $lines[] = ['name' => $surcharge->name, 'net' => Money::unitsToFloat($amount)];
            }

            $purchase = $base + $oversize + $passOn + $peak + $other;

            // Verkaufspreis (Abschnitt 4/5)
            if ($free) {
                $sale = 0;
            } elseif ($domestic && $fixed !== null) {
                $sale = Money::eurosToCents($fixed);
                if ($rules->passOversize) {
                    $sale += Money::grossCents($oversize + $passOn, $rules->vatPercent);
                }
                if ($rules->passPeak) {
                    $sale += Money::grossCents($peak, $rules->vatPercent);
                }
            } elseif ($domestic) {
                $sale = Money::grossCents($purchase, $rules->vatPercent);
            } else {
                $sale = ($isEu ? Money::grossCents($purchase, $rules->vatPercent) : Money::toCents($purchase))
                    + Money::eurosToCents($rules->foreignSurcharge);
            }

            $result->purchaseNet += $purchase;
            $result->saleCents += $sale;
            $result->packages[] = $package->toArray() + [
                'tier' => $tier?->name,
                'purchaseNet' => Money::toCents($purchase) / 100,
                'sale' => $sale / 100,
                'lines' => $lines,
            ];
        }

        $result->available = true;
        if ($free) {
            $result->hints[] = ['code' => 'free'];
        }
        if ($product->basicTracking) {
            $result->hints[] = ['code' => 'basicTracking'];
        }
        if ($result->peakApplied && (!$domestic || $fixed === null || $rules->passPeak) && !$free) {
            $result->hints[] = ['code' => 'peak', 'carrier' => $result->carrierName];
        }

        return $result;
    }

    private function freightOption(
        Packaging $packaging,
        Destination $destination,
        ChannelRules $rules,
        ?FreightQuoteProvider $freight,
        float $goodsValue,
        bool $isEu,
        bool $anyParcel,
        bool $countryAllowed,
    ): OptionResult {
        $key = OptionResult::FREIGHT;
        if ($packaging->mode === PackageBuilder::MODE_PICKUP) {
            return OptionResult::unavailable($key, 'Warenkorb enthält einen Artikel „nur Abholung“');
        }
        if ($packaging->mode === PackageBuilder::MODE_PARCEL && $anyParcel) {
            return OptionResult::unavailable($key, 'Paketversand möglich');
        }
        if (!$countryAllowed) {
            return OptionResult::unavailable($key, 'Land nicht freigeschaltet');
        }

        $quote = $freight?->quote($destination, $packaging->units, $goodsValue) ?? FreightQuote::notConfigured();
        $result = new OptionResult($key);
        $result->carrier = 'freight';
        $result->carrierName = 'Spedition';
        $result->vatIncluded = $isEu;

        if ($quote->status === FreightQuote::OK) {
            $net = Money::fromEuro($quote->netPrice);
            $result->available = true;
            $result->purchaseNet = $net;
            $result->saleCents = ($isEu ? Money::grossCents($net, $rules->vatPercent) : Money::toCents($net))
                + Money::eurosToCents($rules->freightSurcharge);
            $result->transitMin = $quote->daysMin;
            $result->transitMax = $quote->daysMax ?? $quote->daysMin;
            $result->productId = (string) $quote->quotationId;

            return $result;
        }

        if ($quote->status === FreightQuote::ERROR && $rules->freightFallbackPrice !== null && $rules->freightFallbackPrice > 0) {
            $result->available = true;
            $result->saleCents = Money::eurosToCents($rules->freightFallbackPrice);
            $result->hints[] = ['code' => 'freightEstimated'];
            $result->reason = $quote->message;

            return $result;
        }

        return OptionResult::unavailable($key, $quote->status === FreightQuote::ERROR ? 'Speditionspreis nicht abrufbar: ' . $quote->message : 'Speditionspreis auf Anfrage', OptionResult::REASON_ON_REQUEST);
    }

    /**
     * Empfehlung: außerhalb der EU DHL (Zollanmeldung einfach), sonst die günstigste Paketoption.
     *
     * @param array<string, OptionResult> $options
     */
    private function markRecommended(array $options, bool $isEu): void
    {
        if (!$isEu && isset($options[OptionResult::DHL]) && $options[OptionResult::DHL]->available) {
            $options[OptionResult::DHL]->recommended = true;

            return;
        }
        $best = null;
        foreach ($options as $option) {
            if (!$option->available || $option->key === OptionResult::PICKUP) {
                continue;
            }
            if ($best === null || $option->saleCents < $best->saleCents) {
                $best = $option;
            }
        }
        if ($best !== null) {
            $best->recommended = true;
        }
    }
}
