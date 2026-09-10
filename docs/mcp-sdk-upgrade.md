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

> **Re-verify before doing the Schema work below.** Recent trunk checkouts have
> been observed resolving `mcp/sdk v0.7.0`, i.e. the ceiling may already be gone.
> `Schema` cannot simply be adopted either way: the plugin has no `mcp/sdk`
> requirement of its own, so a 0.5 install would fail when the bundle reflects an
> attribute class that is not there. Check the resolved version across the whole
> supported matrix first, not just one lane.

## Current (v0.5.0-compatible) behaviour

To keep PHPStan and the trunk lane green we target the v0.5.0 API:

- **Write tools** (`cart.create`, `cart.update`, `checkout.create`,
  `checkout.update`) take a **JSON-string `payload`** that
  `UcpMcpToolContext::decodeObject()` parses, instead of a typed `array $payload`
  validated by `#[Schema(definition: ShoppingOperationToolSchemas::…)]`.
- **Errors** are returned **in band** by `UcpMcpToolContext::failure()` as
  `{"success":false,"error":{…}}` tool content, mirroring the
  `{"success":true,"data":…}` shape of `success()`. Throwing instead is not an
  option on this version: the MCP server turns any exception into a generic
  `"Error while executing tool"` JSON-RPC error, so the message the agent needs
  in order to correct its call is lost. In-band tool errors are also what the MCP
  spec recommends, so this stays correct after the SDK bump — a
  `ToolCallException` is only worth adding for genuinely protocol-level failures
  (`-32602` invalid input), not for UCP domain errors.
  Only `Ucp\Sdk\Exception\UcpException` subclasses are surfaced verbatim;
  everything else is reported as `{"type":"internal"}` with a generic message so
  internals do not leak to an unauthenticated MCP client.

## Why not just require `mcp/sdk: ^0.6`?

`^0.5` means `>=0.5.0 <0.6.0`, so `0.6.0` is excluded. Adding `mcp/sdk: ^0.6` to
this plugin's `composer.json` conflicts with `symfony/mcp-bundle`'s `^0.5` and the
install fails. `symfony/mcp-bundle` (including its `main`) still pins `^0.5`, so
the bump has to happen **upstream first**.

## How to upgrade later

When `symfony/mcp-bundle` widens its constraint to allow `mcp/sdk ^0.6` and
Shopware trunk bumps the bundle:

1. Restore the typed object payloads on the four write tools:
   - add `use Mcp\Capability\Attribute\Schema;` and
     `use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;`
   - change `__invoke(string $payload = '{}')` back to
     `#[Schema(definition: ShoppingOperationToolSchemas::CART_CREATE_INPUT)] public function __invoke(array $payload)`
     (and the `CART_UPDATE_INPUT` / `CHECKOUT_CREATE_INPUT` / `CHECKOUT_UPDATE_INPUT`
     counterparts), passing `$payload` straight to `ShoppingOperationRequest`
     instead of `decodeObject($payload)`.
2. Leave `UcpMcpToolContext::failure()` alone — in-band tool errors remain the
   right shape. Optionally add a `Mcp\Exception\ToolCallException` path for
   protocol-level input errors (`-32602`), but keep UCP domain errors in band.
3. Remove the `test.skip(true, …)` guard in
   `tests/e2e/ucp/profile.spec.js` → `exposes object payload schemas for MCP write
   tools` and confirm it passes against trunk.

The git history of this branch contains the full v0.6 implementation if you need
a reference diff.
