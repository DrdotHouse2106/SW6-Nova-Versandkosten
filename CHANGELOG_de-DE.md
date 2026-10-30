# 1.0.0

- Erste Version: Versandkosten aus importierten Einkaufstarifen (JSON oder CSV) berechnen
- Rechenkern ohne Shopware-Abhängigkeit: Paketbildung, Warenpost/Kleinpaket/DPD/DHL, Sperrgut/Übermaß/Übergröße, Maut, Energie, Peak und Peak-in-Peak, Insel-, Zoll- und Währungszuschläge, Ausland (außerhalb der EU ohne MwSt.), versandkostenfrei für Hersteller/Tags
- Sechs vom Plugin angelegte (inaktive) Versandarten; unpassende Versandarten werden in der Kasse ausgeblendet
- Spedition über die Cargoboard-API mit Zwischenspeicher und Ersatzpreis; ohne Schlüssel „auf Anfrage“
- Kasse: Preis, voraussichtliches Zustelldatum (Feiertage je Bundesland), Hinweise, Empfehlung, Knopf „Lieferung per Tour anfragen“
- Bestellung: Ergebnis als Zusatzfelder, Karte „Versandkosten“ mit Einkauf/Verkauf/Differenz, optional E-Mail bei negativer Marge
- Administration: Tarifimport/-export und Preisprüfung je Verkaufskanal
- Produktexport: Twig-Funktion `nova_versand_label()` für das Google-Versandlabel
