# 1.1.0

- Neu: **Zur offenen Bestellung beipacken** – Versandart „Zu meiner Bestellung beipacken“ (inaktiv angelegt). Kunden wählen eine eigene, noch nicht versendete Bestellung; Gäste über den Link aus der Bestellbestätigung (Shopwares Bestell-Code) oder Bestellnummer + E-Mail + PLZ (höchstens 5 Versuche je Stunde und IP). Preis = Versand für alte + neue Artikel minus bereits bezahlter Versand, mindestens 0,00 €. Prüfung erneut beim Abschluss, Lieferadresse der alten Bestellung, Kommentar „BEIPACK zu Bestellung <Nr>“, Verweise an beiden Bestellungen, Hinweis im internen Kommentar der alten Bestellung, Link „Noch etwas vergessen?“ auf der Abschlussseite
- Neu: **Versandkosten auf Anfrage** – Versandart (inaktiv angelegt) für Spedition ohne Preis, zusätzlich zu Spedition mit Preis, Länder ohne Tarif, Artikel „nur auf Anfrage“ oder immer. Bestellung mit 0,00 € Versand, deutlicher Hinweis in Kasse und Abschlussseite, nur Zahlarten ohne sofortigen Geldeinzug (einstellbar, Standard Vorkasse), E-Mail an den Shop, Karte in der Bestellung zum Eintragen der Versandkosten (Shopware-Nachberechnung, Steuern und offene Zahlung werden angepasst), E-Mail-Vorlage für den Kunden mit Gesamtbetrag und Bezahl-Link, automatische Stornierung unbezahlter Anfragen nach X Tagen
- Neu: **Energiezuschläge automatisch** von DHL (lang- + kurzfristige Komponente) und DPD (Straße + Emergency Fuel Surcharge) – täglich und per Knopf „Jetzt abrufen“, Plausibilitätsprüfung 0–60 %, bei Fehlern bleibt der letzte Wert (Benachrichtigung, optional E-Mail), automatisch/manuell je Dienstleister, Anzeige von Wert, Quelle und Abrufzeit
- Neu: Versandeinordnung „Nur auf Anfrage“ (Zusatzfeld und Eigenschaftswerte), Google-Label `anfrage`
- Neu: Einstellung „Hinweis ‚Maße fehlen‘ ausgeben“ (Standard an, nur intern); fehlende Maße kosten weiterhin nichts extra – es gilt die Einordnung
- Beim Update werden fehlende Zusatzfelder und Auswahlwerte ergänzt; vorhandene Einstellungen bleiben
- Nachberechnen einer Bestellung in der Administration überschreibt die gespeicherten Versanddaten nicht mehr

# 1.0.0

- Erste Version: Versandkosten aus importierten Einkaufstarifen (JSON oder CSV) berechnen
- Rechenkern ohne Shopware-Abhängigkeit: Paketbildung, Warenpost/Kleinpaket/DPD/DHL, Sperrgut/Übermaß/Übergröße, Maut, Energie, Peak und Peak-in-Peak, Insel-, Zoll- und Währungszuschläge, Ausland (außerhalb der EU ohne MwSt.), versandkostenfrei für Hersteller/Tags
- Sechs vom Plugin angelegte (inaktive) Versandarten; unpassende Versandarten werden in der Kasse ausgeblendet
- Spedition über die Cargoboard-API mit Zwischenspeicher und Ersatzpreis; ohne Schlüssel „auf Anfrage“
- Kasse: Preis, voraussichtliches Zustelldatum (Feiertage je Bundesland), Hinweise, Empfehlung, Knopf „Lieferung per Tour anfragen“
- Bestellung: Ergebnis als Zusatzfelder, Karte „Versandkosten“ mit Einkauf/Verkauf/Differenz, optional E-Mail bei negativer Marge
- Administration: Tarifimport/-export und Preisprüfung je Verkaufskanal
- Produktexport: Twig-Funktion `nova_versand_label()` für das Google-Versandlabel
