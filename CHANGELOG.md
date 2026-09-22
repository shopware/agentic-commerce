# next version

- Let Shopware install the UCP SDK instead of shipping it inside the archive. 1.3.0 put the SDK in the plugin's own `vendor/` and loaded the autoloader Composer had generated for it, because Shopware does not load a plugin's `vendor/autoload.php` by itself. Every shop that installed it then carried two separate package registries: FroshTools reported `2 autoloaders registered`, and a question as ordinary as which version of a package is installed could be answered from the plugin's copy instead of the shop's. The extension now carries no dependencies at all. It has Shopware run `composer require` on install and update instead, which is what that mechanism is there for on a zip-installed extension: the extension itself resolves from the `custom/plugins/*` path repository every Shopware project declares, and the SDK version it names comes from Packagist into the shop's own `vendor/`. Nothing changes for a shop that installs through Composer. What is new is that installing has to reach Packagist -- without it the install stops with a Composer error, rather than leaving an extension that cannot run.
- Switch the extension off instead of taking the shop down when the SDK is missing. An update extracts the new files one request before Shopware runs Composer, so an active extension boots at least once without the dependency it needs. On 1.3.0 every page answered `500` from that moment on, the storefront included, until someone installed the SDK by hand. The extension now registers no services, routes or feeds in that state, and writes to `var/log/swag-agentic-commerce.log` what is missing and the command that fixes it. Once the SDK is there it picks up again on its own. The check asks not only whether an SDK is present but whether it is the version this release names, so a shop still holding the previous one waits instead of running new code against an old SDK. The README has a _Troubleshooting_ section covering what that state looks like, how to leave it, and what to expect when updating from 1.3.0 or older.

# 1.3.0

- Register the billing and shipping address the agent actually stated. One address was resolved from the fulfillment destination and registered as the billing address, with no shipping address passed at all -- so an agent that stated the two separately had the parcel sent to the billing address, and a digital cart, which has no destination to offer, had no address at all and could not complete. Billing now comes from `payment.instruments[].billing_address` and shipping from the fulfillment destination, each falling back to the other, and a shipping address reaches Shopware only when it differs from billing.
- Answer the catalog with products an agent can actually buy. Browsing returned the parent of every variant product -- which is not purchasable, so a cart built from it silently stayed empty -- plus one row per variant, all wearing the parent's name. Browsing now returns one row per variant group and no parents, variant titles carry the options that tell them apart (`Acoustic Guitar (Color: Yellow, Material: Spruce Top)`), and `catalog.lookup` and `catalog.product` answer a parent id with a purchasable variant chosen the same way every time. `catalog.product` previously picked a different variant on every call.
- Refuse a line item that never reached the cart instead of reporting success. Shopware drops a product it cannot resolve without leaving an error, so asking for an unbuyable product returned `201 Created`, `status: success`, no messages and an empty cart. The request is now answered with `422` and a recoverable error naming the id, and for the parent of a variant product it lists the variant ids to buy instead.
- Count which UCP versions agents actually speak: one `info` record per request on the new `ucp_negotiation` log channel with the agent's declared version, the served version, the outcome and the agent profile host, nothing else. This is the measurement the single-version policy is revisited on, and SDK `0.0.7` reports it.
- Add `ucp:setup`, which configures a sales channel for UCP in one step: exposure, security defaults, a signing key when the channel has none, the readiness checks and the first request to run. `--dev` picks local defaults so the shop can act as its own agent; without it the defaults are production ones. The README now walks through the whole setup in four steps.
- Require the exact UCP SDK version the release was tested against (`0.0.7`) and stop configuring `ucp_sdk.version`. The SDK serves one UCP version per release and defaults to it, so an SDK release now arrives together with a plugin release instead of reaching shops on its own, and the plugin can no longer name a version its linked SDK does not serve. Version 1.2.x combined a pinned `2026-04-08` with a `>=0.0.5 <0.1.0` window: `composer update` resolved SDK 0.0.6, which no longer served that version, and the container build failed inside `assets:install` part-way through a Shopware core upgrade. `docs/ucp-version-support.md` explains what the plugin serves and what a spec bump means for a shop.
- Serve UCP `2026-08-25` through SDK `0.0.7`, including version-aware capability negotiation, the standard catalog capability IDs and updated consent, fulfillment and payment shapes.
- Return real product descriptions from catalog search and lookup, with the product title as fallback.
- Apply checkout completion payment data through the platform gateway and expose the applied-discount breakdown.
- Enforce configured profile-fetch allowlists and prevent checkout tokens from leaking through embedded responses.
- Answer a UCP catalog search with an empty query by listing the catalog instead of returning no products. `query` is optional free text in the specification, and an agent opening with a blank search was being told the shop had nothing to sell.
- Answer a UCP request for a cart nobody created with `not_found` instead of handing out a fresh empty cart under the guessed id. Cart ids handed out by `cart.create` are remembered in the same context store the checkout session already uses.
- Name the UCP MCP tools as the specification's OpenRPC document does (`search_catalog`, `create_cart`, `create_checkout`, `complete_checkout`, `get_order` and so on) and advertise them on a fresh MCP session. They were named `shopware-ucp-*` and hidden behind a toolset an agent had to enable first, so a spec-following agent listing tools on `/ucp/mcp` saw only the toolset meta-tools. An MCP client that called the old names has to switch; the arguments are unchanged.
- Product links in the OpenAI and Google product feeds now resolve correctly for headless sales channels on Shopware 6.7.14 and newer, so agents receive working product URLs; the feeds keep working unchanged on earlier Shopware versions.
- Store the administration translations in country-agnostic files (`de.json`, `en.json`) following the current Shopware core convention; a compatibility loader keeps them working on Shopware versions before 6.7.3.
- Polish the administration texts: consistent capitalisation of the informal German address and a clearer "Total" label in the English statistics summary.
- Completing an agentic checkout keeps working on upcoming Shopware versions: the guest customer created during completion rotates the Shopware context token and moves the cart with it, and the order is now placed against the new token instead of the stale one, which newer Shopware versions reject with a "cart not found" error.

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
