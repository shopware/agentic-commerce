# next version

- Product links in the OpenAI and Google product feeds now resolve correctly for headless sales channels on Shopware 6.7.14 and newer, so agents receive working product URLs; the feeds keep working unchanged on earlier Shopware versions.
- Store the administration translations in country-agnostic files (`de.json`, `en.json`) following the current Shopware core convention; a compatibility loader keeps them working on Shopware versions before 6.7.3.
- Polish the administration texts: consistent capitalisation of the informal German address and a clearer "Total" label in the English statistics summary.

# 1.3.0

- Restrict UCP to the sales channels that can actually complete a purchase: Storefront and Headless. Product feed channels are no longer offered for UCP and can no longer have it switched on through the API or the console; one that had it switched on before is now treated as switched off, so no shop is advertised that an agent cannot buy from.
- Serve `/.well-known/api-catalog` (RFC 9727 linkset) on exposed sales channels, so an agent can discover the shop's UCP profile and Store API entry point from one standardised location; unexposed channels return 404.

# 1.2.0

- Add a dry-run mode and actionable error messages to the UCP MCP tools, so an agent that gets a request wrong is told which field and why instead of receiving an opaque failure.
- Read the shipping address from where UCP sends it, so a checkout with separate shipping and billing addresses no longer ships to the billing one.
- Make checkout completion callable, and keep discount responses valid against the UCP schemas.
- Always send an absolute, openable order link in `order.permalink_url` — for guests it is the order's deep link, which works without logging in, and it is the same link the confirmation email uses.
- Refuse a guest order read in the protocol's own vocabulary: an agent asking for someone else's guest order is told the order was not found and that the permalink is how a guest order is read, rather than receiving an internal error.
- Report the code and severity of a failed agent request, and log the underlying exception, so a failure can be diagnosed from the shop's log.
- Require `ucp-php-sdk` 0.0.5 or newer, and accept every later `0.0.x` release.
- Show the Agentic Commerce tab only on sales channels that can actually sell, and fix tab, template-selection and save-button inconsistencies across Shopware 6.5, 6.6 and 6.7.
- Mark parent listings that have variants correctly in the product feeds.
- Fix the installable ZIP shipping an administration that never loaded: the packaged plugin now contains a real, compiled admin bundle that works on Shopware 6.5, 6.6 and 6.7 alike, so the Agentic Commerce tab appears after a plain upload-and-install without rebuilding anything in the shop.

# 1.1.1

- Fix the Basic Information settings page failing on Shopware 6.7 with "Element 'subtitle': This element is not expected" by applying the bundled system-config schema workaround only on Shopware 6.5, and using core's own current schema on 6.6 and 6.7.
- Fix the sales-channel Save button showing a raw snippet key on Shopware 6.7 by using the shared `global.default.save` label.

# 1.1.0

- Add full UCP catalog, cart, checkout, order, identity, embedded, and MCP support.
- Surface merchant-side order lifecycle changes, including cancellations, to agents through the order resource and the `order.updated` webhook.
- Redesign Agentic Commerce administration and sales-channel configuration.
- Extend OpenAI and Google product feeds with richer product data and validation.
- Improve Shopware 6.5, 6.6, and 6.7 compatibility and automated test coverage.

# 1.0.0

- Initial Agentic Commerce beta release.
