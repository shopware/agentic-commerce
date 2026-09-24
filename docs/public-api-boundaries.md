# Public API Boundaries

The plugin's BC surface is behavior, not most PHP implementation classes.

Public contracts:

- REST, Admin API, Store API, A2A, embedded, and MCP route behavior.
- MCP tool names and payload schemas.
- DAL entity names, fields, associations, and template context keys.
- Documented UCP/SDK behavior.
- `Swag\AgenticCommerce\Ucp\Checkout\Payment\AbstractCompletionPaymentApplier`. The shipped
  `UnappliedCompletionPayment` deliberately ignores the instrument and completes against the sales
  channel default; the class exists so a deployment can replace that with one that settles the
  instrument the agent presented. Alias `AbstractCompletionPaymentApplier` to your own service, or
  decorate it and delegate through `getDecorated()`. An abstract class rather than an interface, per
  `adr/2020-11-25-decoration-pattern.md`, so a parameter can be added later without breaking every
  implementation at once. See `docs/completion-payment.md`.
- `Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider`
  plus the `swag_agentic_commerce.product_export.provider` service tag for product-export provider
  extensions. A third party extends the class, implements `getTechnicalName()` and
  `buildProviderContext()`, and tags its service with that tag;
  `AgenticCommerceProductExportProviderRegistry` picks it up via `tagged_iterator`.

Internal by default:

Every class, interface, trait, and enum under `src/` carries `@internal`, except the provider base
class listed above. Test classes under `tests/` carry `@internal` too, so the BC checker does not
capture them. Do not replace this with a per-namespace list; such a list silently omits namespaces
added later.

If a PHP class should become a public extension point, document its contract here before removing
`@internal`.
