# MCP tooling and the `mcp/sdk` version ceiling

The UCP MCP tools (`src/Ucp/Mcp/Tool/*`) run on the `Mcp\…` classes provided by
`mcp/sdk`, which reaches the plugin transitively through `symfony/mcp-bundle`.
That bundle is **only present on Shopware trunk** (6.5/6.6 do not ship it), and
trunk pins:

```
symfony/mcp-bundle: ~0.9.0   →   requires   mcp/sdk: ^0.5   →   resolves   mcp/sdk v0.5.0
```

`mcp/sdk v0.5.0` ships `Mcp\Capability\Attribute\McpTool` but **not**
`Mcp\Capability\Attribute\Schema` nor `Mcp\Exception\ToolCallException`. Both were
added later and are available from **`mcp/sdk` v0.6.0**.

## Current (v0.5.0-compatible) behaviour

To keep PHPStan and the trunk lane green we target the v0.5.0 API:

- **Write tools** (`cart.create`, `cart.update`, `checkout.create`,
  `checkout.update`) take a typed `array $payload`. The v0.5 SDK infers the
  schema from the method signature; it does not support explicit
  `#[Schema(definition: ShoppingOperationToolSchemas::…)]` attributes yet.
- **Errors** propagate as plain exceptions; the MCP server maps them to a generic
  JSON-RPC tool error. `UcpMcpToolContext::toToolCallException()` is kept as a
  pass-through wrapper (the seam to restore the richer mapping).

## Why not just require `mcp/sdk: ^0.6`?

`^0.5` means `>=0.5.0 <0.6.0`, so `0.6.0` is excluded. Adding `mcp/sdk: ^0.6` to
this plugin's `composer.json` conflicts with `symfony/mcp-bundle`'s `^0.5` and the
install fails. `symfony/mcp-bundle` (including its `main`) still pins `^0.5`, so
the bump has to happen **upstream first**.

## How to upgrade later

When `symfony/mcp-bundle` widens its constraint to allow `mcp/sdk ^0.6` and
Shopware trunk bumps the bundle:

1. Add explicit schemas to the four write tools:
   - add `use Mcp\Capability\Attribute\Schema;` and
     `use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;`
   - annotate the existing `array $payload` argument with
     `#[Schema(definition: ShoppingOperationToolSchemas::CART_CREATE_INPUT)]`
     (and the `CART_UPDATE_INPUT` / `CHECKOUT_CREATE_INPUT` / `CHECKOUT_UPDATE_INPUT`
     counterparts).
2. Restore structured errors in `UcpMcpToolContext::toToolCallException()`:
   return a `Mcp\Exception\ToolCallException` (per-violation messages for
   `ValidationException`, `-32602` for invalid input). The call sites in every
   tool already invoke this method, so only the body changes.
3. Tighten `tests/e2e/ucp/profile.spec.js` → `exposes object payload schemas for
   MCP write tools` to assert the schema details provided by the explicit
   `#[Schema]` definitions.

The git history of this branch contains the full v0.6 implementation if you need
a reference diff.
