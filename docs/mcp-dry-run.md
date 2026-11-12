# `dryRun` on the UCP MCP tools

Every mutating UCP MCP tool takes `dryRun: bool`, defaulting to `true`, matching the core admin
write tools (`shopware-entity-upsert`, `shopware-order-state`, …). An agent previews first and
commits explicitly.

This exists so an eval harness can actually _call_ these tools: the safety classification is
mechanical — a tool whose `inputSchema` declares `dryRun` is called with `dryRun: true` forced on,
anything else that mutates is never called and gets graded on tool name alone.

| Tool                | `dryRun: true` behaviour         |
| ------------------- | -------------------------------- |
| `create_cart`       | transaction, rolled back         |
| `update_cart`       | transaction, rolled back         |
| `cancel_cart`       | transaction, rolled back         |
| `create_checkout`   | transaction, rolled back         |
| `update_checkout`   | transaction, rolled back         |
| `cancel_checkout`   | transaction, rolled back         |
| `apply_discount`    | transaction, rolled back         |
| `complete_checkout` | **read-only preview**, see below |

Read-only already, so no `dryRun`: `cart-get`, `catalog-lookup`, `catalog-search`, `checkout-get`,
`order-get`.

## Response shape

A tool answers the way the MCP binding of UCP defines it: `structuredContent` is the UCP response
itself, exactly as REST returns it, and the first text block is that response as JSON. The dry-run
flag is not part of the UCP response, so it goes into the result's `_meta` as `shopware/dryRun` and
into a second text block, which is what most clients show the model. A rolled-back dry run returns
the real operation result, flagged:

```json
{
  "content": [
    { "type": "text", "text": "{\"ucp\":{…},\"id\":\"…\",\"status\":\"…\"}" },
    { "type": "text", "text": "{\"_meta\":{\"dryRun\":true}}" }
  ],
  "structuredContent": { "ucp": { "…": "…" }, "id": "…", "status": "…" },
  "_meta": { "shopware/dryRun": true }
}
```

A commit returns the same result with `false`. The flag is always present on a mutating tool so an
agent can never be in doubt about whether it committed. Read-only tools omit it, the second text
block and `_meta` entirely.

The tools build this result themselves, so Shopware and the MCP SDK pass it on unchanged on
every MCP protocol revision. A failure is still the `{"success": false, "error": {…}}` envelope,
which Shopware renders.

## Why `checkout-complete` is different

The other tools run the real operation inside a DBAL transaction that is always rolled back — the
same mechanism as Shopware's `McpToolResponse::executeWithDryRun`. That is sound only for effects a
rollback undoes.

`CheckoutCompleter::complete()` synchronously `POST`s an `order.created` webhook to the merchant's
endpoint through the SDK's `DefaultOrderWebhookDispatcher`. No database rollback recalls an HTTP
request, so a rolled-back "preview" of a completion would tell the merchant about an order that
never existed — on the one tool in the catalogue that can take money.

So `checkout-complete` previews instead of executing: it reads the checkout back through the same
`checkout.get` path `get_checkout` uses, and reports what a commit would do. It supplies that
preview to `UcpMcpToolContext::executeMutating()` as a callback rather than branching before the
call, so it is still the one entry point every mutating tool goes through — see
[Idempotency](#idempotency):

`structuredContent` and the first text block are the current checkout. The result's `_meta` and the
second text block carry the preview:

```json
{
  "shopware/dryRun": true,
  "shopware/preview": {
    "operation": "checkout.complete",
    "committed": false,
    "wouldSucceed": false,
    "blockers": ["Checkout is incomplete: finish it with update_checkout before completing."]
  }
}
```

Blocker derivation lives in `UcpCheckoutCompletionPreview` — split out of the tool because the tool
depends on the final `ShoppingOperationExecutor` and cannot be constructed with a mock.

## Limits of the rolled-back dry run

Honest scope: the transaction covers **database** state on the plugin's DBAL connection. It does not
cover

- **Redis cart storage.** With `shopware.cart.storage.type: redis` the cart is written outside the
  transaction and a dry run leaves it behind. The plugin cannot detect this: the parameter does not
  exist on 6.5, so reading it would break the container there. Leftover carts expire on their own
  (`shopware.cart.expire_days`), which is why this is documented rather than guarded.
- **Anything a capability sends outbound** — webhooks, mails, messenger messages on a non-Doctrine
  transport. Of the rolled-back tools none currently do this; `checkout-complete`, which does, is
  previewed instead. Keep this in mind when adding a `dryRun` to a new tool: check what it
  dispatches before assuming a rollback is enough.
- **Flows.** Core's helper adds `Context::SKIP_TRIGGER_FLOW` before the write. The UCP capabilities
  build their own `SalesChannelContext` internally and expose no seam for it, so a dry run cannot
  suppress flows. Order placement is the flow-heavy path and it is excluded via the preview above.

## Idempotency

A dry run deliberately runs **before** `IdempotencyService::claim()`. Claiming on a preview would
mean the following real call replayed the rolled-back preview response instead of committing.
Validation that a commit would perform still applies — a dry run with `idempotencyRequired` and no
`Idempotency-Key` header fails exactly as a commit would.

That holds for `checkout-complete` too, which is why its read-only preview is handed to
`executeMutating()` instead of being run ahead of it. Both dry-run shapes sit behind the same check
in the same method, so a tool cannot skip it by previewing differently — a bypass this tool did have
once, on the one tool that can take money. Anything added later with its own preview shape belongs
in that `$preview` callback for the same reason.

## Not in this repository

Two acceptance criteria of shopware/agentic-commerce#153 live elsewhere:

- `shopware-store-api-context` is core's
  `Shopware\Core\System\SalesChannel\Mcp\Tool\StoreApiContextTool`.
- The Store catalogue snapshot and the `UNSAFE` → `DRY_RUNNABLE` move in `toolclass.py` belong to
  `shopware-mcp-evals`.
