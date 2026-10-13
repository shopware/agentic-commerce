# UcpAdmin acceptance journeys: findings and plan

Status: implemented. Research and runs were done against `main` (`c57ec418`) and a local trunk (6.7-dev)
shop with the plugin at 1.4.0. Only trunk could be executed locally. Section 5 records where the
implementation departs from the plan below.

Scope: the remaining Administration journeys for the ATS suite in `tests/acceptance` (project and tag
`@UcpAdmin`). The full ACL matrix for `ucp.viewer`, `ucp.editor` and `ucp.key_rotator` belongs to #205.

## 1. Findings

### 1.1 Current state of the suite

- Only `tests/Setup/*` and `tests/KnownBlocked/path-prefixed-domain.spec.ts` exist. Nothing is tagged
  `@UcpAdmin` yet; the project is already defined in `playwright.config.ts`.
- The acceptance suite is not run by any workflow in `.github/workflows/`.
- ATS is pinned to 12.20.0 (core uses 12.21.2; the differences do not affect these journeys).
- `UcpTestDataService` already offers `createStorefront/Headless/FeedSalesChannel`, `activateUcp`,
  `saveUcpConfig`, `getUcpConfig`, `getProfilePreview`, `listUcpSalesChannels`, `refusalCode`.
- `UcpAclUsers.as(role)` creates a role, a non-admin user and a logged-in page.
- ATS `AdminPage` is a fresh page per test under a worker-scoped admin user `admin_<id>`. ATS has no page
  objects for the sales-channel list, create modal, sidebar filters or Insights.
- The plugin admin templates contain no `data-testid` (only `data-ac-template-select`). Core admin uses
  almost none either; where it does, names are BEM-like (`sw-icon__<name>`, `mt-select-option--<value>`).
- Feed, tracking and order helpers for the content journeys (A3/A4) exist only on the unmerged branch
  `origin/test-ucp-content-journeys` (#191): `createProductFeed`, `saveFeedSettings` and a real storefront
  `referringSalesChannel` journey. On `main`, `createFeedSalesChannel` creates no `product_export`.
- The legacy `tests/e2e` admin specs still gate CI (`bin/ci-admin-smoke.sh`, `ci.yml`). They use translated
  labels, bounding-box geometry and `firstSalesChannel`. Worth keeping from them: MCP expectation driven by
  `meta.supportsStoreApiMcp`, the docs-link href check, the browser console-error collector.

### 1.2 Discovery and known blocker D8

- Every test channel lives on `${APP_URL}test-<uuid>/`. `/.well-known/ucp` under a path prefix resolves the
  root channel (D8, `#264`), confirmed live for `/de-de/` and `/test-<id>/`.
- Admin `GET profile-preview` resolves prefixed channels correctly, so it is not a substitute for discovery:
  it skips exactly the component D8 breaks.
- Verified by prototype: a channel whose only domain is a root-path host
  `http://c<channelId>.ucp-acceptance.test:<APP_URL port>`, fetched by Chromium launched with
  `--host-resolver-rules=MAP *.ucp-acceptance.test <APP_URL hostname>`, returns HTTP 200 with its own
  endpoints and exactly its saved capabilities and transports. Host names are unique per channel, so it is
  parallel-safe. D8 stays a known blocker for prefixed domains.
- The resolver rule only applies to Chromium. Node clients (`page.request`, `AdminApiContext`) fail with
  `ENOTFOUND`, so the discovery fetch must go through `page.goto`.
- Server-side requirements: the web server must accept any Host (devenv Caddy `:8000` does) and
  `TRUSTED_HOSTS` must not be restrictive. With https, `ignoreHTTPSErrors` must be on.

### 1.3 Draft preview defect

- The tab posts the edited form to `POST /_admin/ucp/sales-channels/{id}/profile-preview` after a 400 ms
  debounce (`view/sw-sales-channel-detail-agentic-commerce/index.js:154-171`); errors are swallowed.
- Verified live: removing a transport is reflected, removing a capability is not, adding a transport is not,
  and a draft `active: false` still shows services.
- Cause: `CapabilityFilteringProfileContributor.php:32-70` re-resolves the channel and filters with the saved
  config, not the draft. Fixed, see section 5.
- Page load itself triggers one preview POST (the deep `form` watcher fires when `loadUcpState` sets the form).

### 1.4 Lane split for feed-channel UI

- On core >= 6.7.10 (trunk) `coreShipsAgenticCommerce` is true and `shouldRenderAgenticUi` is false. Core then
  renders the export config cards, the reset button, Insights (`productExportInsights` route) and the
  order/customer export-channel filters (`main.js:20` skips the plugin module).
- Plugin templates that render on every lane: the UCP tab (`registerOrOverride`), the tracking config
  (`registerOrOverride` replaces core's template), the template-select block, the list UCP column, the
  modal-grid badge. Plugin JS on every lane: create-base prefill and `syncExportFileName`.
- Only the UCP tab has two template variants: `.mt.html.twig` (6.6+) and `.sw.html.twig` (6.5).
- Risk for a follow-up issue: with feature flag `v6.8.0.0` core renders tabs from `salesChannelDetailTabs`
  and the plugin's UCP tab link (overriding `sw-tabs` blocks) disappears.
- Risk for a follow-up issue: the plugin's `loadSalesChannel` override initialises `maintenanceIpWhitelist`,
  not core's `maintenanceIpAllowlist`, on trunk.

### 1.5 Per-journey facts

| Topic | Facts |
|---|---|
| Save | No tab-local save. The page Save (`.sw-sales-channel-detail__save-action`) chains the sales-channel write, the system-config batch save and `PUT /_admin/ucp/sales-channels/{id}/config` with `{active, profileDomain, enabledCapabilities, enabledTransports}`. UCP config is stored in `swag_agentic_commerce_ucp_config` (no DAL entity); read back via `GET .../config`. |
| Dirty state | `isPreviewDirty(savedForm, form)`; banner `mt-banner` (6.6+) or an unclassed `sw-alert` (6.5). State survives sub-tab switches. |
| Navigate-away | No `beforeRouteLeave`, `beforeunload` or discard modal in the plugin or core `sw-sales-channel` (checked trunk, `v6.5.0.0`, `v6.6.0.0`). |
| MCP | `meta.supportsStoreApiMcp` is returned by `GET /_admin/ucp/sales-channels` and `GET .../{id}` (`UcpAdminController.php:62,82`), not by `/config`. Computed by `ShopwareVersionDetector::supportsStoreApiMcp()`. The UI filters MCP out when false (`ucp-options.js`). Local trunk reports `true`. Discovery advertises an `mcp` service at `<base>/ucp/mcp`. |
| Export template | Template select is the plugin block (all lanes); the confirm modal is core's `sw-modal`. `onTemplateModalConfirm` copies the template and sets `productExport.provider`; a provider watcher runs `syncExportFileName`, replacing the extension with `.jsonl`/`.xml` and setting `fileFormat`. Reset to default rewrites header/body/footer; nothing persists until Save. |
| Tracking | `sales_channel.configuration.affiliateCode` / `campaignCode`, feed channels only, saved by the page Save. |
| Insights | Same thing as Statistics. Trunk (core) counts `sales_channel_tracking_*` rows by `createdAt` (7-day default). Old lanes (plugin) filter orders by `orderDate` (30 days) and customers by `createdAt`. Turnover needs a `paid` transaction. |
| Attribution | Production path: `SalesChannelTrackingListener` writes tracking rows from the storefront `referringSalesChannel` session. `sales_channel_tracking_order` / `_customer` are Admin-API writable and cascade-deleted. |
| Filters | Core and plugin filters are identical: `export-channel-filter` on `salesChannelTracking.salesChannelId`, label "Export channel". Only one registers per lane. Filter state persists in `user_config` per admin user. |
| List column | Added only with `acl.can('ucp.viewer')`; data from `GET /_admin/ucp/sales-channels`; renders `sw-status` "exposed"/"off". |
| Create | Modal grid shows a sparkle icon and `.sw-sales-channel-modal-grid__agentic-badge`. Create-base prefills `fileName agentic-commerce-<id>.jsonl`, `provider open-ai`, `UTF-8`, cron off, interval 86400. |
| Feed tab | `isTransactionalSalesChannelType` only covers storefront and API; feed channels get Integration and Insights, never the UCP tab. |
| Backend refusal (#180) | Present: `UcpConfigService.php:150-153`, `UCP_SALES_CHANNEL_TYPE_NOT_SUPPORTED`. Already asserted in `tests/Setup/test-data.spec.ts:38-48`. |
| OpenAI return policy | `returnPolicyUrl` required in `openAiProductExport.xml`; checked only in the browser (`validateAgenticCommerceExportConfig`, plugin and identical core copy). Backend falls back to the seller URL. Validation is skipped until both `system-config` GETs have loaded. Label "Return policy URL" on all lanes. |

### 1.6 Attribute forwarding

`mt-checkbox`, `mt-switch`, `mt-banner`, `mt-text-field`, `mt-url-field` and `mt-card` forward attributes to
their root. `mt-select` does not (`inheritAttrs: false`). On 6.5, `sw-*` fields may put attributes on the
`<input>` (unverified, 6.5 not runnable locally). Hooks therefore go on wrappers, and controls are reached by
role inside them.

## 2. Plan (as implemented)

### 2.1 Test hooks

Namespace `ac-<area>__<element>[--<key>]`.

| Template | Hooks | Lanes |
|---|---|---|
| UCP tab `.mt` and `.sw` | `ac-ucp` (root), `ac-ucp__unsaved-banner`, `ac-ucp__expose-switch`, `ac-ucp__capability--<value>`, `ac-ucp__transport--<value>`, `ac-ucp__preview`. In the `.sw` template the switch and checkbox hooks sit on plain wrapper elements, because 6.5's `sw-*` fields drop attributes. | all |
| Tracking config | `ac-tracking__affiliate-code`, `ac-tracking__campaign-code` | all |
| Template select | `ac-export__template-select` on a plain wrapper | all |
| List UCP column | `ac-sales-channel-list__ucp-status--exposed`, `--off` | all |
| Modal grid | `ac-sales-channel-modal__agentic-badge` | all |
| Plugin statistics | `ac-insights__orders-total`, `ac-insights__customers-total` | < 6.7.10 |

A Jest spec asserts both template variants carry every UCP-tab hook, and that each 6.5 hook (and the template
select hook) sits on an element that keeps it. No new Twig blocks, so no blocks-list regeneration.

The Meteor template is used from 6.6.2.0, where core first ships the Meteor components; earlier lanes use the
`.sw` template.

### 2.2 Test infrastructure

`services/UcpTestDataService.ts`:

- `createStorefrontSalesChannelOnOwnHost()`: one root-path domain `c<id>.ucp-acceptance.test`, scheme and port
  copied from `APP_URL`.
- `createFeedSalesChannelWithExport(provider, exportOverrides)`: `domains: []`; `product_export` built from the
  plugin's shipped template (`readFeedTemplates()`) with `provider`, `fileName` extension and `fileFormat`
  consistent (otherwise the load-time `syncExportFileName` rewrites the record); unique `fileName`; storefront
  `DefaultSalesChannel` and its domain; a real product stream registered for cleanup.
- `attributeOrderToFeedChannel()`, `attributeCustomerToFeedChannel()` via sync.
- `createProductStream()`, `setChannelSystemConfig()`, `getChannelSystemConfig()`, `getSalesChannelReadback()`.
- `markUcpActivated(id)` and `adoptSalesChannel(id)`, so channels activated or created through the UI are cleaned
  up.

`services/pluginSource.ts`: `readFeedTemplates()` and `importWithShopwareStub()`, identical to the
content-journeys branch (#191).

`services/discovery.ts`: `fetchDiscoveryDocument(browser, url)`, a fresh browser context per read, no
cache-buster.

`page-objects/`:

- `UcpSalesChannelDetail`: `openAgenticCommerceTab()` and `reload()` wait for the three UCP loads, the load-time
  preview POST and a settled tab, and return `meta`. `saveUcpConfig()` waits for the PUT and then for the stored
  config to be loaded again. `showSubTab()` waits for the sub-tab or the legacy preview card. `offeredTransports()`
  reads the transport hooks.
- `AgenticCommerceFeedDetail`: `open()` waits for both provider-settings reads; template choice, confirm, cancel,
  reset, tracking fields, `save()` waits for the system-config batch.
- `adminRoute.ts`: `goToAdminRoute()`, in-app navigation like the ATS `goesTo()`.

`fixtures/UcpAclUsers.ts`: role users get the privileges the running Administration grants for their role keys;
`asSalesChannelViewer()` and `asFreshAdmin()` added.

`playwright.config.ts`: the `Setup` and `UcpAdmin` projects get the resolver rule in `use.launchOptions.args`.

`tests/Setup`: a probe that creates an own-host channel and fetches discovery. It fails (never skips) with a
message naming the vhost and `TRUSTED_HOSTS` requirements.

### 2.3 Specs

Every test creates its own channels. `DefaultSalesChannel` is never edited through the UI (a UI save bypasses
the config-restore bookkeeping).

`tests/UcpAdmin/ucp-configuration.spec.ts`

1. Capabilities and transports saved in the tab are served by discovery. Own-host channel seeded inactive with
   REST and A2A. UI: expose on, untick `discount` and `a2a`, tick `embedded`, banner visible. Save, banner gone
   on the settled tab. Reload: selections persisted. Discovery (read once, after the save): transports exactly
   `embedded` and `rest`, no `dev.ucp.shopping.discount`, endpoints on the own host.
2. Preview shows the draft before save and the saved profile after. Untick `cart` and `a2a`, tick `embedded`;
   wait for the preview POST with that body; the rendered JSON has `embedded` and `rest` and no cart, while the
   Admin `GET profile-preview` is unchanged. Save, reload, Preview: the stored profile matches.
3. MCP is offered exactly when the Admin API reports it. `meta` from the page's own `GET .../{id}` must be a
   boolean; `mcp` is among the offered transports exactly when it is true. Every offered transport is ticked and
   saved; discovery advertises exactly those. No version comparison.
4. Unsaved-changes banner: absent after load, visible after an edit, still visible on the other sub-tab, gone
   after reverting by hand, gone after edit plus save.
5. Leaving the tab with unsaved changes asks first: `test.fixme`, the feature does not exist.

`tests/UcpAdmin/sales-channel-surfaces.spec.ts`

1. A `ucp.viewer` sees the UCP column: "exposed" for an activated channel, "off" for another.
2. A user with only `sales_channel.viewer` sees the list without the UCP column.
3. The create modal offers the Agentic Commerce type with its badge; the merchant fills in what has no default
   and saves; the stored export carries the prefilled `open-ai`, `jsonl` and `agentic-commerce-<32 hex>.jsonl`.
4. A feed channel has no Agentic Commerce tab; a storefront channel has, with the same locator.

`tests/UcpAdmin/product-feed-export.spec.ts`

1. Changing the template asks for confirmation, then stores the Google template with `*.xml` and
   `fileFormat xml` (Admin API readback).
2. Cancelling the template change keeps the OpenAI template and file.
3. Reset to default restores the provider's template.
4. OpenAI without a return policy URL cannot be saved: the field shows its error, nothing is written, nothing
   stored; with the URL the save goes through.
5. Tracking codes (unique per test) entered in the UI are in `sales_channel.configuration`.

`tests/UcpAdmin/export-channel-attribution.spec.ts` (marked slow: heavy API setup)

1. The order list filters by feed channel: one attributed and one direct order of a buyer with a unique last
   name; after the filter the list is read again from a reload, because core's listing can let an unfiltered
   search land last.
2. The customer list filters by feed channel, same pattern.
3. Insights count exactly 2 orders and 2 customers attributed to the feed, with a referred customer's direct
   order and a sale through a second feed present.

### 2.4 Waiting rules

Waits are armed before the triggering action; web-first assertions; modals visible, click, hidden; no
`waitForTimeout`; debounced preview requests are matched on body, never counted.

### 2.5 Requirement coverage

| # | Requirement | Plan |
|---|---|---|
| 1 | Stable selectors | Hooks in 2.1; Jest guards both UCP template variants |
| 2 | UI, save, reload, discovery | ucp-configuration 1 (real discovery via own host) |
| 3 | Preview draft and saved | ucp-configuration 2 (capability draft defect fixed, section 5) |
| 4 | Unsaved changes | ucp-configuration 4; navigate-away recorded as `test.fixme` (see 3) |
| 5 | MCP via `supportsStoreApiMcp` | ucp-configuration 3 |
| 6 | Export template | product-feed-export 1-3 |
| 7 | Tracking config | product-feed-export 5 |
| 8 | Insights, list column, create, feed tab | sales-channel-surfaces 1-4, attribution 3; backend refusal reused from Setup |
| 9 | OpenAI validation | product-feed-export 4 (frontend validation; no backend rule exists) |
| 10 | Order/customer filters | export-channel-attribution 1-2 |
| 11 | Statistics | export-channel-attribution 3 |

Excluded: #205 ACL matrix (viewer vs editor states, key rotation, per-route negative ACL, card visibility per
privilege combination). Kept in Jest: `isPreviewDirty`,
`toggleArrayValue`, `availableTransports`, `syncExportFileName` edge cases, exact prefill values, sub-tab
resolution. Real storefront attribution stays with the UcpContent branch (#191). Legacy `tests/e2e` stays
untouched.

## 3. Decisions

- Navigate-away warning: left BLOCKED. The feature does not exist in the plugin or core; no placeholder test.
  Needs a feature issue if wanted.
- Draft preview capability defect: fixed in PHP instead of a D9 known blocker (section 5).
- Own-host discovery: gating. A `@Setup` spec fails, naming the vhost and `TRUSTED_HOSTS` requirements, on a
  lane that cannot serve per-channel hosts.

## 4. Validation plan and limits

- `npm run lint`, `npm run typecheck` in `tests/acceptance` (Node 24).
- Admin Jest for the hook spec; admin ESLint for touched templates.
- `npx playwright test --project=Setup --project=UcpAdmin` on local trunk, then `--repeat-each=3`.
- Not runnable locally: 6.5, 6.6 and 6.7 below 6.7.10.
- Local signing-key cleanup through `bin/console` fails with "Unable to decrypt private key material" (CLI and
  web secret mismatch in the local devenv), so local runs leave keys behind with a warning.
## 5. Implementation outcome

### 5.1 Departures from the plan

- **Defects fixed, not reported:**
  - `isPreviewDirty` compared value lists by order, so unticking and re-ticking a capability left the unsaved
    banner on. It now compares string lists as sets (`ucp-profile-preview.js`, Jest).
  - The draft preview ignored capability edits and transport additions. `ProfilePreviewBuilder` now sets the
    rendered config on the new `ProfileDraftConfig` for the duration of one build, and
    `CapabilityFilteringProfileContributor` filters with it instead of reloading the stored config. Real
    discovery builds are unchanged. As a side effect `GET profile-preview` filters with the channel's own
    stored config rather than the config of whichever channel its first domain resolves to (PHP unit tests).
- **No D9 known blocker**, since the defect is fixed.
- **Test hooks:** the list column carries `ac-sales-channel-list__ucp-status--exposed` and `--off`; the UCP tab
  root carries `ac-ucp`.
- **Create journey saves.** The prefilled file name is not shown on the create form, so the test fills what has
  no default, saves, and reads the export back. The new channel is registered for cleanup from the id in its
  create request.
- **Page objects** live in `page-objects/` (`UcpSalesChannelDetail`, `AgenticCommerceFeedDetail`,
  `goToAdminRoute()`), matching the existing `@page-objects/*` path. Discovery is read through
  `services/discovery.ts`.
- **Fixtures:**
  - `UcpAclUsers.asFreshAdmin()` gives the list-filter specs a user of their own.
  - `UcpAclUsers.as()` now adds what the running Administration's privileges service grants for the role keys,
    so plugins that extend `sales_channel.viewer` (for example SwagSocialShopping) do not make the list 403
    for the test user.
- **Insights** locators use the plugin hook or core's BEM summary; no component-registry probe was needed.

### 5.2 New findings

- **Discovery cache:** `/.well-known/ucp` is sent `public, max-age=60` (the UCP specification requires at least
  60 seconds), and Shopware's HTTP cache serves it until it expires; a config save does not invalidate it.
  Merchants see a saved change in discovery after up to a minute. The journey therefore reads discovery once,
  after the save.
- **Navigation under load:** reloading the Administration for every route made the heaviest journeys hit the
  60-second test timeout on the local debug-mode devenv at the default worker count. Navigating inside the
  running Administration (`goToAdminRoute()`, as the ATS `goesTo()` does) removed that; `--repeat-each=3`
  passes at 3 workers and at the default worker count.
