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
- **Zuschläge**: Maut, Sicherheitsgebühr, Energiezuschlag (% je Dienstleister, **täglich automatisch
  von DHL und DPD abgerufen** oder manuell),
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
  anfragen“. Unpassende Versandarten werden ausgeblendet; die **günstigste mögliche Versandart ist
  vorgewählt** und eine nicht mehr mögliche wird sofort gewechselt (ab 1.3.1).
- **Bestellung**: Dienstleister, Produkt, Pakete (Anzahl, Maße, Gewicht), Einkauf, Verkauf und
  „Maße fehlen“ als Zusatzfelder – Grundlage für Warenwirtschaft und Etikett. Karte „Versandkosten“
  in der Bestellung (Differenz rot bei negativer Marge), optional E-Mail bei negativer Marge.
- **Administration** (neu in 1.3.0): ein Modul *Bestellungen → Versandkosten* mit Reitern für
  Übersicht, Einstellungen, Touren, Tarife und Preisprüfung (siehe „Administration“).
- **Tarifimport** als JSON oder CSV (Excel) per Datei-Upload, Ziehen & Ablegen oder Einfügen, gespeichert in der
  Datenbank; Vorlagen und Export zum Bearbeiten in Excel.
- **Preisprüfung**: alle Festpreise je Kanal gegen den aktuellen Einkauf, Warnung bei Unterdeckung.
- **Google/Produktexport**: Twig-Funktion `nova_versand_label(product, context)` → `paket`,
  `sperrgut`, `spedition`, `abholung` oder `anfrage`.
- **Zur offenen Bestellung beipacken** (neu in 1.1.0): Kunden und Gäste packen neue Artikel zu einer
  noch nicht versendeten Bestellung; berechnet wird nur die Differenz der Versandkosten.
- **Versandkosten auf Anfrage** (neu in 1.1.0): Bestellung ohne Versandpreis, der Shop trägt die
  Versandkosten nachträglich ein, der Kunde bekommt Gesamtbetrag und Bezahl-Link per E-Mail;
  unbezahlte Anfragen werden nach X Tagen storniert.
- **Eigene Touren** (neu in 1.2.0): Strecken mit Varianten und Wegpunkten, Tourtermine (mit/ohne
  Hänger, Bestellschluss), Versandart „Lieferung mit unserer Tour“ nur für Adressen nahe der Strecke,
  Preis nach Einordnung und Abweichung, Termin-Auswahl in der Kasse, Liste „Tour-Lieferungen“ zum
  Beladen, Hinweis an Bestellungen „Versandkosten auf Anfrage“ in Reichweite einer Strecke.
  **Preisprofile** (neu in 1.2.1): der Tourpreis je nachdem, wer fährt – Auswahl am Tourtermin.
- **Tour-Benachrichtigung** (neu in 1.2.0): Kunden (Schalter je Lieferadresse im Konto) und Gäste
  (Formular mit Double-Opt-in und Captcha) werden auf Knopfdruck informiert, wenn ein Tourtermin in
  ihre Nähe fährt – ohne die Strecke zu verraten.
- **PLZ-Koordinaten für Deutschland mitgeliefert** (GeoNames, CC BY 4.0) – kein externer Dienst.

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

- neun **inaktive** Versandarten (keinem Verkaufskanal zugeordnet): *Warenpost*, *DHL Kleinpaket*,
  *DPD Paket*, *DHL Paket*, *Spedition*, *Abholung*, *Versandkosten auf Anfrage*
  (`nova_versandkosten_anfrage`), *Zu meiner Bestellung beipacken* (`nova_versandkosten_beipack`) und
  *Lieferung mit unserer Tour* (`nova_versandkosten_tour`) – erkennbar am technischen Namen
  `nova_versandkosten_…`. Vorhandene Versandarten mit diesem Namen bleiben unverändert; beim Update
  kommen nur die fehlenden dazu.
- die Tabellen für Strecken, Varianten, Tourtermine, Abonnenten der Tour-Benachrichtigung und die
  PLZ-Koordinaten (Migration); die mitgelieferte PLZ-Datei wird beim Installieren/Aktualisieren
  geladen (nur wenn sie sich geändert hat, ca. 20.800 Zeilen).
- Zusatzfelder am Produkt („Versandeinordnung“, „Sperrgut-Packart“) und an der Bestellung (beim Update
  werden fehlende Felder und der neue Wert „Nur auf Anfrage“ ergänzt).
- die E-Mail-Vorlagen „Nova Versandkosten: Versandkosten nachberechnet“, „… Tour-Benachrichtigung
  bestätigen“ und „… Tour in Ihrer Nähe“ (deutsch/englisch).
- drei geplante Aufgaben (täglich): `nova_versandkosten.energy_fetch` (Energiezuschläge abrufen),
  `nova_versandkosten.request_cancel` (unbezahlte Anfragen stornieren) und
  `nova_versandkosten.tour_cleanup` (Tour-Benachrichtigung aufräumen). Sie brauchen einen laufenden
  Scheduled-Task-Runner (Cron oder `bin/console scheduled-task:run`).

Beim **Deinstallieren ohne „Daten behalten“** entfernt das Plugin die Tabellen der Touren, die
Zusatzfelder, die E-Mail-Vorlagen und die Einstellungen; Versandarten werden nur deaktiviert.

Warum vom Plugin angelegte, inaktive Versandarten? Die Zuordnung läuft über den technischen Namen –
das ist robuster als IDs in der Konfiguration, und nichts erscheint im Shop, bevor Tarife importiert
und die Versandarten bewusst aktiviert sind. Namen, Beschreibungen, Bilder, Reihenfolge und
Verfügbarkeitsregeln kann man danach frei ändern; nur der technische Name muss bleiben. Eine eigene,
schon vorhandene Versandart lässt sich übernehmen, indem man ihr einen dieser technischen Namen gibt.

## Administration

Alles an einem Ort: **Bestellungen → Versandkosten** (zusätzlich als Kachel unter *Einstellungen →
Versandkosten*). Reiter und Unterreiter stehen in der Adresse, Lesezeichen funktionieren
(`#/nova/versandkosten/index/<reiter>`).

| Reiter | Inhalt |
|---|---|
| Übersicht | Status auf einen Blick: Tarife importiert?, Energiezuschläge aktuell?, Warnungen der Preisprüfung, aktive `nova_versandkosten_…`-Versandarten je Verkaufskanal, geplante Aufgaben (Status, letzter/nächster Lauf), kommende Tourtermine – mit Links zum passenden Reiter |
| Preise | Festpreise, Aufschlag Ausland, Sperrgut weitergeben, Warenwertgrenzen, Höchstgewicht, MwSt., Heimatland |
| Zuschläge & Energie | aktuelle Energiezuschläge (Knopf *Jetzt abrufen*), Peak/Peak-in-Peak, Energiezuschlag automatisch/manuell |
| Länder & Lieferzeit | freigeschaltete Länder, versandkostenfrei, DPD außerhalb der EU, Zustelldatum |
| Artikeldaten | Eigenschaft und Wertnamen der Einordnung, Standardmaße/-gewicht |
| Spedition | Cargoboard |
| Anfrage & Beipacken | Versandkosten auf Anfrage, Beipacken, Knopf „Lieferung per Tour anfragen“ |
| Touren | Unterreiter Tourtermine, Strecken, Preisprofile, Tour-Lieferungen, Benachrichtigung, Einstellungen |
| Tarife | Datei hochladen (ziehen oder wählen) oder einfügen, Prüfen/Importieren, aktuelle Tarife als CSV/JSON exportieren, Vorlagen und Spaltenerklärung |
| Preisprüfung | Festpreise je Kanal gegen den Einkauf, Einstellungen „Kontrolle“ (Marge, fehlende Maße) |

**Einstellungs-Reiter:** Oben den Verkaufskanal wählen – „Alle Verkaufskanäle“ sind die
Standardwerte; je Kanal erbt ein Feld den Standardwert, solange das Kettensymbol geschlossen ist. Es
sind **dieselben Werte** wie in der Plugin-Konfiguration (*Erweiterungen → Meine Erweiterungen → Nova
Versandkosten → Konfigurieren*, dort oben ein Link ins Modul), gespeichert in `system_config` unter
`NovaVersandkosten.config.*`. *Speichern* sichert die Änderungen aller Reiter und Verkaufskanäle.

**Rechte** wie bisher: Einstellungen, Tarife, Energiezuschläge und Preisprüfung brauchen das Recht
„Einstellungen“ (*Benutzer & Rechte → Rollen → Weitere Rechte → Einstellungen*), die Touren die Rolle
*Bestellungen → Touren*. Der Menüeintrag erscheint mit einem der beiden Leserechte; Reiter ohne Recht
werden ausgeblendet.

**Alte Wege** (bis 1.2.1): *Erweiterungen → Nova Versandkosten* und *Bestellungen → Touren* gibt es
als Menüeinträge nicht mehr; alte Lesezeichen leiten ins Modul weiter.

## Einrichtung Schritt für Schritt

1. **Tarife importieren:** *Bestellungen → Versandkosten → Tarife* → CSV- oder JSON-Datei in das Feld
   ziehen oder *Datei wählen* (sie wird sofort geprüft; alternativ Inhalt einfügen) → *Importieren*.
   Die Tarife gelten für alle Verkaufskanäle.
   Vorlage: [`examples/tarife-beispiel.json`](examples/tarife-beispiel.json) bzw.
   [`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv).
2. **Einstellungen je Verkaufskanal:** *Bestellungen → Versandkosten*, Reiter *Preise*, *Zuschläge &
   Energie*, *Länder & Lieferzeit*, *Artikeldaten*, *Spedition*, *Anfrage & Beipacken* (oben den
   Verkaufskanal wählen, dann *Speichern*): Festpreise, Aufschlag Ausland, Warenwertgrenzen,
   Energiezuschläge, Peak, Länder, Sonderregel versandkostenfrei, Lieferzeit, Cargoboard. Dieselben
   Felder stehen weiterhin in der Plugin-Konfiguration (*Erweiterungen → Meine Erweiterungen → Nova
   Versandkosten → Konfigurieren*).
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
6. **Preisprüfung ansehen:** *Bestellungen → Versandkosten → Preisprüfung* – rot markierte
   Festpreise liegen unter dem aktuellen Einkauf.
7. **Testbestellung** in jedem Kanal: Warenkorb mit kleinem Artikel, Sperrgut und (falls genutzt)
   Speditionsware; in der Bestellung die Karte „Versandkosten“ prüfen.
8. **Energiezuschläge:** *Bestellungen → Versandkosten → Zuschläge & Energie → Jetzt abrufen* –
   danach läuft der Abruf täglich. Vor November den Peak-in-Peak-Zeitraum eintragen.
9. **Optional:** Versandarten *Versandkosten auf Anfrage* und *Zu meiner Bestellung beipacken*
   aktivieren und zuordnen (siehe eigene Abschnitte unten), Link „Noch etwas vergessen?“ in die
   Bestellbestätigung einbauen.
10. **Optional: Eigene Touren** – Strecken und Termine unter *Bestellungen → Versandkosten → Touren*, Versandart
    *Lieferung mit unserer Tour* aktivieren (siehe Abschnitt „Eigene Touren“).

Abholung auf Treffen/Messe aus *Nova Termine* ist eine eigene Versandart und wird von diesem Plugin
nicht verändert.

## Artikeldaten und Einordnung

| Einordnung | Bedeutung |
|---|---|
| Paket | normaler Paketversand |
| Sperrgut | immer Sperrgut-/Übermaß-Zuschlag (z. B. keine Quaderform); Packart „Einzelpaket“ (jedes Stück ein Paket) oder „zusammen packbar“ (Stücke desselben Artikels teilen sich Pakete) |
| Spedition | ganze Sendung nur per Spedition, Abholung oder Tour-Anfrage |
| Nur Abholung | ganze Sendung nur Abholung |
| Nur auf Anfrage | ganze Sendung nur „Versandkosten auf Anfrage“ (und Abholung) |

Ist das Zusatzfeld leer, wird die Einordnung aus der Eigenschaft (Name einstellbar, Standard
„Versandart“) abgeleitet: hat der Artikel einen „Paket“-Wert (Standard: DHL, DPD, …) → Paket;
sonst einen „Sperrgut“-Wert (DHL Sperrgut, DPD Sperrgut, …) → Sperrgut; sonst „Spedition“/„Kurier“
→ Spedition; sonst „Abholung“ → nur Abholung; sonst „Anfrage“/„auf Anfrage“ → nur auf Anfrage. Die
Wertnamen sind je Kanal einstellbar. Ohne beides: Paket.

**Maße und Gewicht:** In der Regel genügt das **Gewicht**. Maße (verpackt) lohnen sich nur für
Grenzfälle – Artikel, die nahe an den Höchstmaßen liegen oder je nach Verpackung Sperrgut werden.
Fehlen Maße, gilt die Einordnung: „Paket“ wird mit Standardmaßen innerhalb der normalen Grenzen
gerechnet (kein Aufschlag), Sperrgut wird nach Einordnung berechnet. Fehlende Maße kosten also nichts
extra. Die Bestellung bekommt trotzdem das Zusatzfeld „Maße fehlen“ (z. B. für Lieferschein/ERP);
ob der Hinweis an der Bestellkarte in der Administration und in den internen E-Mails erscheint,
steuert die Einstellung „Hinweis ‚Maße fehlen‘ ausgeben“ (Standard an). Kunden sehen ihn nie.

**Kleinsendung ohne Maße** (neu in 1.3.0, je Verkaufskanal, Reiter *Artikeldaten*): Die
Standardmaße „Paket“ (30 × 20 × 15 cm) passen nie in Warenpost (≤ 5 cm hoch) oder Kleinpaket
(≤ 8 cm) – ein kleines Teil ohne Maße bekäme also nie Warenpost. Die Einstellung „Kleinsendung ohne
Maße“ (*aus* / *Kleinpaket-fähig* / *Warenpost-fähig*, Standard: Warenpost-fähig) mit „bis
Gesamtgewicht“ (Standard 1 kg) behebt das: Haben **alle** Artikel im Warenkorb die Einordnung „Paket“
und **keine** Maße und liegt das Gesamtgewicht bis zur Grenze, prüfen Warenpost (bei
„Warenpost-fähig“) und Kleinpaket nur Gewicht, Warenwertgrenze und „genau ein Paket“, nicht die
Standardmaße. Sobald ein Artikel Maße hat (oder Sperrgut ist), wird wie bisher exakt geprüft. DPD/DHL
rechnen unverändert mit den Standardmaßen; „Maße fehlen“ wird weiter gesetzt.

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
   - Festpreis mit Gewichtsgrenze (neu in 1.3.0, je Festpreis „gilt bis X kg“, leer = immer): Ein
     Paket über der Grenze kostet statt des Festpreises Einkauf × MwSt. – oder, je nach Einstellung
     „Über der Gewichtsgrenze eines Festpreises“, Einkauf × MwSt. + Aufschlag € je Paket. Die
     Preisprüfung rechnet genauso.
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
mehrere Produkt-IDs stehen. Zeilen, deren erste Spalte mit `#` beginnt, sind Kommentare. Im Reiter
*Tarife* gibt es die Beispieldateien und eine leere Vorlage (Kopfzeile + je Zeilentyp eine kommentierte
Beispielzeile) zum Herunterladen, eine aufklappbare Spaltenerklärung und den Export der aktuellen
Tarife als CSV – so lässt sich der Bestand in Excel bearbeiten und wieder importieren. Excel-CSV mit
Semikolon geht direkt, in UTF-8 (mit oder ohne BOM) oder Windows-1252 („CSV (Trennzeichen-getrennt)“).
Auszug (vollständige Datei: [`examples/tarife-beispiel.csv`](examples/tarife-beispiel.csv)):

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

Im Modul *Bestellungen → Versandkosten* nach Reitern sortiert (Spalte „Reiter“), in der
Plugin-Konfiguration als Karten untereinander.

| Bereich (Reiter) | Einstellungen |
|---|---|
| Verkaufspreise (Preise) | Festpreise Warenpost/Kleinpaket/DPD/DHL (leer = Einkauf brutto), je Festpreis „gilt bis X kg“ und was darüber gilt (Einkauf brutto bzw. Einkauf brutto + Aufschlag), Aufschlag Ausland, Sperrgut/Übermaß weitergeben, Warenwertgrenzen, Höchstgewicht je Paket, MwSt.-Satz, Heimatland, günstigste mögliche Versandart vorwählen |
| Peak-Zuschläge (Zuschläge & Energie) | Peak weitergeben, Peak-in-Peak von/bis |
| Energiezuschläge (Zuschläge & Energie) | Energiezuschlag je Dienstleister (`dhl=12,5`), automatisch/manuell je Dienstleister, DPD Emergency Fuel Surcharge, Schlüssel in der Tarifdatei, E-Mail bei Abruf-Fehlern |
| Länder (Länder & Lieferzeit) | freigeschaltete Länder, versandkostenfrei für Hersteller/Tags in bestimmten Ländern, DPD außerhalb der EU |
| Artikeldaten (Artikeldaten) | Eigenschaftsname, Wertnamen je Einordnung, Standardmaße und -gewicht, Kleinsendung ohne Maße (aus/Kleinpaket-fähig/Warenpost-fähig, bis Gesamtgewicht) |
| Lieferzeit (Länder & Lieferzeit) | Anzeige an/aus, Annahmeschluss, Bearbeitungstage, Feiertage (Bundesland), Schließtage, Laufzeiten (`option;länder;min-max`, z. B. `dpd;DE;1-2`, `*;EU;3-5`, `warenpost;*;4-10`) |
| Spedition (Spedition) | Cargoboard-API-Schlüssel, Sandbox, Aufschlag, Ersatzpreis, Ladungsträger, Abhol-PLZ/-Land, Privatempfänger, Zeitlimit, Zwischenspeicher |
| Versandkosten auf Anfrage (Anfrage & Beipacken) | wann anbieten (Spedition ohne Preis, zusätzlich zu Spedition, Länder ohne Tarif, Artikel „nur auf Anfrage“, immer), Zahlarten in der Kasse, Empfänger „Versandkosten eintragen“, Stornieren nach X Tagen |
| Beipacken (Anfrage & Beipacken) | Höchstalter der Bestellung (Tage), Formular für Gäste |
| Tour-Anfrage in der Kasse (Anfrage & Beipacken) | Knopf „Lieferung per Tour anfragen“, Empfänger |
| Kontrolle (Preisprüfung) | Hinweis „Maße fehlen“ ausgeben, E-Mail bei negativer Marge |
| Eigene Touren (Touren → Einstellungen) | Preis Kleinteile/Paket/Sperrgut und Höchstabweichung, Speditionsstufen (`30=190`, `60=290`), Umwegfaktor, Lieferländer, Standard-Bestellschluss, Hinweis in der Kasse |
| Tour-Benachrichtigung (Touren → Einstellungen) | einschalten, Löschen nach X Monaten ohne Aktivität |

## Zur offenen Bestellung beipacken

Ein Kunde hat bestellt, die Bestellung ist noch nicht versendet – und er hat etwas vergessen. Mit der
Versandart **„Zu meiner Bestellung beipacken“** (`nova_versandkosten_beipack`) bestellt er die neuen
Artikel und zahlt nur die Differenz der Versandkosten.

**Wer darf zu welcher Bestellung beipacken?**

- Nur zu **eigenen** Bestellungen: Kunden mit Konto wählen in der Kasse aus ihren Bestellungen; Gäste
  kommen über den Link aus der Bestellbestätigung (Shopwares geheimer Bestell-Code `deepLinkCode`)
  oder geben Bestellnummer + E-Mail + PLZ ein (höchstens 5 Versuche je Stunde und IP). Daten fremder
  Bestellungen werden nie angezeigt; Fehlermeldungen verraten nicht, welche Angabe nicht passte.
- Die Bestellung muss **offen** sein: Lieferstatus „offen“ (nicht versendet, nicht teilweise
  versendet), nicht storniert oder abgeschlossen, nicht älter als X Tage (Einstellung, Standard 14),
  gleicher Verkaufskanal, gleiches Lieferland. Beipack-Bestellungen selbst können kein Ziel sein.
- Beim **Abschluss** prüft das Plugin alles erneut (Shopware rechnet beim Bestellen neu). Wurde die
  alte Bestellung inzwischen versendet oder storniert, ist die Versandart gesperrt und Shopware
  wechselt auf eine andere.

**Preis:** Versand für (Artikel der alten Bestellung + frühere Beipack-Bestellungen + Warenkorb) mit
derselben Versandoption wie die alte Bestellung (aus deren Zusatzfeldern; geht diese nicht mehr oder
fehlt sie: die günstigste passende) **minus** bereits bezahlter Versand, mindestens 0,00 €. Beispiele
(Platzhalterpreise): alt Kleinpaket 7,50 €, zusammen DPD 7,50 € → 0,00 €; alt Warenpost 5,00 €,
zusammen Kleinpaket 7,50 € → 2,50 €. Die Kasse zeigt die Rechnung („Versand für alle Artikel
zusammen – bereits bezahlt = Nachzahlung“).

**Was passiert mit der Bestellung?**

- Lieferadresse = Lieferadresse der alten Bestellung (das Paket geht ja dorthin).
- Bestellkommentar beginnt mit **„BEIPACK zu Bestellung &lt;Nr&gt;“** (ein Kundenkommentar bleibt dahinter
  erhalten) – Warenwirtschaft und Kommissionierung sehen es sofort.
- Zusatzfelder: an der neuen Bestellung `nova_versandkosten_beipack_zu` (ID) und
  `nova_versandkosten_beipack_zu_nr` (Nummer), an der alten `nova_versandkosten_beipack_bestellungen`
  (Liste). Die alte Bestellung bekommt außerdem einen Eintrag im internen Kommentar, und die Karte
  „Versandkosten“ in der Administration zeigt beide Seiten mit Link.

**Einrichtung:**

1. *Einstellungen → Versand* → „Zu meiner Bestellung beipacken“ aktivieren, dem Verkaufskanal zuordnen
   (der Platzhalterpreis 0,00 € bleibt; den Preis setzt das Plugin).
2. Einstellungen „Zur offenen Bestellung beipacken“: Höchstalter in Tagen, Formular für Gäste an/aus.
3. Link in die Bestellbestätigung einbauen: *Einstellungen → E-Mail-Vorlagen →
   „Bestellbestätigung“* (je Sprache) an passender Stelle einfügen:

```twig
{% if order.deepLinkCode %}
<p>Noch etwas vergessen?
    <a href="{{ rawUrl('frontend.nova_versandkosten.beipack.link', { deepLinkCode: order.deepLinkCode }, salesChannel.domains|first.url) }}">Jetzt zu dieser Bestellung beipacken</a>
</p>
{% endif %}
```

   Für die Textversion:
   `Noch etwas vergessen? {{ rawUrl('frontend.nova_versandkosten.beipack.link', { deepLinkCode: order.deepLinkCode }, salesChannel.domains|first.url) }}`

   Der Link merkt sich die Bestellung im Warenkorb und wählt die Versandart vor. Derselbe Link
   erscheint automatisch auf der Seite „Vielen Dank für Ihre Bestellung“, sobald die Versandart aktiv
   und zugeordnet ist.

Die Versandart erscheint in der Kasse nur, wenn es etwas zum Beipacken gibt (gewählte gültige
Bestellung, eigene offene Bestellungen oder – für Gäste – das Formular).

## Versandkosten auf Anfrage

Für Fälle ohne festen Preis – Spedition ohne Cargoboard-Preis, Länder ohne Tarif, Sonderteile – gibt
es die Versandart **„Versandkosten auf Anfrage“** (`nova_versandkosten_anfrage`). Die Bestellung wird
mit Versandkosten 0,00 € angelegt, die Zahlung bleibt offen.

**Wann wird sie angeboten?** (Einstellungen „Versandkosten auf Anfrage“, je Verkaufskanal)

| Einstellung | Standard |
|---|---|
| wenn Spedition nötig ist, aber keinen Preis hat | an |
| zusätzlich zu Spedition mit Preis („Versandkosten erscheinen Ihnen zu hoch? Wir prüfen gern eine günstigere Lösung (z. B. eigene Tour).“) | an |
| für Länder ohne Tarif | an |
| für Artikel mit Einordnung „Nur auf Anfrage“ | an |
| immer | aus |

**In der Kasse** steht deutlich: „Die Versandkosten werden nachberechnet. Ihre Bestellung wird erst
verbindlich, wenn Sie den Gesamtbetrag bezahlen.“ – ebenso auf der Abschlussseite.

**Zahlarten:** Mit „auf Anfrage“ sind in der Kasse nur Zahlarten wählbar, die beim Bestellen **kein
Geld einziehen** – Einstellung „Zahlarten in der Kasse“, leer = alle Vorkasse-Zahlarten (Shopware-
Handler „Vorkasse“). Online-Zahlarten (PayPal, Kreditkarte …) gehören nicht in diese Liste: Sie würden
beim Bestellen sofort den Betrag ohne Versand abbuchen. Bezahlt wird später so, wie Shopware 6.7
„Zahlung nachholen“ löst:

- **Kunden mit Konto:** *Mein Konto → Bestellungen → Bestellung → Zahlungsart ändern*
  (`/account/order/edit/<Bestell-ID>`). Angeboten werden alle Zahlarten, bei denen in *Einstellungen →
  Zahlungsarten* „Nach Bestellabschluss änderbar“ aktiv ist, solange die Zahlung offen ist – also auch
  PayPal oder Karte, dann mit dem neuen Gesamtbetrag.
- **Gäste:** Bestellseite mit Shopwares Bestell-Code (`/account/order/<deepLinkCode>`), Anmeldung mit
  E-Mail-Adresse und PLZ der Rechnungsadresse, danach derselbe Weg.

**Ablauf für den Shop:**

1. Der Shop bekommt eine E-Mail „Versandkosten eintragen für Bestellung &lt;Nr&gt;“ (Empfänger einstellbar,
   leer = Shop-E-Mail) mit Link in die Administration.
2. In der Bestellung (*Details*) die Karte **„Versandkosten auf Anfrage“**: Versandkosten (brutto; bei
   steuerfreien Bestellungen wie die Bestellung) eintragen → **„Versandkosten übernehmen und Kunde
   benachrichtigen“**. Das Plugin geht den Shopware-Weg wie „Bestellung bearbeiten“: Arbeitskopie
   (Version) anlegen, Versandkosten als manuellen Preis setzen, Bestellung nachberechnen (Steuern aus
   den Positionen), Kopie übernehmen. Der Betrag der offenen Zahlung wird angepasst.
3. Der Kunde bekommt die E-Mail „Nova Versandkosten: Versandkosten nachberechnet“ mit Gesamtbetrag und
   Bezahl-Link (anpassbar unter *Einstellungen → E-Mail-Vorlagen*). Die Karte zeigt den Link ebenfalls
   und kann die E-Mail erneut senden.
4. Ohne Zahlung storniert die geplante Aufgabe `nova_versandkosten.request_cancel` die Bestellung nach
   X Tagen (Einstellung, Standard 14, 0 = aus) – Bestellung, offene Zahlung und Lieferung.

**Hinweis in der Bestellbestätigung (empfohlen):** in die Vorlage „Bestellbestätigung“ einfügen:

```twig
{% if order.customFields.nova_versandkosten_anfrage|default(false) %}
<p><strong>Die Versandkosten werden nachberechnet. Ihre Bestellung wird erst verbindlich, wenn Sie den Gesamtbetrag bezahlen.</strong>
Wir melden uns mit den Versandkosten und einem Link zum Bezahlen.</p>
{% endif %}
```

**AGB:** Eine Bestellung mit „Versandkosten auf Anfrage“ ist rechtlich zunächst nur eine Anfrage; der
Vertrag kommt erst mit der Zahlung des mitgeteilten Gesamtbetrags zustande. Das sollte in den AGB
stehen. Wer *Nova Rechtstexte* (ab 1.8.4) nutzt, bekommt dafür den Baustein `[[wenn versandanfrage]]`
– er wird automatisch aktiv, wenn Nova Versandkosten installiert und die Versandart aktiv ist. Keine
Rechtsberatung – im Zweifel anwaltlich prüfen lassen.

## Eigene Touren

Wer selbst ausliefert (eigener Transporter, mit oder ohne Hänger), bietet Kunden **entlang der
Strecke** die Versandart **„Lieferung mit unserer Tour“** (`nova_versandkosten_tour`) an. Die Strecke
selbst sieht der Kunde nie – nur den Termin und den Preis.

### Begriffe

| Begriff | Bedeutung |
|---|---|
| **Strecke** | Vorlage für regelmäßige Fahrten: Name, Start, Ziel und eine oder mehrere **Varianten** |
| **Variante** | ein Weg zum Ziel mit geordneten **Wegpunkten** (Eingabe als PLZ oder Ort → Koordinate). Beispiel: Strecke „Musterstadt – Beispielhausen“, Variante A „über Nordheim“, Variante B „über Südhausen“ |
| **Tourtermin** | Datum (typisch Fr/Sa/So, frei wählbar), Strecke **oder** eigene Wegpunkte (einmalige Fahrt), Bestellschluss (Standard 2 Tage vorher, letzter Bestelltag inklusive), Schalter **„mit Hänger“**, Status |
| **Status** | **offen** = noch keine Buchung, alle Varianten zählen · **festgelegt** = Variante steht fest, nur noch Kunden an dieser Variante · **geschlossen** = keine Buchung mehr |
| **Abweichung** | Luftlinie vom PLZ-Mittelpunkt der Lieferadresse zur Linie der Variante (Polylinie der Wegpunkte) × **Umwegfaktor** (Standard 1,3), in km |

**Die erste Buchung legt die Variante fest** – und zwar die mit der geringsten Abweichung für diesen
Kunden. Damit werden Kunden links und rechts verschiedener Wege nicht beide „billig“. Im Admin lässt
sich die Variante jederzeit festlegen oder ändern (z. B. vor der ersten Buchung).

### Preise und Regeln (je Verkaufskanal, Einstellungen „Eigene Touren“)

| Einordnung im Warenkorb | Standard |
|---|---|
| Kleinteile, Paketware, Sperrgut | **50 €** bis **10 km** Abweichung |
| Speditionsware (nur bei Terminen **mit Hänger**) | **190 €** bis 30 km, **290 €** bis 60 km |
| darüber | keine Tour |

- Die **höchste Einordnung** im Warenkorb bestimmt den Preis (ein Speditionsartikel → Speditionspreis).
- Artikel „Nur Abholung“ oder „Nur auf Anfrage“ schließen die Tour aus.
- Nur **Inlandsadressen** (Lieferländer einstellbar, Standard DE; PLZ-Koordinaten sind für DE enthalten).
- Preise brutto; Steuer wie bei den anderen Versandarten. Die Tour braucht **keine** Tarifdatei.
- Beispiel (Platzhalter): Lieferadresse 8 km neben der Strecke → Abweichung 8 × 1,3 = 10,4 km → für
  Kleinteile zu weit, für Speditionsware bei Termin mit Hänger 190 €.

### Preisprofile (wer fährt)

Der Tourpreis kann davon abhängen, **wer fährt** – z. B. darf eine weite Speditionsfahrt günstiger
sein, wenn der Inhaber selbst fährt, als wenn ein Mitarbeiter fährt. Dafür gibt es **Preisprofile**
unter *Bestellungen → Versandkosten → Touren → Preisprofile*:

| Feld | Bedeutung |
|---|---|
| **Name** | nur intern, z. B. „Profil A“ / „Profil B“ – **wird Kunden nie angezeigt** |
| **Preis Kleinteile/Paket/Sperrgut** + **bis Abweichung (km)** | wie in den Einstellungen, z. B. 50 € bis 10 km |
| **Speditionsstufen** (nur mit Hänger) | „bis km = Preis“ je Zeile, z. B. `30=190` und `60=290`; leer = mit diesem Profil keine Speditionsware |
| **Reihenfolge** | Sortierung in der Auswahl |

Beispiel (Platzhalter): **Profil A** – Spedition `60=190` (190 € bis 60 km); **Profil B** – Spedition
`30=190` und `60=290`. Eine Speditionsware 45 km neben der Strecke kostet mit Profil A 190 €, mit
Profil B 290 €.

- **Am Tourtermin** wählen Sie das Preisprofil; ohne Auswahl gilt das **Standardprofil** = die Werte
  aus den Einstellungen „Eigene Touren“ (je Verkaufskanal). Ohne eigene Profile ändert sich also
  nichts gegenüber 1.2.0.
- Das Profil des Termins gilt überall: Preis und Verfügbarkeit in der Kasse, erneute Prüfung beim
  Bestellen, Reichweite der Tour-Benachrichtigung (Interessenten im Korridor = bis zur größten
  Stufe des Profils), Markierung „außerhalb des Preisrahmens“ in den Tour-Lieferungen und der
  Hinweis an „Versandkosten auf Anfrage“. Für Strecken ohne Termin (Hinweis, Abonnenten-Filter) zählt
  die größte Reichweite aller Profile.
- Umwegfaktor und Lieferländer bleiben die der Einstellungen (je Verkaufskanal); Profile gelten für
  alle Verkaufskanäle. Preise brutto.
- Wird ein Profil gelöscht, fallen seine Termine auf das Standardprofil zurück. Bereits bestellte
  Touren behalten ihren Preis.

### Einrichtung

1. *Einstellungen → Versand* → „Lieferung mit unserer Tour“ aktivieren und dem Verkaufskanal zuordnen
   (Platzhalterpreis 0,00 € bleibt; den Preis setzt das Plugin).
2. *Bestellungen → Versandkosten → Touren → Einstellungen* (oder Plugin-Konfiguration → „Eigene
   Touren“): Preise, Grenzen, Umwegfaktor, Lieferländer, Standard-Bestellschluss, Hinweis in der Kasse.
3. *Bestellungen → Versandkosten → Touren → Strecken → Neue Strecke*: Name, Start, Ziel; je Variante die Wegpunkte in
   Fahrtreihenfolge über „PLZ oder Ort“ suchen und übernehmen (bei Orten mit mehreren PLZ gibt es den
   Mittelpunkt aller PLZ). Reihenfolge mit ↑/↓, Bezeichnung frei änderbar.
4. *Touren → Tourtermine → Neuer Tourtermin*: Strecke wählen (oder „eigene Wegpunkte“ für eine einmalige
   Fahrt), Datum, ggf. Bestellschluss, „mit Hänger“. Status „offen“ lassen. Einmalige Fahrten lassen
   sich mit „Als Strecken-Vorlage speichern“ für später sichern. Optional: **Preisprofil** wählen
   (wer fährt, siehe oben).
5. Rechte: *Einstellungen → Benutzer & Rechte → Rollen → Bestellungen → Touren* (ansehen, bearbeiten,
   anlegen, löschen). Für „Tour-Lieferungen“ braucht die Rolle zusätzlich Bestellungen lesen.
   Preisprofile: ansehen mit „ansehen“, anlegen/ändern mit „bearbeiten“, löschen mit „löschen“
   (bestehende Rollen werden beim Update auf 1.2.1 ergänzt).

### In der Kasse

- Die Versandart erscheint nur, wenn **mindestens ein offener/festgelegter Termin passt**:
  Bestellschluss nicht vorbei, Abweichung im Rahmen, Hänger bei Speditionsware, Lieferland erlaubt,
  PLZ bekannt. Angezeigt werden Preis („ab …“ bei mehreren Terminen mit unterschiedlichem Preis) und
  der nächste Termin.
- Auf der Bestätigungsseite wählt der Kunde **den Termin (Pflicht)** – „Sa, 14.11.2026 – 50,00 €“ –
  dazu der Hinweis (einstellbar). **Keine Strecke, keine Orte.**
- Beim Bestellen prüft das Plugin alles erneut: Ist der Termin inzwischen geschlossen, der
  Bestellschluss vorbei oder die Variante anders festgelegt (die Adresse liegt nicht daran), kann
  nicht bestellt werden („Der gewählte Tourtermin ist nicht mehr verfügbar …“).
- Store-API: `GET /store-api/nova-versandkosten/tour` (passende Termine), `POST
  /store-api/nova-versandkosten/tour/select` mit `{"tourDateId": "…"}`.

### An der Bestellung

- Bestellkommentar beginnt mit **„TOUR: Sa, 14.11.2026“** (ein Kundenkommentar bleibt dahinter).
- Zusatzfelder: `nova_versandkosten_tour_termin` (Termin-ID), `nova_versandkosten_tour_datum`,
  `nova_versandkosten_tour_variante` (+ `_name`), `nova_versandkosten_tour_abweichung_km`,
  `nova_versandkosten_tour_einordnung` (`klein`/`spedition`); `nova_versandkosten_option` = `tour`.
- Die erste Buchung setzt den Termin auf „festgelegt“ mit ihrer Variante. Haben zwei Kunden
  gleichzeitig an verschiedenen Varianten bestellt, bekommt die spätere Bestellung einen internen
  Kommentar zur Prüfung.
- Karte „Versandkosten“ in der Bestellung: Termin, Variante, Abweichung, Link zum Termin.

### Tour-Lieferungen (Beladen)

Im Tourtermin und im Unterreiter *Touren → Tour-Lieferungen* (Termin auswählen) listet
**„Tour-Lieferungen“** alle Bestellungen des Termins: Bestellnummer (Link),
Kunde, Straße, PLZ/Ort, Position entlang der Strecke (km ab Start), Abweichung (bei Bestellung und
mit der aktuellen Variante), Einordnung, Status von Bestellung/Lieferung/Zahlung. Sortiert **entlang
der Strecke** – die zuletzt angefahrenen zuerst laden. Liegt eine Bestellung nach einer
Varianten-Änderung außerhalb des Preisrahmens, ist sie rot markiert.

### Hinweis bei „Versandkosten auf Anfrage“

An Bestellungen „Versandkosten auf Anfrage“ zeigt die Karte in der Administration, ob die
Lieferadresse **an einer aktiven Strecke oder einem buchbaren Tourtermin** liegt („Strecke … (Variante):
12,3 km“) – Reichweite bis zur größten Speditionsstufe (Standard 60 km).

## Tour-Benachrichtigung

Kunden und Interessenten werden informiert, wenn ein Tourtermin in ihre Nähe fährt – die Mail nennt
nur **Datum und „in Ihrer Nähe (PLZ-Gebiet 12…)“**, nie die Strecke. Einschalten: Einstellungen
„Tour-Benachrichtigung“.

- **Kundenkonto:** *Mein Konto → Tour-Benachrichtigung* – Schalter je Lieferadresse („Benachrichtigen,
  wenn eine Tour in die Nähe fährt“). Der Kunde ist angemeldet, deshalb ohne Double-Opt-in; gesendet
  wird an die aktuelle E-Mail des Kontos. Store-API: `GET/POST /store-api/nova-versandkosten/tour-notification`
  (`{"addressId": "…", "subscribe": true}`).
- **Gäste/Besucher:** Erlebniswelten-Element bzw. -Block **„Tour-Benachrichtigung“** (Kategorie
  Formular, z. B. auf der Versandseite) oder eigener Einbau, z. B. auf Produktseiten von Speditionsware:
  `{% sw_include '@Storefront/storefront/nova-versandkosten/tour-notify-form.html.twig' with { formId: 'produkt' } %}`.
  E-Mail + PLZ + Einwilligung (Pflicht-Checkbox mit Link zur Datenschutzerklärung), **Double-Opt-in**
  per Mail (Link 7 Tage gültig), Shopware-Captchas (dasselbe Route-Attribut wie das Kontaktformular,
  damit z. B. Nova Captcha greift), Honeypot, Herkunftsprüfung, RateLimiter (3 je Stunde und IP,
  100 je Tag und Kanal). Die Antwort verrät nicht, ob eine Adresse schon angemeldet ist.
- **Dauer:** aktiv bis zur Abmeldung (Link in jeder Mail, signiert). Automatisch gelöscht werden
  unbestätigte Anmeldungen nach 7 Tagen und aktive Abonnenten nach **24 Monaten ohne Aktivität**
  (einstellbar, 0 = nie). Aktivität = Bestätigung, Umschalten im Konto oder „Weiter benachrichtigen“
  in einer Mail. Geplante Aufgabe `nova_versandkosten.tour_cleanup` (täglich).
- **Administration:** *Bestellungen → Versandkosten → Touren → Benachrichtigung*: alle Abonnenten (Kunde/Gast,
  E-Mail, PLZ/Ort, seit, Quelle, bestätigt, Anzahl Mails), Filter nach Strecke und Umkreis, Suche,
  **CSV-Export**, Löschen. Im Tourtermin: **„X Interessenten im Korridor“** (bei offenem Termin alle
  Varianten; Reichweite mit Hänger bis zur größten Speditionsstufe, sonst bis zur Grenze für
  Kleinteile) und Knopf **„Benachrichtigung senden“** – nie automatisch, je Termin und Abonnent höchstens
  eine Mail.
- Mail-Vorlagen (anpassbar unter *Einstellungen → E-Mail-Vorlagen*): „Tour-Benachrichtigung
  bestätigen“ (Variablen `novaTour.confirmUrl`, `.postcode`, `.days`) und „Tour in Ihrer Nähe“
  (`novaTour.date`, `.dateShort`, `.area`, `.deadline`, `.trailer`, `.shopUrl`, `.renewUrl`,
  `.unsubscribeUrl`).
- **Datenschutzerklärung ergänzen:** *Nova Rechtstexte* (ab 1.8.5) hat den Baustein
  `[[wenn tourbenachrichtigung]]` und erkennt ihn automatisch, wenn das Plugin aktiv und die
  Tour-Benachrichtigung eingeschaltet ist. Keine Rechtsberatung.

## PLZ-Koordinaten (Herkunft und Lizenz)

Die Abweichung wird ohne externen Dienst berechnet. Dafür liefert das Plugin die Datei
`src/Resources/geo/postcodes-de.tsv.gz` mit (deutsche Postleitzahlen mit Ort, Bundesland und
Koordinate, ca. 290 kB). Quelle: **GeoNames** Postal Codes, Datei `DE.zip` von
[download.geonames.org/export/zip](https://download.geonames.org/export/zip/), Stand 30.10.2026,
Lizenz **Creative Commons Attribution 4.0** ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)) –
Weitergabe und Bearbeitung mit Namensnennung erlaubt, keine Weitergabe-unter-gleichen-Bedingungen-Pflicht.
Bearbeitung: Großkunden-Einträge (Firmennamen statt Ort) als Ortsnamen entfernt, Koordinaten auf
4 Nachkommastellen gerundet (`scripts/plz-aufbereiten.php DE.txt …`). Namensnennung auch in
[NOTICE](NOTICE). Die Koordinaten sind Schätzwerte (Mittelpunkte), keine Adressen.

Aktualisieren: `DE.zip` laden, entpacken, `php scripts/plz-aufbereiten.php DE.txt
src/Resources/geo/postcodes-de.tsv.gz` – beim nächsten Update lädt das Plugin die geänderte Datei.

## Energiezuschläge automatisch

Die geplante Aufgabe `nova_versandkosten.energy_fetch` (täglich) und der Knopf *Bestellungen →
Versandkosten → Zuschläge & Energie → Jetzt abrufen* lesen die öffentlichen Tabellen:

| Dienstleister | Quelle | Wert |
|---|---|---|
| DHL | [dhl.de/energiezuschlag](https://www.dhl.de/energiezuschlag) | Tabelle „Zuschlagshöhe ab 1./11./21. des Monats“ = langfristige + kurzfristige Komponente (fehlt sie, werden beide Tabellen addiert) |
| DPD | [dpd.de/energiezuschlag](https://www.dpd.de/energiezuschlag) | „Energiezuschlag Straße“ ab Gültigkeitsdatum |
| DPD | [Emergency Fuel Surcharge](https://www.dpd.com/de/de/support/versandbestimmungen/emergency-fuel-surcharge/) | monatlicher Zuschlag seit Mai 2026, wird zur Straße addiert (abschaltbar) |

- Gespeichert werden die ganzen Tabellen mit „gültig ab“; gerechnet wird mit dem **am Tag gültigen**
  Wert – der neue Monatswert gilt also pünktlich, auch wenn ein Abruf ausfällt.
- **Plausibilitätsprüfung** (0–60 %). Bei Fehlern (Seite nicht erreichbar, geändert, unplausibel)
  bleibt der letzte Wert, es gibt eine Benachrichtigung in der Administration und optional eine
  E-Mail.
- Je Dienstleister „automatisch“ oder „manuell“ (Reiter *Zuschläge & Energie*); manuell gilt der Wert
  aus dem Feld „Energiezuschlag (%) je Dienstleister“. Bis zum ersten erfolgreichen Abruf gilt
  ebenfalls der manuelle Wert. Die Schlüssel `dhl`/`dpd` sind einstellbar (falls die Tarifdatei andere
  Dienstleister-Schlüssel nutzt).
- Der Reiter *Zuschläge & Energie* zeigt je Quelle den heute gültigen Wert, die Bestandteile, Abrufzeit,
  Quelle und den letzten Fehler.

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
| `nova_versandkosten_details` | Warenwert, Modus, Hinweise, Cargoboard-Angebots-ID, bei Beipack Grundlage (zusammen/bezahlt) |
| `nova_versandkosten_beipack_zu`, `nova_versandkosten_beipack_zu_nr` | Beipack: ID/Nummer der Bestellung, zu der beigepackt wird |
| `nova_versandkosten_beipack_bestellungen` | an der alten Bestellung: Liste `{id, orderNumber, date}` der Beipack-Bestellungen |
| `nova_versandkosten_anfrage`, `nova_versandkosten_anfrage_status`, `nova_versandkosten_anfrage_preis_am` | Versandkosten auf Anfrage: Kennzeichen, `open`/`priced`, Zeitpunkt |
| `nova_versandkosten_tour_termin`, `nova_versandkosten_tour_datum` | Eigene Tour: Termin-ID, Datum (`2026-11-14`) |
| `nova_versandkosten_tour_variante`, `nova_versandkosten_tour_variante_name` | Variante (ID bzw. `custom` bei eigenen Wegpunkten) und Name |
| `nova_versandkosten_tour_abweichung_km`, `nova_versandkosten_tour_einordnung` | Abweichung in km (inkl. Umwegfaktor), `klein`/`spedition` |

Bei Beipack-Bestellungen steht in `nova_versandkosten_option` die Option, nach der gerechnet wurde
(z. B. `dpd`), und in `nova_versandkosten_sale` die Nachzahlung.

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
- Der Abruf der Energiezuschläge ruft nur die öffentlichen Seiten von DHL (dhl.de) und DPD (dpd.de,
  dpd.com) ab – ohne Kunden- oder Shopdaten.
- Beipacken: Gespeichert wird nur die ID der gewählten Bestellung am Warenkorb; für die
  Versuchsbegrenzung ein Hash der IP-Adresse (höchstens eine Stunde im Cache).
- Eigene Touren: Die Abweichung wird im Shop aus der mitgelieferten PLZ-Datei berechnet – **kein
  externer Dienst**, keine Übertragung von Adressen. Strecken und Termine sieht der Kunde nicht.
- Tour-Benachrichtigung: gespeichert werden E-Mail-Adresse, PLZ (mit Ort und PLZ-Mittelpunkt),
  bei Kunden der Bezug zu Konto und Adresse, Quelle, Zeitpunkte (Anmeldung, Bestätigung, letzte
  Aktivität), Sprache, Verkaufskanal und welche Termin-Mails versendet wurden. Der Bestätigungs-Token
  liegt nur als Hash vor; für die Mengenbegrenzung ein Hash der IP (Cache). Rechtsgrundlage
  Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), Widerruf jederzeit über den Abmelde-Link oder im Konto.

## Geplant

- Eigene Preisliste mit Volumengewicht (z. B. für einen Kanal mit Lieferanten-Preisliste)
- Express-/Zeitzustellung, Paketshop-Zustellung, Weltweit-Tarife mit Volumengewicht
- Variabler Transportkostenzuschlag International je kg
- Kontaktformular statt E-Mail für die Tour-Anfrage; Versandseite (Tabellen) als Erlebniswelt-Element
- Lieferzeiten an Google (Merchant Center) übertragen; Monats-Liefertermine anderer Plugins einbeziehen
  (heute: Erscheinungsdatum des Produkts)
- Standard-Versandart je Land (heute: Empfehlung außerhalb der EU nur als Hinweis „empfohlen“)
- Cargoboard-Buchung und Etiketten (gehört in die Warenwirtschaft)
- Eigene Touren: **echter Umweg über einen Routendienst** (z. B. OpenRouteService – übertragen würden
  nur Koordinaten, keine Namen) statt Luftlinie × Umwegfaktor; dann zählen auch Kunden zwischen zwei
  Varianten richtig
- Eigene Touren: Termine im Rhythmus anlegen (z. B. jeden 2. Freitag), Zeitfenster je Stopp, Karte der
  Strecke im Admin, Tourplan als PDF; PLZ-Koordinaten weiterer Länder

## Entwicklung

```bash
php phpunit.phar                     # Unit-Tests des Rechenkerns (ohne Shopware)
php scripts/testfaelle.php           # Beispiel-Testfälle (Tabelle Soll/Ist)
NOVA_VK_TARIFE=/pfad/tarife.json NOVA_VK_FAELLE=/pfad/faelle.json php scripts/testfaelle.php
php scripts/tarif-konvertieren.php tarife.json tarife.csv
php scripts/plz-aufbereiten.php DE.txt src/Resources/geo/postcodes-de.tsv.gz   # GeoNames-PLZ aufbereiten
```

Die eigenen Tarife und Testfälle mit echten Preisen gehören **nicht** ins Repository – das Skript
nimmt die Pfade als Umgebungsvariablen.

Template-Blöcke: `nova_versandkosten_method_info` (in `component/shipping/shipping-method.html.twig`),
`nova_versandkosten_request` (`nova-versandkosten/request.html.twig`), `nova_versandkosten_beipack`
(`nova-versandkosten/beipack.html.twig`), `nova_versandkosten_tour` (`nova-versandkosten/tour.html.twig`,
Termin-Auswahl), `nova_versandkosten_tour_notify` (`nova-versandkosten/tour-notify-form.html.twig`),
`nova_versandkosten_account_tour` (Kundenkonto), `nova_versandkosten_finish` (Abschlussseite). Versandarten-Erweiterung
`shippingMethod.extensions.novaVersandkosten` (auch in der Store-API): `price`, `vatIncluded`,
`packageCount`, `delivery`, `hints`, `recommended`. Warenkorb-Erweiterung `novaVersandkosten`:
alle Optionen mit Paketen.

Die Administration liegt fertig gebaut unter `src/Resources/public/administration` (wird
eingecheckt). Nach Änderungen an `src/Resources/app/administration` neu bauen – innerhalb einer
Shopware-Installation mit `bin/build-administration.sh`, oder nur dieses Plugin mit dem
Administration-Quellcode von Shopware 6.7:

```bash
# PROJECT_ROOT enthält var/plugins.json (Eintrag „NovaVersandkosten“ mit basePath custom/plugins/NovaVersandkosten/src/)
# und custom/plugins/NovaVersandkosten (Kopie dieses Repositorys, mit examples/ – die Tarif-Vorlagen
# im Reiter „Tarife“ werden beim Bauen aus examples/ eingebunden)
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

### Warenpost/Kleinpaket erscheint bei kleinen Teilen nicht

**Ursache:** Artikel ohne Maße werden mit den Standardmaßen „Paket“ (30 × 20 × 15 cm) gerechnet –
die passen nicht in Warenpost/Kleinpaket. Oder Warenwert-/Gewichtsgrenze überschritten.

**Lösung:** *Bestellungen → Versandkosten → Artikeldaten* → „Kleinsendung ohne Maße“ auf
„Warenpost-fähig“ (bzw. „Kleinpaket-fähig“) und die Gewichtsgrenze passend setzen – oder beim Artikel
die verpackten Maße pflegen.

### Spedition zeigt „auf Anfrage“

**Ursache:** Kein Cargoboard-API-Schlüssel, Abhol-PLZ fehlt, Kunde hat noch keine Lieferadresse
(PLZ) oder Cargoboard antwortet nicht. Fehler stehen im Shopware-Log („Nova Versandkosten“).

**Lösung:** Schlüssel und Abhol-PLZ eintragen, bei Ausfällen einen Ersatzpreis setzen.

### Bestellung zeigt „Maße fehlen“

**Ursache:** Mindestens ein Artikel hatte kein Gewicht oder keine vollständigen Maße; gerechnet wurde
mit der Einordnung (Standardwerte). Das kostet nichts extra.

**Lösung:** Gewicht nachtragen; Maße nur bei Grenzfällen. Wer den Hinweis nicht sehen will: Einstellung
„Hinweis ‚Maße fehlen‘ ausgeben“ ausschalten (das Zusatzfeld bleibt).

### „Zu meiner Bestellung beipacken“ erscheint nicht

**Ursache:** Versandart inaktiv/nicht zugeordnet, keine offene Bestellung (versendet, storniert, älter
als die eingestellten Tage, anderes Lieferland), oder für Gäste ist das Formular ausgeschaltet.

**Lösung:** Versandart prüfen; Gäste nutzen den Link aus der Bestellbestätigung.

### „Lieferung mit unserer Tour“ erscheint nicht

**Ursache:** Versandart inaktiv/nicht zugeordnet, kein offener/festgelegter Termin mit Datum ab heute,
Bestellschluss vorbei, Lieferadresse zu weit von der Strecke (bzw. von der festgelegten Variante),
Speditionsware bei Termin ohne Hänger, Artikel „Nur Abholung“/„Nur auf Anfrage“ im Warenkorb,
Lieferland nicht freigegeben oder PLZ unbekannt.

**Lösung:** Im Tourtermin Status, Bestellschluss, Hänger und Variante prüfen; Abweichung schätzen
(Luftlinie zur Strecke × Umwegfaktor). Fehlen die PLZ-Koordinaten, zeigt *Bestellungen → Versandkosten → Touren* einen
Hinweis – Plugin aktualisieren oder neu aktivieren.

### Tour-Benachrichtigung: Formular erscheint nicht

**Ursache:** Einstellung „Tour-Benachrichtigung einschalten“ aus (für diesen Verkaufskanal).

**Lösung:** Einschalten; danach erscheinen Formular, Erlebniswelten-Element und der Eintrag im Kundenkonto.

### Bei „Versandkosten auf Anfrage“ ist keine Zahlart wählbar

**Ursache:** Es gibt im Verkaufskanal keine aktive Vorkasse-Zahlart, und in „Zahlarten in der Kasse“
ist nichts eingetragen.

**Lösung:** Vorkasse aktivieren und zuordnen oder die gewünschten Zahlarten eintragen.

### Energiezuschlag wird nicht abgerufen

**Ursache:** Geplante Aufgaben laufen nicht, der Server darf nicht ins Internet, oder die Seite von
DHL/DPD hat sich geändert (Benachrichtigung „Energiezuschlag konnte nicht abgerufen werden“).

**Lösung:** In *Bestellungen → Versandkosten → Übersicht* den Status der geplanten Aufgaben ansehen,
Scheduled-Task-Runner prüfen, *Jetzt abrufen* testen; bis zur Klärung „manuell“ wählen und
den Wert eintragen. Bei geänderter Seite bitte ein Issue anlegen.

## Lizenz

MIT – siehe [LICENSE](LICENSE). Die mitgelieferten PLZ-Koordinaten stammen von GeoNames
(CC BY 4.0) – siehe [NOTICE](NOTICE).
