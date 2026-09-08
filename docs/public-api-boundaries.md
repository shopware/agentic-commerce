# Public API Boundaries

The plugin's BC surface is behavior, not most PHP implementation classes.

Public contracts:

- REST, Admin API, Store API, A2A, embedded, and MCP route behavior.
- MCP tool names and payload schemas.
- DAL entity names, fields, associations, and template context keys.
- Documented UCP/SDK behavior.
- `Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider`
  plus the `swag_agentic_commerce.product_export.provider` service tag for
  product-export provider extensions. A third party extends the class, implements
  `getTechnicalName()` and `buildProviderContext()`, and tags its service with that
  tag; `AgenticCommerceProductExportProviderRegistry` picks it up via
  `tagged_iterator`.

Internal by default:

Every class, interface, trait, and enum under `src/` carries `@internal`, except the
provider base class listed above. Test classes under `tests/` carry `@internal` too,
so the BC checker does not capture them. Do not replace this with a per-namespace
list; such a list silently omits namespaces added later.

If a PHP class should become a public extension point, document its contract here
before removing `@internal`.
