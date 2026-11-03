# 1.3.1

- Neu: **Günstigste mögliche Versandart vorwählen** (Reiter Preise, je Verkaufskanal, Standard an): Warenkorb, Offcanvas-Warenkorb und Kasse wählen automatisch die günstigste mögliche Paket-Versandart des Plugins (Warenpost, Kleinpaket, DPD, DHL, Spedition) statt fest der Standard-Versandart des Kanals. Gleicher Preis: die bisherige bleibt. Wählt der Kunde selbst eine Versandart, bleibt sie (Merkzeichen in der Sitzung, Länderwechsel zählt nicht als eigene Wahl); nach dem Bestellabschluss wird wieder vorgewählt
- Behoben: Eine nicht mehr mögliche Versandart (z. B. Warenpost nach Mengenerhöhung, Paket bei Speditionsware, Land außerhalb der Freigabe) blieb im Warenkorb mit „gesperrt“ und 0,00 € stehen, wenn Shopwares eigener Wechsel an einer alten Versandart scheiterte. Jetzt wird sofort auf die günstigste mögliche Plugin-Versandart gewechselt – gibt es keine, auf „Versandkosten auf Anfrage“ – mit Shopwares Hinweis „Versandart wurde geändert“. Fremde Versandarten (Abholung, Termine …) bleiben unberührt, solange sie möglich sind; Beipacken und Tour werden nur bei Sperre gewechselt

# 1.3.0

- Neu: **ein Modul für alles** – *Bestellungen → Versandkosten* (zusätzlich Kachel unter *Einstellungen → Versandkosten*) mit Reitern Übersicht · Preise · Zuschläge & Energie · Länder & Lieferzeit · Artikeldaten · Spedition · Anfrage & Beipacken · Touren (Tourtermine, Strecken, Preisprofile, Tour-Lieferungen, Benachrichtigung, Einstellungen) · Tarife · Preisprüfung. Ersetzt die Menüeinträge *Erweiterungen → Nova Versandkosten* und *Bestellungen → Touren*; alte Lesezeichen leiten weiter
- Neu: **Einstellungen im Modul** – je Reiter die passenden Karten der Plugin-Konfiguration mit Verkaufskanal-Umschalter und Vererbung („Alle Verkaufskanäle“, Kettensymbol). Gleiche Werte wie in der Plugin-Konfiguration (`NovaVersandkosten.config`), keine Migration; die Plugin-Konfiguration zeigt oben einen Link ins Modul. Karten in `config.xml` haben jetzt Namen; „Zuschläge“ ist aufgeteilt in „Peak-Zuschläge“ und „Energiezuschläge“, „Anfrage und Kontrolle“ in „Tour-Anfrage in der Kasse“ und „Kontrolle“, „DPD außerhalb der EU“ steht bei den Ländern – Feldnamen unverändert
- Neu: **Übersicht** – Tarife importiert?, Energiezuschläge aktuell?, Warnungen der Preisprüfung, aktive Nova-Versandarten je Verkaufskanal, geplante Aufgaben (Status, letzter/nächster Lauf), kommende Tourtermine
- Neu: Unterreiter **Tour-Lieferungen** (Termin wählen, Lade-Reihenfolge) direkt in den Touren
- Neu: **Tarife hochladen** per Datei wählen oder Ziehen & Ablegen (.csv/.json), sofortige Prüfung; CSV mit Semikolon in UTF-8 (mit/ohne BOM) oder Windows-1252 (Excel „CSV (Trennzeichen-getrennt)“). Bisher wurde die Datei immer als UTF-8 gelesen (Umlaute aus Excel-CSV kaputt), es gab keine Rückmeldung nach der Auswahl und dieselbe Datei ließ sich nicht erneut wählen
- Neu: **Vorlagen** im Reiter Tarife – Beispieldateien CSV/JSON (Platzhalterpreise), leere CSV-Vorlage mit je einer kommentierten Beispielzeile pro Zeilentyp (`#` = Kommentar), aufklappbare Spaltenerklärung, gut sichtbarer Export „Aktuelle Tarife exportieren (CSV)“ zum Bearbeiten in Excel
- Neu: **Kleinsendung ohne Maße** (je Verkaufskanal, Standard „Warenpost-fähig bis 1 kg“): Haben alle Artikel die Einordnung Paket und keine Maße, prüfen Warenpost/Kleinpaket nur Gewicht, Warenwert und „ein Paket“ statt der Standardmaße 30 × 20 × 15 cm (die nie passten). Artikel mit Maßen werden weiter exakt geprüft
- Neu: **Festpreis mit Gewichtsgrenze** – je Festpreis „gilt bis X kg“ (leer = immer); schwerere Pakete kosten Einkauf brutto oder Einkauf brutto + Aufschlag (Einstellung). Die Preisprüfung rechnet genauso und zeigt „Einkauf brutto (über X kg)“
- Rechte unverändert (Einstellungen bzw. Rolle „Touren“); der Menüeintrag erscheint mit einem der beiden Leserechte

# 1.2.1

- Neu: **Preisprofile für Touren** – der Tourpreis kann davon abhängen, wer fährt. Unter *Bestellungen → Touren → Preisprofile* je Profil Name (nur intern), Preis Kleinteile/Paket/Sperrgut mit Höchstabweichung und Speditionsstufen (z. B. `30=190`, `60=290`; leer = keine Speditionsware). Am Tourtermin wird das Profil gewählt; Kasse, Prüfung beim Bestellen, Reichweite der Tour-Benachrichtigung, Tour-Lieferungen und Hinweis an „Versandkosten auf Anfrage“ nutzen das Profil des Termins. Der Profilname erscheint nie beim Kunden
- Die bisherigen Einstellungen „Eigene Touren“ bleiben das **Standardprofil** – ohne eigene Profile ändert sich nichts
- Neue Tabelle `nova_vk_tour_price_profile` und Spalte `price_profile_id` am Tourtermin (gelöschtes Profil → Standardprofil); bestehende Rollen „Touren“ erhalten die Rechte für Preisprofile; Deinstallieren ohne „Daten behalten“ entfernt auch die neue Tabelle

# 1.2.0

- Neu: **Eigene Touren** – Strecken (Vorlagen) mit einer oder mehreren Varianten und geordneten Wegpunkten (Eingabe als PLZ oder Ort), Tourtermine mit Strecke oder eigenen Wegpunkten (einmalige Fahrt, optional als Vorlage speichern), Datum, Bestellschluss (Standard 2 Tage vorher), „mit Hänger“, Status offen/festgelegt/geschlossen. Neue Administration *Bestellungen → Touren* mit Rollenrechten
- Neu: Versandart **„Lieferung mit unserer Tour“** (`nova_versandkosten_tour`, inaktiv angelegt) – nur wenn ein offener/festgelegter Termin passt (Bestellschluss, Abweichung, Hänger bei Speditionsware, Inland). Abweichung = Luftlinie der Liefer-PLZ zur Linie der Variante × Umwegfaktor (Standard 1,3). Preise je Verkaufskanal (Standard Kleinteile/Paket/Sperrgut 50 € bis 10 km, Speditionsware nur mit Hänger 190 € bis 30 km, 290 € bis 60 km), höchste Einordnung im Warenkorb zählt. Offener Termin: alle Varianten zählen, **die erste Buchung legt die Variante fest**
- Neu: Kasse mit Pflichtauswahl des Termins (nur Datum und Preis, keine Strecke), erneute Prüfung beim Bestellen, Store-API-Routen; Bestellkommentar „TOUR: <Wochentag, Datum>“, Zusatzfelder (Termin, Variante, Abweichung, Einordnung), Liste **„Tour-Lieferungen“** je Termin entlang der Strecke sortiert zum Beladen
- Neu: Hinweis an Bestellungen „Versandkosten auf Anfrage“, wenn die Lieferadresse an einer Strecke bzw. einem buchbaren Tourtermin liegt
- Neu: **Tour-Benachrichtigung** – Schalter je Lieferadresse im Kundenkonto (auch Store-API), Formular für Gäste als Erlebniswelten-Element/-Block mit Double-Opt-in, Shopware-Captcha, Honeypot und RateLimiter; Abmeldung über signierten Link in jeder Mail, Löschung unbestätigter Anmeldungen nach 7 Tagen und inaktiver Abonnenten nach 24 Monaten (einstellbar); Administration mit Abonnenten-Liste, Filter nach Strecke/Umkreis, CSV-Export, Vorschau „X Interessenten im Korridor“ und Knopf „Benachrichtigung senden“ (einmal je Termin und Abonnent); Mail-Vorlagen de/en
- Neu: PLZ-Koordinaten für Deutschland mitgeliefert (GeoNames, CC BY 4.0, siehe NOTICE) – kein externer Dienst
- Deinstallieren ohne „Daten behalten“ entfernt auch die neuen Tabellen

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
