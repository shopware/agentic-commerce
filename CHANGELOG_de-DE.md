# next version

- Produktlinks in den OpenAI- und Google-Produktfeeds werden für Headless-Verkaufskanäle ab Shopware 6.7.14 nun korrekt aufgelöst, sodass Agenten funktionierende Produkt-URLs erhalten; auf älteren Shopware-Versionen funktionieren die Feeds unverändert weiter.
- Die Admin-Übersetzungen liegen jetzt in länder-agnostischen Dateien (`de.json`, `en.json`) gemäß aktueller Shopware-Core-Konvention; ein Kompatibilitäts-Loader hält sie auf Shopware-Versionen vor 6.7.3 funktionsfähig.
- Admin-Texte überarbeitet: durchgängige Großschreibung der Du-Anrede und ein eindeutigeres „Total"-Label in der englischen Statistik-Zusammenfassung.

# 1.3.0

- Exponierte Verkaufskanäle liefern `/.well-known/api-catalog` (RFC-9727-Linkset) aus, sodass ein Agent das UCP-Profil und den Store-API-Einstiegspunkt des Shops an einem standardisierten Ort findet; nicht exponierte Kanäle antworten mit 404.

# 1.2.0

- Die UCP-MCP-Tools unterstützen einen Trockenlauf (Dry Run) und liefern verwertbare Fehlermeldungen: Ein Agent erfährt, welches Feld falsch ist und warum, statt einen undurchsichtigen Fehler zu erhalten.
- Die Lieferadresse wird dort gelesen, wo UCP sie sendet. Ein Checkout mit getrennter Liefer- und Rechnungsadresse liefert nicht mehr an die Rechnungsadresse.
- Der Checkout-Abschluss ist aufrufbar, und Antworten mit Rabatten bleiben gegenüber den UCP-Schemas valide.
- `order.permalink_url` enthält immer einen absoluten, aufrufbaren Bestell-Link. Für Gäste ist das der Deep Link der Bestellung, der ohne Anmeldung funktioniert – derselbe Link wie in der Bestellbestätigung.
- Der Zugriff auf eine fremde Gastbestellung wird in der Sprache des Protokolls abgelehnt: Der Agent erhält "nicht gefunden" und den Hinweis, dass Gastbestellungen über den Permalink gelesen werden, statt eines internen Fehlers.
- Code und Schweregrad einer fehlgeschlagenen Agenten-Anfrage werden gemeldet und die zugrunde liegende Exception geloggt, sodass Fehler über das Shop-Log nachvollziehbar sind.
- Es wird `ucp-php-sdk` 0.0.5 oder neuer benötigt; alle späteren `0.0.x`-Releases sind erlaubt.
- Der Agentic-Commerce-Tab erscheint nur in Verkaufskanälen, die tatsächlich verkaufen können. Inkonsistenzen bei Tabs, Template-Auswahl und Speichern-Button unter Shopware 6.5, 6.6 und 6.7 sind behoben.
- Eltern-Artikel mit Varianten werden in den Produkt-Feeds korrekt gekennzeichnet.
- Behoben: Das installierbare ZIP enthielt eine Administration, die nie geladen wurde. Das Paket enthält jetzt ein echtes, kompiliertes Admin-Bundle, das unter Shopware 6.5, 6.6 und 6.7 gleichermaßen funktioniert. Der Agentic-Commerce-Tab erscheint dadurch direkt nach dem Hochladen und Installieren, ohne dass im Shop etwas neu gebaut werden muss.

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
