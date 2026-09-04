# UCP SDK Integration Backlog

Status: planned, not started. Owner: TBD.

The UCP PHP SDK is upgrading from protocol version `2026-04-08` to `2026-08-25`,
a breaking upstream release, and is fixing several interop deviations along the
way. The SDK-side plan lives in that repository at
`docs/ucp-2026-08-25-upgrade.md` and its task numbers (`T*`) are referenced here.

This document is the plugin-side counterpart: what has to change here, in what
order relative to the SDK, and what turns out not to be a problem. Each `P*`
section is written to be pasted into a GitHub issue as-is.

## How tightly we are coupled

Harder than "a plugin that uses a library":

- **366 `use Ucp\Sdk\…` statements across 89 distinct symbols** in `src/` and `tests/`.
- Seven hand-written `Ucp\Sdk\Contract\*` capability implementations and six `Ucp\Sdk\Adapter\*` adapters. We do **not** use the SDK's `AdapterBacked*Capability` classes; we hand-write the capability layer over our own adapters.
- Five console commands **subclass** SDK command classes, with `ReplaceSdkSigningKeyCommandsPass` removing the SDK originals by class name at priority 10000.
- The SDK bundle is registered through `getAdditionalBundles()` (`src/SwagAgenticCommerce.php:73-88`), by string class name so static analysis and the ZIP build do not hard-require it.
- `Ucp\Sdk\Model\RequestContext` is threaded through **51 files** (36 in `src/`, 15 in `tests/`) — every adapter, capability, gateway, MCP tool and subscriber.
- All UCP protocol routes are imported wholesale from the SDK bundle (`src/Resources/config/routes.php:34-39`) and forced into storefront scope.

## The one thing that must happen first

`composer.json:14` requires `ucp-php-sdk/symfony-bundle >=0.0.5 <0.1.0`, there is
**no `composer.lock`**, and `SwagAgenticCommerce::executeComposerCommands()`
returns `true` — so the SDK resolves at merchant install time. `README.md:234-252`
already documents the consequence:

> "A new SDK release now reaches merchants without a plugin change. … a breaking
> SDK patch can land in production on its own."

The SDK plan ships roughly six breaking `0.0.x` releases. **Under the current
constraint, every one of them auto-installs onto merchants.** `P1` is therefore a
hard prerequisite for SDK `T17`, and should land before any of the SDK's Wave 3.

## What survives the SDK upgrade unchanged

Worth recording, because it is the result of deliberate SDK-side design choices
and should not be re-litigated:

| Our usage | Why it is safe |
|---|---|
| `PlatformProfile` — 6-arg positional construction plus 6 property reads at `src/Ucp/Profile/CapabilityFilteringProfileContributor.php:63-70` | SDK `T16` renames only the *wire* field (`signing_keys` becomes `keys`) and keeps the PHP property `$signingKeys`. **No change needed.** |
| `CapabilityDescriptor` — 5-arg positional at `src/Ucp/Capability/UcpCapabilityCatalog.php:118-124` and 6-arg at `CapabilityFilteringProfileContributor.php:96-103` | SDK `T20` adds a trailing `?CapabilityRequirements $requires = null`. Append-with-default, invisible to both sites. |
| `BuyerConsent` | **Zero references anywhere in the plugin.** SDK `T18` is free. |
| `MonetaryAmount` | Zero references; we only use `Money`. `T17`'s rounding work is invisible to our source, though it changes serialized output. |
| `ServiceEndpoint->transport->value` at `CapabilityFilteringProfileContributor.php:58` | The SDK plan does not change `Transport` or the `$transport` property type. |
| Our `/ucp/mcp` proxy and MCP tools | The SDK explicitly will **not** build an MCP runtime (see its "Explicitly out of scope"). Our runtime stays authoritative. |
| Our OAuth Auth Code + PKCE S256 implementation (`Migration1780328112`) | SDK-side PKCE is deferred. Nothing breaks; we would only be able to delete ours if the SDK ships it. |

## Corrections to `full-ucp-parity-plan.md`

That document lists three items under `## Remaining Runtime Gaps` as requiring an
upstream SDK change. **Two are already shipped and the third is fixed by the
version bump.**

| Stated gap | Reality |
|---|---|
| "`checkout.complete` … the SDK's `CheckoutAdapterInterface::completeCheckout()` takes only an id and a context … Threading payment into the adapter is an upstream SDK change." | **Available since SDK 0.0.3.** `Ucp\Sdk\Contract\PaymentAwareCheckoutCapabilityInterface` and `Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface` both expose `completeCheckoutFromRequest(CheckoutCompleteRequest, RequestContext)`. The adapter interface's own docblock: *"adopting it is a one-method change with no coordination."* We implement neither. → **P12** |
| "the SDK's `Cart` model has no `discounts` field and no `extra` escape hatch, so cart responses cannot carry it" | **Stale.** `packages/core/src/Model/Cart/Cart.php:26` declares `public readonly array $extra = []`, added in SDK 0.0.3. → **P13** |
| "`cart.update` requires the cart id inside the payload as well as on the tool argument … removing it is an upstream SDK change" | **Fixed by the bump.** UCP `2026-08-25` standardises `cart.id` as omitted in update requests. → **P14** |

`P17` corrects the document.

---

# Backlog

Effort is S/M/L.

## P1 — `build!: pin the SDK to an exact patch range`

**Why.** See "The one thing that must happen first". Six breaking SDK releases
are coming and the current constraint admits all of them silently.

**Note.** `~0.0.5` does **not** help — for a `0.0.x` version Composer expands the
tilde to the same `>=0.0.5 <0.1.0`. Use an explicit single-patch window
(`>=0.0.6 <0.0.7`), bumped deliberately per SDK release with a CHANGELOG entry.

**Files.** `composer.json:14`; `.github/workflows/ci.yml:221-222` (the forced
path-repo versions must track the same window); `CHANGELOG.md` and
`CHANGELOG_de-DE.md`; `README.md:234-252` (the "Bumping the SDK version floor"
section documents the old policy).

**Decide in the PR.** Whether to commit a `composer.lock`. Plugins usually do
not, which is exactly why the constraint has to carry the weight.

**Acceptance.** Installing against an SDK release outside the window fails
resolution rather than silently upgrading. CI's forced versions match the
declared window. `README.md` states the new policy.

**Effort.** S · **Blocks.** SDK `T17` reaching merchants safely

## P2 — `refactor: use named arguments for every SDK model construction`

**Why.** `src/Ucp/Gateway/ShopwareDataMapper.php` alone constructs `Checkout`
with **11 positional arguments, twice** (`:85-97` and `:102-117`, the first
including a bare `null` in slot 10), `OrderView` with 8 positional plus 4 named
(`:122-135`), `Cart` with 5 (`:66-75`), `LineItem` with 6 (`:406-413`), and
`Money` five times (`:382-389`). Any SDK parameter insertion or reorder silently
shifts arguments rather than failing to compile.

**This is the highest-leverage task in this backlog.** It converts every
subsequent SDK model change from a silent runtime defect into a named-argument
error PHPStan catches, and it is what makes `P7` and `P8` tractable.

**Files.** `src/Ucp/Gateway/ShopwareDataMapper.php` (roughly 15 construction
sites); `src/Ucp/Adapter/ShopwareCheckoutAdapter.php:220` (`new Cart($cart->id)`);
`src/Ucp/Capability/UcpCapabilityCatalog.php:118-124`;
`src/Ucp/Profile/CapabilityFilteringProfileContributor.php:63-70,96-103`;
`src/Ucp/Payment/ShopwareInvoicePaymentHandler.php:22-33`;
`src/Ucp/Admin/SigningKey/UcpSigningKeyService.php:81`. Tests may keep positional
construction where it reads better, but `src/` should not.

**Acceptance.** No positional construction of an `Ucp\Sdk\Model\*` class remains
in `src/`. PHPStan level 7 clean. `ShopwareDataMapperTest` unchanged — this is a
pure refactor.

**Effort.** M · **Blocks.** P7, P8; should precede the SDK's Wave 3

## P3 — `chore: eliminate or promote the @internal SDK dependencies`

**Why.** Four `@internal` couplings, two of them load-bearing:

| Our usage | SDK symbol | Blast radius |
|---|---|---|
| 13 MCP tool classes (`src/Ucp/Mcp/Tool/Ucp*Tool.php`) | `Ucp\Sdk\Symfony\Operation\ShoppingOperationExecutor` and `ShoppingOperationRequest` | the entire Store API MCP surface breaks at once |
| `src/SwagAgenticCommerce.php:24,137,143` in `install()`/`update()` | `Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper` | **plugin installation** fatals, not just runtime |
| `tests/Unit/UcpMcpProxyControllerTest.php:32` | `Ucp\Sdk\Internal\Service\DefaultHttpRequestContextFactory` | test-only |
| `tests/Unit/UcpResponseSchemaTest.php:28,135` | `Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator` | test-only |

**The honest resolution is SDK-side, and it is filed there as `T31`.**
`ShoppingOperationExecutor`/`ShoppingOperationRequest` are the natural public
"execute a UCP operation on any transport" seam, and `SchemaBootstrapper` is the
natural public installer API. Promoting them brings them under the SDK's
public-API snapshot and Roave — which is the point, since we already depend on
them as if they were public. Doing it plugin-side instead means reimplementing
the executor, which is worse.

**This issue is the plugin half:** once SDK `T31` lands, drop any local
suppression, and replace the two test-only `Internal\` usages with the public
seam SDK `T1` introduces (`SchemaDirectoryLocator` for the schema directory, the
public request-context factory interface for the other).

**Acceptance.** No `Ucp\Sdk\Internal\` reference remains in `src/` or `tests/`.
No reference to a class still marked `@internal` in the installed SDK remains in
`src/`. `docs/public-api-boundaries.md` records which SDK symbols we treat as
public API.

**Effort.** M · **Depends on.** SDK `T31`, SDK `T1`

## P4 — `feat(routing): follow the SDK catalog-product route change`

**Why.** SDK `T7` adds `POST /ucp/v1/catalog/product` (the shape the upstream
OpenAPI document has defined at both protocol versions) and moves the
non-conformant `GET /ucp/v1/catalog/product/{id}` behind
`legacy_routes.catalog_product_get`.

Our routes are imported wholesale from the SDK bundle
(`src/Resources/config/routes.php:34-39`), so the new route arrives
automatically. What does **not** update automatically:

**Files.** `bin/lib/smoke/discovery.sh` and `bin/validate-ucp-store.sh` assert on
the discovery shape and route behaviour; `src/Resources/config/services.php:159-174`
must decide the `legacy_routes` flag; `tests/e2e/ucp/*` fixtures;
`docs/manual-testing.md`.

**Recommendation.** Keep the legacy GET route **on** for one plugin minor. We
have shipped it to merchants even though it was never conformant, so removing it
in the same release that adds POST is a needless break. Announce the removal in
the CHANGELOG at the same time.

**Acceptance.** Smoke scripts exercise POST. A merchant upgrading gets both routes
in the first release and a deprecation notice.

**Effort.** S · **Depends on.** SDK `T7`

## ~~P5 — reconcile capability ids with the SDK~~ — withdrawn, premise false

Written on the claim that the SDK publishes `dev.ucp.common.identity` while we
publish `dev.ucp.common.identity_linking`, making the SDK "the side that is
wrong". **Verified false**: there is no bare `dev.ucp.common.identity` anywhere in
the SDK, and both of its example apps already publish
`dev.ucp.common.identity_linking`. Our `UcpCapabilityCatalog.php:27` agrees with
it and with upstream. Nothing to reconcile.

The corresponding SDK task (`T9`) is withdrawn for the same reason; see its entry
in that repository's backlog for the evidence.

What remains real, and is not this: we publish `dev.ucp.shopping.catalog`
(singular) while the SDK's `UcpCapability` enum knows
`dev.ucp.shopping.catalog.search`, `.lookup` and `.product`. Negotiation is
name-keyed today, so it is worth checking whether that name matches anything at
all on the SDK side — but that is a question about capability granularity, and it
belongs with `P9`, once SDK `T20` makes negotiation version-aware and strict.

## P6 — `feat(admin): render keys[] in the profile preview`

**Why.** SDK `T16` changes the profile's JSON key from `signing_keys` to `keys`.
The PHP property stays `$signingKeys`, so PHP-side code is unaffected — but
anything reading the *JSON* is not.

**Files.** `src/Resources/app/administration/` — anything consuming the
`profile-preview` or `platform-profiles` payload from `UcpAdminController`
(`:105`, `:130`); `tests/e2e/fixtures/shopware.js`,
`tests/e2e/fixtures/ucp-protocols.js`, `tests/e2e/ucp/profile.spec.js`;
`tests/jest/administration/core/service/ucp-admin.api.service.spec.js`;
`docs/manual-testing.md` if it shows profile output.

**Acceptance.** The admin profile preview renders keys from a `keys[]` payload;
e2e profile assertions pass against the new shape.

**Effort.** S · **Depends on.** SDK `T16`

## P7 — `feat!: handle integer-or-measure quantities`

**Why.** SDK `T17` changes `LineItem::$quantity` from `int` to a `Quantity` value
object, because UCP `2026-08-25` allows a structured `measure` object for
weight- and length-priced goods. Three of our reads are affected, one of them
silently:

- **`src/Ucp/Gateway/ShopwareCartGateway.php:146`** — `if ($existing->getQuantity() !== $item->quantity)`. A strict `!==` between a Shopware `int` and a `Quantity` object is **always true**, so every cart sync would issue a redundant update. Silent, not fatal, and the most likely thing to ship unnoticed.
- **`:149` and `:160`** — `'quantity' => $item->quantity` passed straight into Store API cart item add/update payloads. An object here fails at the Store API boundary.
- **`src/Ucp/Gateway/ShopwareDataMapper.php:406-413`** — construction, with `int $quantity` in the private factory signature at `:398-405`.

**Design.** Shopware carts are integer-quantity. Our correct behaviour is to
accept a measure on the wire, **reject it with a UCP error descriptor at the
adapter boundary** until Shopware supports fractional line items, and keep
emitting integers. Do not silently floor or round — that is how the current
`(int)` cast bug behaves.

**Files.** the three sites above;
`tests/Unit/Ucp/Gateway/ShopwareCartGatewayTest.php`;
`tests/Unit/ShopwareDataMapperTest.php`; the MCP tool descriptions if they
document quantity.

**Acceptance.** A measure-quantity cart request returns a typed UCP error
descriptor, not a silently-quantity-1 cart. An integer-quantity request behaves
exactly as before. The cart-sync comparison at `:146` does not fire spuriously.

**Effort.** M · **Depends on.** SDK `T17`, and `P2` (without named arguments the construction change is invisible)

## P8 — `feat!: absorb the fulfillment and payment-credential restructure`

**Why.** SDK `T19` carries UCP `2026-08-25`'s fulfillment restructure: config
flags drop the `allows_` prefix (`multi_destination`, `method_combinations`),
`fulfillment_option.description` goes from a flat string to a structured object,
`multi_destination` goes from a map to an array of objects,
`fulfillment_available_method.type` opens from an enum to a string, and
destinations require explicit tagged `shipping`/`pickup` types. It also splits PAN
and network token into distinct credential types.

We reach deep into that JSON by string path:

**Files.** `src/Ucp/Checkout/CheckoutGuestAddressPayloadResolver.php:26,57-58,97,261`
(reads `$fulfillment->extra` and walks `fulfillment.methods[].destinations[]`);
`src/Ucp/Customer/GuestCustomerAddressResolver.php:31,36,42,47,78`;
`src/Ucp/Adapter/ShopwareCheckoutAdapter.php:62,158` (passes
`$request->fulfillment` in); `src/Ucp/Payment/ShopwareInvoicePaymentHandler.php:39`
(`$instrument->credential['payment_method_id']` — check against the credential
split); `src/Ucp/Capability/CheckoutCapability.php` config map (the `allows_`
flags); `tests/Unit/CheckoutGuestAddressPayloadResolverTest.php`.

**Acceptance.** A `2026-08-25`-shaped fulfillment payload with explicit
destination types resolves a guest address correctly; an array-shaped
`multi_destination` is handled; the invoice handler still resolves its payment
method under the split credential types.

**Effort.** M · **Depends on.** SDK `T19`, `P2`

## P9 — `test: verify negotiation still matches under version-aware intersection`

**Why.** Today's SDK negotiation is name-only and therefore permissive. SDK `T20`
makes it strict on versions and adds `requires` range intersection. Any
descriptor whose version drifts from the negotiated protocol version starts being
**excluded** rather than silently accepted.

We publish `UcpProtocol::VERSION` on most descriptors but **hardcode
`'2026-04-08'`** at `src/Ucp/Payment/ShopwareInvoicePaymentHandler.php:25`
instead of using the constant. Fix that here so `P10` has one fewer site.

**Also relevant.** `SwagAgenticCommerce::install()`/`update()` call
`SchemaBootstrapper::ensureSchema()` (`src/SwagAgenticCommerce.php:135-144`), and
SDK `T20` adds a column to the negotiation-session table. Verify the SDK's schema
bootstrap is additive and idempotent, or plugin updates break on existing
installs.

**Files.** `src/Ucp/Payment/ShopwareInvoicePaymentHandler.php:25`;
`tests/Functional/Ucp/UcpCartFlowTest.php`, `UcpCatalogFlowTest.php`,
`UcpCheckoutFlowTest.php`, `UcpFlowTestBehaviour.php`;
`tests/Integration/` for the schema-update path.

**Acceptance.** The functional UCP flow tests still negotiate every capability we
publish. A plugin `update()` against an existing install adds the new column
without error.

**Effort.** S · **Depends on.** SDK `T20`

## P10 — `feat!: bump the plugin to UCP 2026-08-25`

**Why.** SDK `T21` flips the active protocol version. We carry the version in
**five independent source locations plus a build artifact**, and one test reads
SDK internals off disk.

**Files.**
- `src/Ucp/UcpProtocol.php:10` — `public const VERSION` , the canonical constant
- `src/Resources/config/services.php:160` — the `ucp_sdk` extension config
- `src/Resources/config/packages/ucp_sdk.yaml:2` — the duplicate (resolve via `P16` first)
- `src/Ucp/Payment/ShopwareInvoicePaymentHandler.php:25` — a hardcoded literal that should be `UcpProtocol::VERSION` (fixed in `P9`)
- `src/Resources/app/administration/src/extension/sw-sales-channel/agentic-commerce/ucp-protocol.js:1` — `export const UCP_VERSION`
- `src/Resources/public/static/js/*.js` — the compiled admin bundle carries `ucpVersion:"2026-04-08"`; needs a rebuild
- **`tests/Unit/UcpResponseSchemaTest.php:135`** — instantiates `GeneratedSchemaValidator` against `<sdk>/resources/schema/generated/2026-04-08`. **This test breaks the moment SDK `T21` deletes that directory.** It is also the second `Internal\` violation from `P3`; the clean fix is the public `SchemaDirectoryLocator` SDK `T1` introduces.
- `src/Ucp/Gateway/ShopwareDataMapper.php:141,156,157` — three commit-pinned SDK schema URLs in comments
- Remaining `'2026-04-08'` assertions across `tests/Unit/` (roughly 8 files)

**Acceptance.** Discovery advertises `2026-08-25`; response envelopes carry
`2026-08-25`; the admin bundle is rebuilt and `bin/ci-assert-zip-admin-bundle.sh`
passes; no `2026-04-08` literal remains outside a deliberate backwards-compat
path.

**Effort.** M · **Depends on.** SDK `T21`, `P16`

## P11 — `test: verify response signing against our listeners`

**Why.** We set `signature_policy: strict` in both config locations and add our
own response listeners: `EmbeddedResponseListener` (CSP and origin) and the
profile cache-headers listener. SDK `T23` adds a `ResponseSignatureListener`. A
listener that mutates the body after signing invalidates the signature.

**Files.** `src/Ucp/Embedded/EmbeddedResponseListener.php`;
`src/Ucp/Profile/` cache-headers listener;
`src/Resources/config/services.php` listener priorities;
`tests/Functional/Ucp/`.

**Acceptance.** A signed UCP response passes external verification with our
listeners active. Embedded responses still carry CSP and origin enforcement.

**Effort.** S · **Depends on.** SDK `T23`

## P12 — `feat(checkout): act on the completion payment instrument`

**Why.** `checkout.complete` requires a `payment` object per spec and our MCP tool
already sends one, but the instrument is not acted on: completion always charges
the sales-channel default (invoice/offline). `full-ucp-parity-plan.md` records
this as blocked on an upstream SDK change. **It is not** — the SDK has shipped
the seam since 0.0.3.

**Files.** `src/Ucp/Capability/CheckoutCapability.php` — implement
`Ucp\Sdk\Contract\PaymentAwareCheckoutCapabilityInterface`
(`completeCheckoutFromRequest(CheckoutCompleteRequest, RequestContext)`);
`src/Ucp/Adapter/ShopwareCheckoutAdapter.php` — implement
`Ucp\Sdk\Adapter\PaymentAwareCheckoutAdapterInterface`;
`src/Ucp/Checkout/CheckoutCompleter.php`;
`src/Ucp/Payment/ShopwareInvoicePaymentHandler.php`;
`src/Resources/config/services.php:263-270` aliases.

**Note.** Because we hand-write the capability rather than using
`AdapterBackedCheckoutCapability`, both the capability and the adapter need the
opt-in interface — the SDK's "one-method change" note assumes the adapter-backed
path.

**Acceptance.** Completing a checkout with a `payment` naming a non-default
Shopware payment method charges that method. Completing without one keeps the
current default behaviour. The idempotency and Symfony-Lock guards around
completion are unaffected.

**Effort.** M · **Depends on.** nothing — startable now

## P13 — `feat(cart): emit the discount breakdown via Cart.extra`

**Why.** Applied discounts are reported only as a negative `items_discount`
total. The spec's richer `discounts.applied[]` breakdown
(`discount.json` → `$defs.applied_discount`, with per-target `allocations`) is
not emitted. `full-ucp-parity-plan.md` says the SDK's `Cart` has no `extra`
escape hatch — **it does**, `Cart.php:26`, since SDK 0.0.3.

**Files.** `src/Ucp/Gateway/ShopwareDataMapper.php` (cart mapping around
`:66-75`, discount totals around `:389`);
`src/Ucp/Adapter/ShopwareDiscountAdapter.php`;
`tests/Unit/ShopwareDataMapperTest.php`.

**Acceptance.** A cart with an applied promotion emits `discounts.applied[]` with
per-target allocations alongside the existing `items_discount` total, and
validates against the pinned cart response schema.

**Effort.** S · **Depends on.** nothing — startable now

## P14 — `refactor(mcp): drop the duplicated cart id in cart.update`

**Why.** UCP `2026-08-25` standardises `cart.id` as omitted in update requests,
so the tool argument and the payload no longer both need it.

**Files.** `src/Ucp/Mcp/Tool/UcpCartUpdateTool.php` (argument and description);
`src/Ucp/Mcp/Tool/UcpMcpToolContext.php` if it does the merging;
`docs/mcp-dry-run.md`; `tests/Unit/UcpMcpToolContextTest.php`.

**Acceptance.** `cart.update` accepts a payload without `id` and still resolves
the cart from the tool argument. The tool description no longer documents the
duplication.

**Effort.** S · **Depends on.** SDK `T21`

## P15 — `test(conformance): run the UCP conformance suite against a real store`

**Why.** Upstream publishes a language-agnostic conformance suite
([Universal-Commerce-Protocol/conformance](https://github.com/Universal-Commerce-Protocol/conformance),
pytest, runs against any live UCP merchant server). The SDK is adopting it against
its example app (SDK `T12`). **We are the more valuable target** — a real
Shopware store is the actual product — and we already have most of the harness:
docker-compose lanes generated by `bin/ci-write-compose.sh` across 6.5.x, 6.6.x
and trunk, plus `bin/validate-ucp-store.sh`, `bin/lib/smoke/discovery.sh` and
`SeedSmokeCatalogCommand`.

**Design.** Reuse the fixture-config shape from SDK `T12`
(`conformance_input.json` plus `test_fixtures.json`, owned here because they
describe *our* store). Clone the suite at a pinned tag rather than vendoring — a
pytest tree in this repo would drag Python into phpstan, php-cs-fixer and the ZIP
build. Seed via `SeedSmokeCatalogCommand`; note it is currently excluded from the
service load (`services.php:178-189`) and non-prod gated. Land advisory
(`continue-on-error: true`, matching the existing advisory lanes), then promote
per module.

**Files.** new `.github/workflows/conformance.yml`; new
`bin/ci-conformance.sh`; new `tests/conformance/{conformance_input.json,test_fixtures.json,README.md}`;
`bin/ci-write-compose.sh`; `src/Ucp/Command/SeedSmokeCatalogCommand.php`
(out-of-stock and discount fixtures, mirroring SDK `T11`);
new `docs/conformance.md`.

**Acceptance.** The suite runs against a booted store in at least one Shopware
lane and uploads a JUnit artifact with a per-module summary. `docs/conformance.md`
records the promotion procedure and the current allowlist.

**Effort.** L · **Depends on.** SDK `T12` (for the fixture-config shape), `P4`

## P16 — `chore: de-duplicate the ucp_sdk config`

**Why.** `src/Resources/config/services.php:159-174` and
`src/Resources/config/packages/ucp_sdk.yaml:1-12` set overlapping `ucp_sdk`
values, and the YAML omits `profile_fetching_development_mode`. The only
reference to the YAML file is `bin/ci-smoke.sh:364-375`, and only to assert what
it must **not** contain (no `sqlite:`, no `resolve:DATABASE_URL`). So either it is
dead config that CI still validates, or merge order silently decides which values
win.

Resolve it before `P10` turns it into a two-place version bump.

**Files.** `src/Resources/config/packages/ucp_sdk.yaml`;
`src/Resources/config/services.php:159-174`; `bin/ci-smoke.sh:364-381`.

**Acceptance.** Exactly one place sets `ucp_sdk`. The smoke assertions still
cover whichever file survives — in particular that `DATABASE_URL` is **not**
`resolve()`d, so percent-encoded DSNs stay intact (`bin/ci-smoke.sh:377-381`).

**Effort.** S · **Depends on.** nothing — startable now

## P17 — `docs: correct the plugin parity plan`

**Why.** Three items under `## Remaining Runtime Gaps` are recorded as blocked on
upstream and are not. See "Corrections to `full-ucp-parity-plan.md`" above.

Also disambiguate: **both repositories contain a `docs/full-ucp-parity-plan.md`
with different content.** Ours is the Shopware support matrix and implementation
decisions; the SDK's was transport metadata and now points at its upgrade
backlog. Cross-reference explicitly.

**Files.** `docs/full-ucp-parity-plan.md:152-188`; a pointer to this document.

**Effort.** S · **Depends on.** nothing — startable now

---

## Cross-repo ordering

```
P1 ────────────────────────────────► must precede SDK T17 reaching merchants
P2 ────────────────────────────────► must precede SDK Wave 3
P3 ⇄ SDK T31 (promote ShoppingOperation* + SchemaBootstrapper)

SDK T7  → P4        SDK T9  → P5        SDK T16 → P6
SDK T17 → P7        SDK T19 → P8        SDK T20 → P9
SDK T21 → P10, P14  SDK T23 → P11       SDK T12 → P15

P12, P13, P16, P17 : independent, start now
```

## Our CI is already an SDK canary

`.github/workflows/ci.yml:41` defaults `UCP_SDK_REF` to `main`, so our functional
suite already runs against unreleased SDK code across three Shopware lanes
(`shopware-matrix`, `:308`). That is the earliest warning signal available for
every SDK slice.

**Treat a red plugin lane during the SDK upgrade as an SDK finding, not a plugin
one** — and consider raising its visibility for the duration of the upgrade,
because it is currently the only cross-repo integration test that exists.

## Related

- SDK-side backlog: `ucp-php-sdk` repository, `docs/ucp-2026-08-25-upgrade.md`
- [full-ucp-parity-plan.md](full-ucp-parity-plan.md) — our Shopware support matrix and implementation decisions
- [public-api-boundaries.md](public-api-boundaries.md) — what we consider BC surface
- [mcp-sdk-upgrade.md](mcp-sdk-upgrade.md) — the separate `mcp/sdk` version ceiling, unrelated to the UCP SDK
- Upstream spec: <https://github.com/Universal-Commerce-Protocol/ucp>
- Upstream conformance suite: <https://github.com/Universal-Commerce-Protocol/conformance>
