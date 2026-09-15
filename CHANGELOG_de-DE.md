# 1.3.0

- Der Katalog liefert nur noch Produkte, die ein Agent auch kaufen kann. Beim Durchsuchen wurde bisher das Hauptprodukt jeder Variante zurückgegeben -- es ist nicht bestellbar, ein damit gefüllter Warenkorb blieb also stillschweigend leer -- und zusätzlich jede Variante einzeln, alle unter dem Namen des Hauptprodukts. Jetzt kommt eine Zeile pro Variantengruppe ohne Hauptprodukte zurück, Variantentitel enthalten die unterscheidenden Eigenschaften (`Acoustic Guitar (Color: Yellow, Material: Spruce Top)`), und `catalog.lookup` sowie `catalog.product` beantworten die ID eines Hauptprodukts mit einer kaufbaren Variante, die jedes Mal gleich gewählt wird. `catalog.product` lieferte zuvor bei jedem Aufruf eine andere Variante.
- Eine Position, die nicht im Warenkorb angekommen ist, wird nicht mehr als Erfolg gemeldet. Shopware entfernt ein nicht auflösbares Produkt ohne Fehlermeldung, weshalb die Anfrage nach einem nicht bestellbaren Produkt `201 Created`, `status: success`, keine Meldungen und einen leeren Warenkorb ergab. Jetzt antwortet die Anfrage mit `422` und einem behebbaren Fehler samt ID -- beim Hauptprodukt einer Variante mit der Liste der stattdessen kaufbaren Varianten-IDs.
- Das UCP-SDK wird als einzelnes Patch-Fenster (`>=0.0.6 <0.0.7`) vorausgesetzt, und `ucp_sdk.version` wird nicht mehr gesetzt. Das SDK bedient pro Release genau eine UCP-Version und verwendet sie als Standardwert, deshalb erreicht ein SDK-Release Shops jetzt nur noch zusammen mit einem Plugin-Release, und das Plugin kann keine Version mehr benennen, die sein SDK nicht bedient. Version 1.2.x kombinierte ein fest eingetragenes `2026-04-08` mit einem `>=0.0.5 <0.1.0`-Fenster: `composer update` installierte SDK 0.0.6, das diese Version nicht mehr bedient, und der Container-Build schlug mitten in einem Shopware-Core-Update in `assets:install` fehl. `docs/ucp-version-support.md` beschreibt, welche Version das Plugin bedient und was ein Versionswechsel für einen Shop bedeutet.
- Es wird gezählt, welche UCP-Versionen Agenten tatsächlich sprechen: pro Request ein `info`-Eintrag im neuen Log-Kanal `ucp_negotiation` mit der vom Agenten genannten Version, der bedienten Version, dem Ergebnis und dem Host des Agentenprofils, sonst nichts. Auf dieser Messung beruht die Überprüfung der Ein-Versions-Entscheidung; sie benötigt ein SDK-Release neuer als `0.0.6` und ist bis dahin wirkungslos.
- Neuer Befehl `ucp:setup`, der einen Verkaufskanal in einem Schritt für UCP einrichtet: Freigabe, Sicherheitsvorgaben, ein Signaturschlüssel, falls noch keiner existiert, die Bereitschaftsprüfung und der erste auszuführende Request. `--dev` wählt lokale Vorgaben, mit denen der Shop als sein eigener Agent auftreten kann; ohne die Option gelten Produktionsvorgaben. Die README beschreibt die gesamte Einrichtung in vier Schritten.
- UCP `2026-08-25` mit SDK `0.0.6` oder neuer: versionsabhängige Capability-Aushandlung, standardisierte Katalog-IDs sowie aktualisierte Einwilligungs-, Liefer- und Zahlungsdaten.
- Katalogsuche und Produktabfrage liefern echte Produktbeschreibungen. Fehlt eine Beschreibung, wird der Produkttitel verwendet.
- Eine leere Katalogsuche listet Produkte auf. Unbekannte Warenkorb-IDs werden mit `not_found` abgelehnt.
- Die UCP-MCP-Tools verwenden die standardisierten Namen wie `search_catalog` und `create_checkout` und sind direkt nach Sitzungsbeginn sichtbar. Clients müssen bisherige `shopware-ucp-*`-Namen anpassen.
- Zahlungsdaten beim Checkout-Abschluss werden an das Plattform-Gateway weitergereicht; angewendete Rabatte werden einzeln ausgewiesen.
- Konfigurierte Freigabelisten gelten auch für das Laden von Agentenprofilen. Eingebettete Antworten geben keine Checkout-Tokens mehr preis.
- Produktlinks in den OpenAI- und Google-Produktfeeds werden für Headless-Verkaufskanäle ab Shopware 6.7.14 nun korrekt aufgelöst, sodass Agenten funktionierende Produkt-URLs erhalten; auf älteren Shopware-Versionen funktionieren die Feeds unverändert weiter.
- Die Admin-Übersetzungen liegen jetzt in länder-agnostischen Dateien (`de.json`, `en.json`) gemäß aktueller Shopware-Core-Konvention; ein Kompatibilitäts-Loader hält sie auf Shopware-Versionen vor 6.7.3 funktionsfähig.
- Admin-Texte überarbeitet: durchgängige Großschreibung der Du-Anrede und ein eindeutigeres „Total"-Label in der englischen Statistik-Zusammenfassung.
- Der Abschluss eines agentischen Checkouts funktioniert auch auf kommenden Shopware-Versionen: Der beim Abschluss angelegte Gastkunde rotiert das Shopware-Kontext-Token und verschiebt den Warenkorb mit, und die Bestellung wird nun mit dem neuen statt dem veralteten Token aufgegeben, das neuere Shopware-Versionen mit einem „Warenkorb nicht gefunden“-Fehler ablehnen.

- UCP lässt sich nur noch in Verkaufskanälen aktivieren, die tatsächlich verkaufen können: Storefront und Headless. Produktfeed-Kanäle werden für UCP nicht mehr angeboten und lassen sich weder über die API noch über die Konsole aktivieren. Ein Feed-Kanal, in dem UCP zuvor aktiviert war, gilt jetzt als deaktiviert und bewirbt so keinen Shop, in dem ein Agent nichts kaufen kann.
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
