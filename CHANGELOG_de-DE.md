# 1.1.1

- Behebt den Fehler "Element 'subtitle': This element is not expected" auf der Seite Grundeinstellungen unter Shopware 6.7. Der gebündelte System-Config-Schema-Workaround wird nur noch unter Shopware 6.5 angewendet; unter 6.6 und 6.7 wird das aktuelle Core-Schema verwendet.
- Der Speichern-Button im Verkaufskanal zeigte unter Shopware 6.7 einen rohen Snippet-Schlüssel an. Behoben durch Wechsel auf das gemeinsame Label `global.default.save`.

# 1.1.0

- Vollständige UCP-Unterstützung für Katalog, Warenkorb, Checkout, Bestellungen, Identität, eingebettete Seiten und MCP.
- Händlerseitige Änderungen am Bestellstatus – einschließlich Stornierungen – werden Agenten über die Bestellressource und den `order.updated`-Webhook bereitgestellt.
- Überarbeitete Administration und Verkaufskanal-Konfiguration für Agentic Commerce.
- Erweiterte OpenAI- und Google-Produktfeeds mit umfangreicheren Produktdaten und Validierungen.
- Verbesserte Kompatibilität mit Shopware 6.5, 6.6 und 6.7 sowie erweiterte automatisierte Tests.

# 1.0.0

- Erste Beta-Version von Agentic Commerce.
