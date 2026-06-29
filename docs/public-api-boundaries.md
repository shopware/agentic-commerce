# Public API Boundaries

The plugin's BC surface is behavior, not most PHP implementation classes.

Public contracts:

- REST, Admin API, Store API, A2A, embedded, and MCP route behavior.
- MCP tool names and payload schemas.
- DAL entity names, fields, associations, and template context keys.
- Documented UCP/SDK behavior.
- `Swag\AgenticCommerce\Content\ProductExport\Provider\AbstractAgenticCommerceProductExportProvider`
  plus the `swag_agentic_commerce.product_export.provider` service tag for
  product-export provider extensions.

Internal by default:

- `Swag\AgenticCommerce\AgenticFiles\`
- `Swag\AgenticCommerce\Compatibility\`
- `Swag\AgenticCommerce\Content\ProductExport\`, except the provider base class
  listed above.
- `Swag\AgenticCommerce\DependencyInjection\`
- `Swag\AgenticCommerce\Exception\`
- `Swag\AgenticCommerce\Migration\`
- `Swag\AgenticCommerce\Storefront\`
- `Swag\AgenticCommerce\System\`
- `Swag\AgenticCommerce\Ucp\`

Classes, interfaces, and traits in internal-by-default namespaces must carry
`@internal`. If a PHP class in one of those namespaces should become a public
extension point, document that contract here before removing `@internal`.
