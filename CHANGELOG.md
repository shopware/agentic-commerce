# 1.1.1

- Fix the Basic Information settings page failing on Shopware 6.7 with "Element 'subtitle': This element is not expected" by applying the bundled system-config schema workaround only on Shopware 6.5, and using core's own current schema on 6.6 and 6.7.

# 1.1.0

- Add full UCP catalog, cart, checkout, order, identity, embedded, and MCP support.
- Surface merchant-side order lifecycle changes, including cancellations, to agents through the order resource and the `order.updated` webhook.
- Redesign Agentic Commerce administration and sales-channel configuration.
- Extend OpenAI and Google product feeds with richer product data and validation.
- Improve Shopware 6.5, 6.6, and 6.7 compatibility and automated test coverage.

# 1.0.0

- Initial Agentic Commerce beta release.
