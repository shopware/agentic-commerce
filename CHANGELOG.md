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
