# Nova Versandkosten

[![Spendier mir einen Kaffee](https://img.shields.io/badge/☕_Spendier_mir_einen_Kaffee-PayPal-0070ba?style=for-the-badge&logo=paypal&logoColor=white)](https://paypal.me/DrdotHouse)

*[English version](README.en.md)*

Shopware-6.7-Plugin, das die Versandkosten je Warenkorb aus den **eigenen Einkaufstarifen** der
Paketdienste berechnet – statt aus starren Preistabellen. Es bildet Pakete aus Gewicht und Maßen,
wählt je Dienstleister die passende Stufe (Warenpost, Kleinpaket, Paket, Sperrgut/Übermaß), rechnet
alle Zuschläge ein und bietet in der Kasse nur die Versandarten an, die für den Warenkorb wirklich
gehen – mit Preis, voraussichtlichem Zustelldatum und Hinweisen.

## Funktionen

- **Rechenkern in reinem PHP** (`src/Calculator`, ohne Shopware-Abhängigkeit): gleiche Eingabe →
  gleicher Preis; lässt sich 1:1 in andere Systeme (z. B. eine Warenwirtschaft) übertragen.
- **Paketbildung**: größter Artikel bestimmt das Paketmaß, weitere Artikel kommen mit hinein bis zum
  Höchstgewicht (Standard 25 kg), Sperrgut als Einzelpaket oder „zusammen packbar“.
- **Stufen je Dienstleister**: Warenpost (Warenwertgrenze), Kleinpaket, DPD, DHL – mit Höchstmaßen,
  Gurtmaß, Sperrgut-/Übermaß-/Übergröße-Zuschlägen aus der Tarifdatei.
- **Zuschläge**: Maut, Sicherheitsgebühr, Energiezuschlag (% je Dienstleister, monatlich pflegbar),
  Peak und Peak-in-Peak (wahlweise weitergeben, mit Hinweis in der Kasse), Inselzuschläge nach PLZ,
  Zollabfertigung und Währungszuschlag je Land.
- **Verkaufspreis je Verkaufskanal**: Festpreise im Heimatland (je weiteres Paket ein weiterer
  Paketpreis), Sperrgut/Übermaß = Einkauf brutto obendrauf, Ausland = Einkauf brutto + Aufschlag,
  **außerhalb der EU ohne MwSt.**, Sonderregel „versandkostenfrei“ für Hersteller oder Tags.
- **Spedition** über die Cargoboard-API (Preis + Laufzeit, Aufschlag, Zwischenspeicher, Ersatzpreis
  bei Fehlern); ohne API-Schlüssel „auf Anfrage“.
- **Kasse**: Preis, „Zustellung voraussichtlich Di, 04.11. – Mi, 05.11.“ (Bearbeitungszeit,
  Annahmeschluss, Laufzeit, Feiertage je Bundesland, eigene Schließtage), Hinweise („mit
  Basis-Sendungsverfolgung“, „inkl. Saisonzuschlag von DHL“), Empfehlung, Knopf „Lieferung per Tour
  anfragen“. Unpassende Versandarten werden ausgeblendet.
- **Bestellung**: Dienstleister, Produkt, Pakete (Anzahl, Maße, Gewicht), Einkauf, Verkauf und
  „Maße fehlen“ als Zusatzfelder – Grundlage für Warenwirtschaft und Etikett. Karte „Versandkosten“
  in der Bestellung (Differenz rot bei negativer Marge), optional E-Mail bei negativer Marge.
- **Tarifimport** als JSON oder CSV (Excel) in der Administration, gespeichert in der Datenbank.
- **Preisprüfung**: alle Festpreise je Kanal gegen den aktuellen Einkauf, Warnung bei Unterdeckung.
- **Google/Produktexport**: Twig-Funktion `nova_versand_label(product, context)` → `paket`,
  `sperrgut`, `spedition` oder `abholung`.

Die konkreten Einkaufspreise gehören nicht ins Plugin: Jeder Shop importiert seine eigenen Tarife.
Alle Preise in dieser Anleitung und in `examples/` sind **erfundene Platzhalter**.

## Installation

**Über den Nova Updater:** *Erweiterungen → Meine Erweiterungen → Plugin-Katalog* → „Nova
Versandkosten“ → *Installieren*. Danach aktivieren.

**Per ZIP:** Das ZIP `NovaVersandkosten-<Version>.zip` aus den
[Releases](https://github.com/DrdotHouse2106/SW6-Nova-Versandkosten/releases) unter *Erweiterungen →
Meine Erweiterungen → Erweiterung hochladen* hochladen, installieren und aktivieren. Danach die
Administration neu laden (Strg+F5).

Beim Installieren legt das Plugin an:

- sechs **inaktive** Versandarten (keinem Verkaufskanal zugeordnet): *Warenpost*, *DHL Kleinpaket*,
  *DPD Paket*, *DHL Paket*, *Spedition*, *Abholung* – erkennbar am technischen Namen
  `nova_versandkosten_…`. Vorhandene Versandarten mit diesem Namen bleiben unverändert.
- Zusatzfelder am Produkt („Versandeinordnung“, „Sperrgut-Packart“) und an der Bestellung.

Warum vom Plugin angelegte, inaktive Versandarten? Die Zuordnung läuft über den technischen Namen –
das ist robuster als IDs in der Konfiguration, und nichts erscheint im Shop, bevor Tarife importiert
und die Versandarten bewusst aktiviert sind. Namen, Beschreibungen, Bilder, Reihenfolge und
Verfügbarkeitsregeln kann man danach frei ändern; nur der technische Name muss bleiben. Eine eigene,
schon vorhandene Versandart lässt sich übernehmen, indem man ihr einen dieser technischen Namen gibt.

## Einrichtung Schritt für Schritt

1. **Tarife importieren:** *Erweiterungen → Nova Versandkosten → Tarife* → JSON- oder CSV-Datei wählen
   (oder Inhalt einfügen) → *Prüfen* → *Importieren*. Die Tarife gelten für alle Verkaufskanäle.
   Vorlage: [`examples/tarife-beispiel.json`](examples/tarife-beispiel.json) bzw.
   [`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv).
2. **Einstellungen je Verkaufskanal:** *Erweiterungen → Meine Erweiterungen → Nova Versandkosten →
   Konfigurieren* (oben den Verkaufskanal wählen): Festpreise, Aufschlag Ausland, Warenwertgrenzen,
   Energiezuschläge, Peak, Länder, Sonderregel versandkostenfrei, Lieferzeit, Cargoboard.
3. **Versandarten aktivieren:** *Einstellungen → Versand* → die gewünschten `nova_versandkosten_…`-
   Versandarten öffnen, aktivieren, ggf. umbenennen. Der Preis „0,00 €“ in der Preismatrix ist nur
   ein Platzhalter (Shopware sperrt Versandarten ohne Preis) – den echten Preis setzt das Plugin.
   Steuer: „Automatisch“ lassen.
4. **Verkaufskanal zuordnen:** *Verkaufskanäle → (Kanal) → Versandarten* – die aktivierten
   Versandarten hinzufügen und eine Standard-Versandart wählen (z. B. DPD Paket).
5. **Artikeldaten prüfen:** Gewicht (kg) und Länge/Breite/Höhe (mm, **verpackt**) in den
   Produktfeldern. Einordnung über das Zusatzfeld „Versandeinordnung“ oder über die Eigenschaft
   (Standard „Versandart“, siehe unten). Fehlen Maße, gelten Standardmaße je Einordnung und die
   Bestellung wird mit „Maße fehlen“ gekennzeichnet.
6. **Preisprüfung ansehen:** *Erweiterungen → Nova Versandkosten → Preisprüfung* – rot markierte
   Festpreise liegen unter dem aktuellen Einkauf.
7. **Testbestellung** in jedem Kanal: Warenkorb mit kleinem Artikel, Sperrgut und (falls genutzt)
   Speditionsware; in der Bestellung die Karte „Versandkosten“ prüfen.
8. **Monatlich:** Energiezuschläge (%) in den Einstellungen nachtragen; vor November den
   Peak-in-Peak-Zeitraum eintragen.

Abholung auf Treffen/Messe aus *Nova Termine* ist eine eigene Versandart und wird von diesem Plugin
nicht verändert.

## Artikeldaten und Einordnung

| Einordnung | Bedeutung |
|---|---|
| Paket | normaler Paketversand |
| Sperrgut | immer Sperrgut-/Übermaß-Zuschlag (z. B. keine Quaderform); Packart „Einzelpaket“ (jedes Stück ein Paket) oder „zusammen packbar“ (Stücke desselben Artikels teilen sich Pakete) |
| Spedition | ganze Sendung nur per Spedition, Abholung oder Tour-Anfrage |
| Nur Abholung | ganze Sendung nur Abholung |

Ist das Zusatzfeld leer, wird die Einordnung aus der Eigenschaft (Name einstellbar, Standard
„Versandart“) abgeleitet: hat der Artikel einen „Paket“-Wert (Standard: DHL, DPD, …) → Paket;
sonst einen „Sperrgut“-Wert (DHL Sperrgut, DPD Sperrgut, …) → Sperrgut; sonst „Spedition“/„Kurier“
→ Spedition; sonst „Abholung“ → nur Abholung. Die Wertnamen sind je Kanal einstellbar. Ohne beides:
Paket.

## Rechenregel

1. **Pakete bilden:** Spedition im Warenkorb → nur Spedition/Abholung. „Nur Abholung“ → nur Abholung.
   Sonst: Sperrgut-Einzelpakete je Stück, „zusammen packbare“ Sperrgut-Stücke desselben Artikels
   teilen sich Pakete bis zum Höchstgewicht. Übrige Artikel (größte zuerst) kommen in das größte
   Paket, solange es das Höchstgewicht nicht überschreitet; sonst neues Paket. Das Paketmaß wächst
   je Kante auf den größten enthaltenen Artikel. Stücke sind unteilbar.
2. **Je Dienstleister die passende Stufe:** Warenpost/Kleinpaket nur bei genau einem Paket, ohne
   Sperrgut, innerhalb der Höchstmaße und der Warenwertgrenze. Paket mit Gewichtsstaffel; Sperrgut-/
   Übermaß-Stufen greifen, wenn eine Bedingung zutrifft (die letzte greifende Stufe ersetzt die
   vorherigen) und das Paket in deren Höchstmaße passt. Passt ein Paket bei einem Dienstleister
   nicht, wird dieser nicht angeboten. Passt keiner, wird Spedition angeboten.
3. **Einkauf netto je Paket** = Grundpreis + Stufen-Zuschlag + feste Zuschläge + Prozentzuschläge
   (auf den Grundpreis) + Energiezuschlag + Peak (Monate laut Tarif) + Peak-in-Peak (Zeitraum laut
   Einstellung).
4. **Verkauf je Paket**
   - Heimatland mit Festpreis: Festpreis + (Sperrgut/Übermaß + als „weitergeben“ markierte Zuschläge)
     × MwSt. + Peak × MwSt. (wenn „Peak weitergeben“). Energie, Maut usw. ändern nur die Marge.
   - Heimatland ohne Festpreis: Einkauf × MwSt.
   - Ausland EU: Einkauf × MwSt. + Aufschlag Ausland. Außerhalb der EU: Einkauf (ohne MwSt.) + Aufschlag.
   - Sonderregel versandkostenfrei (alle Artikel passen, Land freigeschaltet): 0,00 €.
5. **Rundung:** kaufmännisch auf Cent nach der MwSt. (intern ganzzahlig in 1/10.000 €).
6. **Spedition:** Cargoboard-Preis netto × MwSt. (EU) + Aufschlag; Laufzeit von Cargoboard.
7. **Empfehlung:** außerhalb der EU DHL (Zollanmeldung), sonst die günstigste Option.

### Beispiele (erfundene Platzhaltertarife aus `examples/`)

| Warenkorb | Ergebnis |
|---|---|
| 0,4 kg, 20 × 15 × 4 cm, Warenwert 18 €, DE | Warenpost 5,00 · Kleinpaket 7,50 · DPD 7,50 · DHL 10,50 · Abholung 0,00 |
| 27 × 1 kg normale Teile, DE | DPD 15,00 (2 Pakete) · DHL 21,00 (2 Pakete) |
| Schwingarm 130 × 40 × 40 cm, 9 kg (Sperrgut) + 2 Kleinteile, DE | DPD 7,50 + Übermaß 6,00 × 1,19 = 14,64 · DHL 10,50 + Sperrgut 18,00 × 1,19 = 31,92 (1 Paket, Kleinteile wandern mit) |
| 2 kg nach Österreich | DPD (8,00 + 0,30 + 0,10) × 1,19 + 5 = 15,00 · DHL (12,00 + 2 × 1,00 + 0,15) × 1,19 + 5 = 21,84 |
| 2 kg in die Schweiz | DHL 20,00 + 2,00 + 0,15 + 5 = 27,15 (ohne MwSt., empfohlen) · DPD inkl. Zoll 20,00 und 10 % Währungszuschlag 35,30 |
| DHL Paket im November | 10,50 + 0,20 × 1,19 = 10,74, Hinweis „inkl. Saisonzuschlag von DHL“ |

Alle Fälle: [`tests/Fixtures/testfaelle-beispiel.json`](tests/Fixtures/testfaelle-beispiel.json).

## Tarifformat

Eine Tarifdatei enthält **Einkaufspreise netto in Euro** je Versandprodukt. Zwei gleichwertige
Formate: JSON (genau) und CSV (zum Bearbeiten in Excel). Der Import erkennt das Format selbst und
meldet alle Fehler mit Zeile bzw. Produkt. In der Administration lässt sich die aktuelle Datei als
JSON oder CSV herunterladen. Umwandeln ohne Shopware: `php scripts/tarif-konvertieren.php ein.json aus.csv`.

### JSON

```json
{
  "format": "nova-versandkosten-tarife",
  "version": 1,
  "name": "Meine Tarife 2027",
  "currency": "EUR",
  "carriers": { "dhl": { "name": "DHL" }, "dpd": { "name": "DPD" }, "post": { "name": "Deutsche Post" } },
  "products": [
    {
      "id": "dhl_paket_de",
      "carrier": "dhl",
      "option": "dhl",
      "name": "DHL Paket national",
      "limits": { "maxKg": 31.5, "maxDimsCm": [120, 60, 60] },
      "zones": [
        { "name": "Deutschland", "countries": ["DE"], "steps": [ { "maxKg": 1, "price": 4.00 }, { "maxKg": 31.5, "price": 14.00 } ] }
      ],
      "oversize": [
        { "name": "Sperrgut", "amount": 18.00, "if": { "exceedsDimsCm": [120, 60, 60], "bulky": true }, "max": { "lengthCm": 200, "girthCm": 360 } }
      ],
      "surcharges": [
        { "type": "fixed", "name": "Maut", "amount": 0.15 },
        { "type": "energy", "name": "Energiezuschlag" },
        { "type": "peak", "name": "Peakzuschlag", "amount": 0.20, "months": [11, 12] },
        { "type": "peakInPeak", "name": "Peak-in-Peak", "amount": 0.40 }
      ]
    }
  ]
}
```

| Feld | Bedeutung |
|---|---|
| `id` | eindeutige Produkt-ID (a–z, 0–9, `_`, `-`) |
| `carrier` | Dienstleister-Schlüssel (frei, z. B. `dhl`, `dpd`, `post`) – auch Schlüssel für den Energiezuschlag |
| `option` | Versandart im Shop: `warenpost`, `kleinpaket`, `dpd` oder `dhl`. Mehrere Produkte je Option sind üblich (national/international); je Land gilt das günstigste passende |
| `limits.maxKg` | Höchstgewicht je Paket |
| `limits.maxDimsCm` | Höchstmaße (beliebig gedreht) ohne Zuschlag |
| `limits.maxLengthCm`, `limits.maxGirthCm` | Höchstlänge / Gurtmaß (Länge + 2 × Breite + 2 × Höhe) |
| `limits.maxGoodsValue` | Warenwertgrenze (brutto); die Kanal-Einstellung hat Vorrang |
| `limits.singlePackage` | nur, wenn die Sendung genau ein Paket ist (Warenpost, Kleinpaket) |
| `limits.allowBulky` | `false` = kein Sperrgut |
| `limits.basicTracking` | Hinweis „mit Basis-Sendungsverfolgung“ in der Kasse |
| `zones[].countries` | ISO-Ländercodes |
| `zones[].steps` | Gewichtsstufen `maxKg` → `price` (aufsteigend) |
| `zones[].perPiece` + `perKg` (+ `roundKg`, Standard 0,1) | Stückpreis + kg-Preis, Gewicht auf `roundKg` aufgerundet |
| `zones[].perPiece` + `per100g` | Stückpreis + Preis je angefangene 100 g |
| `oversize[]` | Sperrgut-/Übermaß-Stufen. `if` (eine Bedingung genügt): `lengthOver`, `lengthAtLeast`, `sideOver` (Breite oder Höhe), `volumeOverL`, `girthOver`, `exceedsDimsCm`, `weightOverKg`, `bulky` (Artikel ist als Sperrgut eingeordnet). `max`: `lengthCm`, `girthCm`, `dimsCm`, `kg`. Die letzte greifende Stufe gilt |
| `surcharges[].type` | `fixed` (Betrag), `percent` (`percent` vom Grundpreis), `energy` (% aus der Kanal-Einstellung), `peak` (Betrag in `months`), `peakInPeak` (Betrag im eingestellten Zeitraum) |
| `surcharges[].countries` / `postcodes` | nur für diese Länder / PLZ (einzeln oder Bereich `25846-25847`) |
| `surcharges[].passOn` | `true` = auch auf Festpreise brutto aufschlagen |

### CSV (Excel)

Eine Datei, eine Zeile je Eintrag, Spalte `type` sagt, was die Zeile ist. Trennzeichen `;` (Excel
deutsch) oder `,`; Dezimalkomma oder -punkt; Listen mit Leerzeichen; `limits`/`condition` als
`schlüssel=wert`-Paare, Maße als `35,3x25x5`. In `product` einer `surcharge`-/`oversize`-Zeile dürfen
mehrere Produkt-IDs stehen. Auszug (vollständige Datei:
[`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv)):

```
type;product;carrier;option;name;zone;countries;max_kg;price_net;per_kg_net;per_100g_net;round_kg;amount_net;percent;kind;condition;limits;postcodes;months;pass_on
meta;;;;Meine Tarife 2027;;;;;;;;;;;;;;;
carrier;;dhl;;DHL;;;;;;;;;;;;;;;
product;dhl_paket_de;dhl;dhl;DHL Paket national;;;;;;;;;;;;maxKg=31,5 maxDimsCm=120x60x60;;;
rate;dhl_paket_de;;;;Deutschland;DE;1;4,00;;;;;;;;;;;
rate;dhl_paket_de;;;;Deutschland;DE;31,5;14,00;;;;;;;;;;;
rate;dhl_paket_int;;;;Zone 1;AT BE FR NL;;12,00;1,00;;0,1;;;;;;;;
rate;warenpost_int;;;;Zone 1;AT FR;;4,50;;0,40;;;;;;;;;
oversize;dhl_paket_de;;;Sperrgut;;;;;;;;18,00;;;exceedsDimsCm=120x60x60 bulky=1;lengthCm=200 girthCm=360;;;
surcharge;dhl_paket_de;;;Maut;;;;;;;;0,15;;fixed;;;;;
surcharge;dhl_paket_de;;;Energiezuschlag;;;;;;;;;;energy;;;;;
surcharge;dhl_paket_de;;;Peakzuschlag;;;;;;;;0,20;;peak;;;;11 12;
surcharge;dpd_de;;;Insel;;DE;;;;;;12,00;;fixed;;;27498 25846-25847;;
surcharge;dpd_europa;;;Währungszuschlag;;CH;;;;;;;10;percent;;;;;
```

| type | Spalten |
|---|---|
| `meta` | `name` |
| `carrier` | `carrier`, `name` |
| `product` | `product`, `carrier`, `option`, `name`, `limits` |
| `rate` (Stufe) | `product`, `zone`, `countries`, `max_kg`, `price_net` |
| `rate` (kg-Preis) | `product`, `zone`, `countries`, `price_net` (Stück), `per_kg_net`, `round_kg` – oder `per_100g_net` |
| `oversize` | `product`, `name`, `amount_net`, `condition`, `limits` |
| `surcharge` | `product`, `name`, `kind`, `amount_net` oder `percent`, optional `countries`, `postcodes`, `months`, `pass_on` (`1`) |

## Einstellungen (je Verkaufskanal)

| Bereich | Einstellungen |
|---|---|
| Verkaufspreise | Festpreise Warenpost/Kleinpaket/DPD/DHL (leer = Einkauf brutto), Aufschlag Ausland, Sperrgut/Übermaß weitergeben, Warenwertgrenzen, Höchstgewicht je Paket, MwSt.-Satz, Heimatland |
| Zuschläge | Peak weitergeben, Peak-in-Peak von/bis, Energiezuschlag je Dienstleister (`dhl=12,5`), DPD außerhalb der EU |
| Länder | freigeschaltete Länder, versandkostenfrei für Hersteller/Tags in bestimmten Ländern |
| Artikeldaten | Eigenschaftsname, Wertnamen je Einordnung, Standardmaße und -gewicht |
| Lieferzeit | Anzeige an/aus, Annahmeschluss, Bearbeitungstage, Feiertage (Bundesland), Schließtage, Laufzeiten (`option;länder;min-max`, z. B. `dpd;DE;1-2`, `*;EU;3-5`, `warenpost;*;4-10`) |
| Spedition | Cargoboard-API-Schlüssel, Sandbox, Aufschlag, Ersatzpreis, Ladungsträger, Abhol-PLZ/-Land, Privatempfänger, Zeitlimit, Zwischenspeicher |
| Anfrage/Kontrolle | Knopf „Lieferung per Tour anfragen“, Empfänger, E-Mail bei negativer Marge |

## Zusatzfelder an der Bestellung

| Feld | Inhalt |
|---|---|
| `nova_versandkosten_option` | `warenpost`, `kleinpaket`, `dpd`, `dhl`, `spedition`, `abholung` |
| `nova_versandkosten_carrier`, `nova_versandkosten_product` | Dienstleister, Versandprodukt |
| `nova_versandkosten_packages` | Liste `{weightKg, lengthCm, widthCm, heightCm, bulky, tier, purchaseNet, sale}` |
| `nova_versandkosten_package_count` | Anzahl Pakete |
| `nova_versandkosten_purchase_net` | Einkauf netto (geschätzt) |
| `nova_versandkosten_sale`, `nova_versandkosten_sale_net` | Versandkosten wie berechnet (brutto bzw. netto) |
| `nova_versandkosten_dims_missing` | Maße/Gewicht fehlten an mindestens einem Artikel |
| `nova_versandkosten_delivery` | „Di, 04.11. – Mi, 05.11.“ |
| `nova_versandkosten_details` | Warenwert, Modus, Hinweise, Cargoboard-Angebots-ID |

## Google / Produktexport

Im Produktexport (*Verkaufskanäle → Produktvergleich*) steht die Twig-Funktion
`nova_versand_label(product, context)` zur Verfügung. Ergebnis: `paket`, `sperrgut` (Einordnung
Sperrgut oder verpackt größer als 120 × 60 × 60 cm), `spedition` oder `abholung`. Beispiel für das
Google-Shopping-Feld `shipping_label`:

```twig
<g:shipping_label>{{ nova_versand_label(product, context) }}</g:shipping_label>
```

Im Merchant Center je Label eine Versandregel anlegen (Spedition mit vorsichtigem Festpreis oder
Artikel aus dem Feed lassen; `abholung`-Artikel nicht in den Feed).

## Datenschutz

- Die Berechnung läuft im Shop; Tarife und Einstellungen liegen in der Shop-Datenbank.
- Nur für **Speditionsware** und nur mit eingetragenem API-Schlüssel fragt das Plugin Cargoboard an.
  Übertragen werden: PLZ und Land von Absender und Empfänger, Gewichte, Maße, Ladungsträger,
  Privatkunde ja/nein, außerhalb der EU der Warenwert (Zoll). **Kein Name, keine Straße, keine
  E-Mail.** Den Hinweis in der Datenschutzerklärung entsprechend ergänzen.
- Der Knopf „Lieferung per Tour anfragen“ öffnet nur das E-Mail-Programm des Kunden.

## Geplant

- Eigene Preisliste mit Volumengewicht (z. B. für einen Kanal mit Lieferanten-Preisliste)
- Express-/Zeitzustellung, Paketshop-Zustellung, Weltweit-Tarife mit Volumengewicht
- Variabler Transportkostenzuschlag International je kg; automatischer Abruf der Energiezuschläge
- Kontaktformular statt E-Mail für die Tour-Anfrage; Versandseite (Tabellen) als Erlebniswelt-Element
- Lieferzeiten an Google (Merchant Center) übertragen; Monats-Liefertermine anderer Plugins einbeziehen
  (heute: Erscheinungsdatum des Produkts)
- Standard-Versandart je Land (heute: Empfehlung außerhalb der EU nur als Hinweis „empfohlen“)
- Cargoboard-Buchung und Etiketten (gehört in die Warenwirtschaft)

## Entwicklung

```bash
php phpunit.phar                     # Unit-Tests des Rechenkerns (ohne Shopware)
php scripts/testfaelle.php           # Beispiel-Testfälle (Tabelle Soll/Ist)
NOVA_VK_TARIFE=/pfad/tarife.json NOVA_VK_FAELLE=/pfad/faelle.json php scripts/testfaelle.php
php scripts/tarif-konvertieren.php tarife.json tarife.csv
```

Die eigenen Tarife und Testfälle mit echten Preisen gehören **nicht** ins Repository – das Skript
nimmt die Pfade als Umgebungsvariablen.

Template-Blöcke: `nova_versandkosten_method_info` (in `component/shipping/shipping-method.html.twig`),
`nova_versandkosten_request` (`nova-versandkosten/request.html.twig`). Versandarten-Erweiterung
`shippingMethod.extensions.novaVersandkosten` (auch in der Store-API): `price`, `vatIncluded`,
`packageCount`, `delivery`, `hints`, `recommended`. Warenkorb-Erweiterung `novaVersandkosten`:
alle Optionen mit Paketen.

Die Administration liegt fertig gebaut unter `src/Resources/public/administration` (wird
eingecheckt). Nach Änderungen an `src/Resources/app/administration` neu bauen – innerhalb einer
Shopware-Installation mit `bin/build-administration.sh`, oder nur dieses Plugin mit dem
Administration-Quellcode von Shopware 6.7:

```bash
# PROJECT_ROOT enthält var/plugins.json (Eintrag „NovaVersandkosten“ mit basePath custom/plugins/NovaVersandkosten/src/)
# und custom/plugins/NovaVersandkosten (Kopie dieses Repositorys)
cd <shopware>/src/Administration/Resources/app/administration
PROJECT_ROOT=<projekt> SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1 VITE_MODE=production npx ts-node -T build.ts
# danach custom/plugins/NovaVersandkosten/src/Resources/public zurückkopieren
```

Kein eigenes Storefront-JavaScript.

## Häufige Probleme

### Die Versandarten erscheinen nicht in der Kasse

**Ursache:** Versandart inaktiv, nicht dem Verkaufskanal zugeordnet, keine Tarifdatei importiert oder
für Land/Warenkorb gibt es keinen passenden Tarif.

**Lösung:** Schritte 1–4 der Einrichtung prüfen; *Preisprüfung* zeigt, ob die Tarife geladen sind.

### Spedition zeigt „auf Anfrage“

**Ursache:** Kein Cargoboard-API-Schlüssel, Abhol-PLZ fehlt, Kunde hat noch keine Lieferadresse
(PLZ) oder Cargoboard antwortet nicht. Fehler stehen im Shopware-Log („Nova Versandkosten“).

**Lösung:** Schlüssel und Abhol-PLZ eintragen, bei Ausfällen einen Ersatzpreis setzen.

### Bestellung zeigt „Maße fehlen“

**Ursache:** Mindestens ein Artikel hatte kein Gewicht oder keine vollständigen Maße; gerechnet wurde
mit den Standardwerten.

**Lösung:** Gewicht und verpackte Maße am Artikel nachtragen.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
