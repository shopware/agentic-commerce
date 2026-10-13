# UcpAdmin acceptance journeys: review

Review of the uncommitted change on branch `193/administration-journeys` (base `main` `c57ec418`, no commits on
the branch). Six independent reviewers (acceptance criteria, Shopware core/ATS, domain, flakiness,
false-positive, scope) plus a challenge round. Only the local trunk (6.7-dev) shop was executed.

## 1. Verdict

`NOT READY FOR APPROVAL` (at review time). The HIGH and MEDIUM findings have since been addressed; see
section 12 for what changed and how each fix was verified.

## 2. Executive summary

The change gets most things right, but four problems block approval:

- **What's right:** the core journeys hit real boundaries (Admin API save, reload, real `/.well-known/ucp`, real
  preview route, Admin API readbacks). The two production fixes (draft preview and the order-insensitive dirty
  check) were judged correct and in scope. Test data is isolated and cleans up correctly.
- **6.5 lane:** the new test hooks on the 6.5 checkbox, switch and template-select fields never reach the DOM, so
  those journeys fail there every time. The plugin requires `~6.5.8`, so this is a supported lane.
- **Banner:** the "save clears the banner" check can pass while the tab is unmounted, so that criterion isn't
  proven.
- **Insights:** the test can't tell a correct count from a hardcoded "1".
- **Flakiness:** the parallel run failed intermittently, and the cause is undetermined.

No blockers. All six reviewers agree with the verdict.

## 3. Acceptance criteria matrix

| Requirement | Status | Evidence | Test | Notes |
|---|---|---|---|---|
| 1 Stable hooks | PARTIAL | Hooks work on trunk (all journeys pass). On v6.5.8.9, `sw-checkbox-field` and `sw-select-field` set `inheritAttrs:false` and pass `$attrs` only to `sw-base-field`, which binds them only `{% if VUE3 %}` (off by default). | Jest `…-test-hooks.spec.js` (string match only) | Verified from source. The reset button and template modal still use BEM classes. |
| 2 UI → save → reload → discovery | PROVEN (trunk) | Inactive pre-seed; the UI turns UCP on, drops discount and A2A, adds Embedded. A no-op save leaves services `{}`, failing UC:45. | `ucp-configuration` test 1 | Discovery is read once, after the save, because of the 60 s public cache. |
| 3 Preview draft and saved | PROVEN (trunk) | Draft POST matched on its body; stored profile still shows A2A and cart at UC:74-75. Needed the `ProfileDraftConfig` fix. | test 2, plus PHPUnit | A draft with UCP switched off is not asserted (LOW). |
| 4a Banner on edit | PROVEN | UC:25, :120 | tests 1 and 4 | |
| 4b Save clears banner | PARTIAL | Plugin `onSave` calls `loadEntityData()` after the PUT (`index.js:373`). Core shows `router-view` only when not loading (`sw-sales-channel-detail.html.twig:167-173`). `toBeHidden` at UC:29 and :132 can pass while the tab is unmounted. | tests 1 and 4 | Removing the `savedForm` reset at `index.js:262` would go uncaught. |
| 4c Navigate-away warning | MISSING, out of scope by user decision | No route-leave guard in the plugin or core (trunk, v6.5.8.9, v6.6.10.9). | none | A product feature gap. Not recorded in the suite or a known-blocker entry. |
| 5 MCP via `meta.supportsStoreApiMcp` | PARTIAL | Helper `ucp-options.js:27` uses the flag only; Jest `ucp-options.spec.js` covers the helper. | test 3, Jest | A version check inside the component would pass on trunk (no `MCP_SERVER` flag there, so flag and version agree). |
| 6a Confirmation modal | PROVEN | `AgenticCommerceFeedDetail.ts` waits for the modal to show | `product-feed-export` 1 | Cancel path untested (LOW). |
| 6b Confirm applies template | PROVEN | Readback of provider and templates, PF:25 | 1 | |
| 6c File name synced | PROVEN | `.jsonl` → `.xml` plus `fileFormat`, PF:27-28 | 1 | The plugin watcher runs on trunk too. |
| 6d Reset to default | PROVEN | Custom body seeded, then readback equals the shipped default, PF:46 | 2 | |
| 7 Tracking codes | PROVEN | Readback of `sales_channel.configuration` through the Admin API, PF:92 | 4 | |
| 8a Insights cards | PARTIAL | Correct count passes; a hardcoded "1" also passes. | attribution 3 | On trunk this runs core's view, not the plugin's. |
| 8b UCP column, ACL-sensitive | PROVEN for `ucp.viewer` positive; negative covered by Jest | SS:41-55; Jest `sw-sales-channel-list.spec.js:18,22` fails if the gate is removed | surfaces 1, Jest | Real role resolution for a user without `ucp.viewer` belongs to #205. |
| 8c Create modal type | PROVEN | Type button plus badge | surfaces 2 | |
| 8d Create prefill | PROVEN (trunk) | Readback of `open-ai`, `jsonl`, file-name pattern | surfaces 2 | On ≥6.7.10, core's own prefill would mask removal of the plugin's override. |
| 8e Feed channel has no tab | PROVEN | Href-based check after Integration renders, plus a storefront control | surfaces 3 | |
| 8f #180 backend refusal | PROVEN (existing test) | `UcpConfigService.php:150-153`; `Setup/test-data.spec.ts:38-48` | Setup | Already merged into `main`. |
| 9 OpenAI return policy | PROVEN (UI-level) | Field error, nothing written, nothing stored, then a valid save | `product-feed-export` 3 | There is no backend rule: an API client can save without the URL. |
| 10 Order and customer filters | PROVEN on trunk (core module) | The search carries `salesChannelTracking.salesChannelId`; the unattributed row disappears | attribution 1–2 | The plugin's own filter module never runs on trunk. |
| 11 Statistics count | PARTIAL | Same as 8a | attribution 3 | |

Paths: UC = `tests/acceptance/tests/UcpAdmin/ucp-configuration.spec.ts`, PF = `…/product-feed-export.spec.ts`,
SS = `…/sales-channel-surfaces.spec.ts`, EA = `…/export-channel-attribution.spec.ts`.

## 4. Findings

### HIGH-1: test hooks don't reach the DOM on 6.5

- **Where:** `sw-sales-channel-detail-agentic-commerce.sw.html.twig:25,52,70`, `sw-sales-channel-detail-base.html.twig:41`,
  `UcpSalesChannelDetail.ts:99-102`.
- **Evidence:** v6.5.8.9 `sw-checkbox-field/index.js:20` and `sw-select-field/index.js:26` set `inheritAttrs:false`.
  `sw-base-field/index.js:16` does too, and its template binds `$attrs` only under `VUE3`, which `feature.yaml:13-14`
  defaults to off.
- **Impact:** the UCP-tab and template-change journeys fail deterministically on a supported lane. The Jest hooks
  spec can't detect this because it only reads template text.
- **Fix:** put each hook on a plain wrapper element in the 6.5 template and in detail-base.

### HIGH-2: criterion 4b isn't proven

- **Where:** UC:29, UC:132.
- **Problem:** the banner check can pass during the post-save skeleton.
- **Fix:** after saving, wait for the reload's `GET /config` and a visible anchor such as a checkbox, then assert the
  banner is hidden.

### HIGH-3: the Insights assertion can't detect wrong counts

- **Where:** `export-channel-attribution.spec.ts:77-95`.
- **Problem:** with one attributed order, a hardcoded "1" or "orders of attributed customers" also passes.
- **Fix:** seed two attributed orders and customers on feed A plus one on feed B, and assert exactly 2.

### HIGH-4: intermittent failures under parallel load

- **Evidence:** across four `UcpAdmin` ×3 runs at the default worker count, one run had 2 failures (order filter,
  Insights); serial runs are clean.
- **Cause:** undetermined, because later runs overwrote the artifacts. The likely cause is heavy setup within the
  60 s timeout (INFERENCE).
- **Fix:** capture artifacts, lighten setup or use `test.slow()`, then re-measure.

### MEDIUM

- **MCP:** a version check inside the component is uncaught; add a component-level Jest test.
- **Sub-tab and reload sync:** `showSubTab` uses a non-waiting `count()`, and `reload()` waits can be satisfied by
  responses still in flight from the save. This causes timeouts, never false passes. Use a DOM-based wait such as
  `subTab.or(preview)`.
- **Unexercised plugin code:** the plugin's Insights view and filter module never ran (trunk uses core's).
- **Navigate-away:** the gap isn't recorded in the suite.
- **Duplication:** the new helpers duplicate `origin/test-ucp-content-journeys` (#191) and will conflict with it.
- **6.6.0/6.6.1 (pre-existing):** these versions ship no Meteor components, so the 6.6+ template has no inputs there.
- **6.5/6.6 save race (pre-existing):** `loadEntityData` can run before the UCP PUT, which then writes the old config
  back and silently loses the merchant's edits. Rated HIGH as a product bug; it needs its own issue.

### LOW

- No end-to-end check that a user without `ucp.viewer` sees no column. Reviewer A dissents, see section 11.
- `data-ac-template-select` duplicates the new hook and nothing uses it.
- An unnecessary promise queue in `pluginSource.ts`.
- A misleading cache comment in `discovery.ts`.
- `docs/ucp-admin-acceptance-plan.md` §2 is out of date.
- Two lint warnings in the MCP test.
- `UcpAclUsers` now depends on `AdminPage`, and still unions a hard-coded privilege list.
- A draft with UCP switched off is not asserted.
- Template-modal cancel path untested.
- Insights timezone edge near midnight.
- No changelog entry for the preview fix (observation only).

## 5. False-positive analysis

| Regression | Caught by |
|---|---|
| Save becomes a no-op | UC:35 after reload; also UC:45 |
| Reload restores defaults | UC:35-39 |
| Discovery ignores transports or capabilities | UC:45 / UC:47 |
| Preview ignores the draft, or always shows the saved state | UC:70-71 |
| Dirty banner never appears | UC:25 |
| **Banner not cleared after save** | **not reliably caught (HIGH-2)** |
| **Navigate-away warning removed** | **nothing to regress; feature absent** |
| **MCP becomes version-based in the component** | **not caught (MEDIUM)** |
| `syncExportFileName` no-op | PF:28 |
| Campaign code not saved | PF:92 |
| **Insights hardcoded or miscounting** | **partly; "count every order" is caught, a hardcoded 1 is not (HIGH-3)** |
| UCP column shown without the ACL | Jest list spec, lines 18 and 22 |
| Agentic Commerce tab on a feed channel | SS:107 |
| Missing return policy no longer blocks save | PF:62-66 |
| Order or customer filter ignored | EA:56 / EA:74 on core; plugin module unexercised |
| #180 refusal removed | Setup:46-47 |

## 6. Shopware / ATS conformity

- **Navigation:** `goToAdminRoute` matches ATS `Actor.goesTo` (`index.mjs:4122-4144`).
- **Login and context:** login and admin-context helpers are identical in pinned ATS 12.20.0 and core's 12.21.2.
- **Cleanup order:** follows the ATS fixture. FK rules checked in the live schema: `product_export→product_stream`
  RESTRICT (handled by deleting channels first); export, tracking and `system_config` CASCADE from the channel.
- **Privileges API:** the API `UcpAclUsers` now calls exists on v6.5, v6.6 and trunk.
- **Test-id convention:** core admin has essentially no `data-testid` convention, so `ac-<area>__<element>` collides
  with nothing.
- **Deviation:** attribute forwarding on 6.5 `sw-*` fields (HIGH-1).

## 7. Isolation and flakiness

- **Data:** each test owns its channels, hosts, unique names and last names. List filters run on a per-test admin.
  Discovery cache keys never repeat.
- **Cleanup:** runs even after a mid-test failure. UI-created channels are registered for cleanup from the create
  request, and UI activations before the save.
- **Waits and selectors:** no sleeps or translated selectors. Response waits are armed before their actions.
- **List search (Reviewer D's H3):** withdrawn. The admin search splits terms only on spaces on every lane.
- **Open problems:**
  - HIGH-4 (parallel intermittency);
  - the sub-tab and reload sync (MEDIUM);
  - CI retries (`retries: 2`) would show these as "flaky" rather than failing.

## 8. Scope assessment

- **Scope:** the change stays within the ticket.
- **Production changes:** limited to test hooks plus two behaviour fixes needed for criteria 3 and 4.
- **#205:** no ACL-matrix work leaked in.
- **Jest-level checks in Playwright:** minor overlap only: the banner revert step, the MCP checkbox count, and the
  badge count.
- **Unrelated changes:** none.
- **Branch overlap:** the helpers overlap the unmerged #191 branch.

## 9. Validation results

| Command | Result |
|---|---|
| `cd tests/acceptance && npm run typecheck` | PASS |
| `cd tests/acceptance && npm run lint` | PASS (2 warnings) |
| `npx jest --config tests/jest/administration/jest.config.js` | PASS: 115/115 |
| `npx eslint src/Resources/app/administration/src/extension/sw-sales-channel` | PASS (0 errors) |
| `composer test` | PASS: 801 tests, 9 skipped |
| `composer cs` | FAIL: exit 8, only finding is `tests/acceptance/node_modules/flatted/php/flatted.php` (folder created by `npm ci`) |
| `composer phpstan` | FAIL: exit 1, pre-existing. Nothing in `src/Ucp/Profile`. The two touched test classes lack `CoversClass` on `main` too (verified). |
| `playwright test --project=Setup --grep-invert @UcpConsole` | PASS: 16/16 |
| `playwright test --project=Setup --grep @UcpConsole` | FAIL: environment (local `bin/console` can't decrypt signing keys) |
| `playwright test --project=UcpAdmin --no-deps` | PASS: 14/14 |
| `playwright test --project=UcpAdmin --no-deps --repeat-each=3` (four runs) | FAIL once (40/42), PASS three times (42/42) |
| `playwright test --project=UcpAdmin --no-deps --repeat-each=3 --workers=1` | PASS: 42/42 |
| `playwright test --project=UcpKnownBlocked` | BLOCKED: its `Setup` dependency fails on the console spec |

All Playwright commands ran in `tests/acceptance` with `APP_URL=http://localhost:8000/`. Lanes 6.5, 6.6 and 6.7
before 6.7.10: NOT RUN.

## 10. Remaining uncertainty

- Behaviour on 6.5, 6.6 and 6.7.0–6.7.9 is inferred from source; none of them was run.
- The cause of the parallel failures is unknown.
- The 6.5/6.6 save race is inferred, not reproduced.
- Meteor 3.5.1 (6.6.2) and 4.x (6.7.0–6.7.9) attribute forwarding wasn't inspected.

## 11. Final reviewer challenge

- **All six reviewers agree on `NOT READY FOR APPROVAL`, with no blocker.**
- **Reviewer B argued HIGH-1 should not be a BLOCKER:** production UI behaviour on 6.5 is unchanged and the fix is
  mechanical. Accepted.
- **Reviewer A dissents** on the missing end-to-end negative `ucp.viewer` check, wanting MEDIUM. A argued the Jest
  spec would still pass if the gate were removed. Resolved at LOW from the spec itself: lines 18 and 22 fail if
  `canViewUcpStatus` stops calling `acl.can('ucp.viewer')` or returns true. The only uncovered part is real privilege
  resolution for a role without `ucp.viewer`, which is #205's ACL matrix.
- **Reviewer C** rates the pre-existing 6.5/6.6 save race HIGH as a product bug for a separate issue. Recorded under
  MEDIUM for this change.

No code was changed during this review.
## 12. Resolution of the HIGH and MEDIUM findings

### HIGH

| Finding | Fix | Files | Proof |
|---|---|---|---|
| HIGH-1: hooks dropped on 6.5 | Hooks moved onto plain wrapper elements in the 6.5 template: the expose switch, every capability and transport row (the wrapper now carries the `__option-item` grid class, so the layout is unchanged), and the template select in detail-base. The page object reaches the checkbox inside the hook on every lane. | `sw-sales-channel-detail-agentic-commerce.sw.html.twig`, `sw-sales-channel-detail-base.html.twig`, `page-objects/UcpSalesChannelDetail.ts`, `page-objects/AgenticCommerceFeedDetail.ts` | Jest `…-test-hooks.spec.js` now fails when a 6.5 hook sits on a component that drops attributes: it checks the tag carrying each hook is a plain element or `sw-alert`. 6.5 itself still not run. |
| HIGH-2: "save clears banner" unproven | `saveUcpConfig()` waits for the PUT, then for a `GET /config` started after the write (the stored config loaded again), then for the tab to be mounted with no skeleton. The banner is asserted on that settled tab. Every lane now loads the config again after the write; see the save race below. | `page-objects/UcpSalesChannelDetail.ts` | The banner check can no longer run against an unmounted tab. |
| HIGH-3: Insights miscounts undetected | Seeds 2 attributed orders and customers on the feed, a later direct order from a referred customer, and an order and customer attributed to a second feed. Expects exactly 2 orders and 2 customers. | `tests/UcpAdmin/export-channel-attribution.spec.ts` | A hardcoded value, a count of all orders, a count of orders by referred customers, or a count across feeds no longer gives 2. |
| HIGH-4: parallel intermittency | The three attribution tests are marked `test.slow()` (heavy API setup before the Administration opens). In-app navigation was already in place. | `tests/UcpAdmin/export-channel-attribution.spec.ts` | Re-measured below. |

### MEDIUM

| Finding | Fix | Files | Proof |
|---|---|---|---|
| MCP version gate inside the component uncaught | Component-level Jest spec: imports the real view, sets an admin version that contradicts `meta.supportsStoreApiMcp` (6.5.8 with the flag on, 6.7.10 with it off), and asserts the offered transports follow the flag. | `sw-sales-channel-detail-agentic-commerce-transports.spec.js` (new) | Passes; a version comparison in `transportItems` would fail it. |
| Sub-tab and reload synchronisation | `showSubTab()` waits until either the sub-tab or the legacy preview card is rendered before deciding, instead of counting immediately. Loading and saving end in a positive settle check (tab visible, no skeleton, switch hook visible). | `page-objects/UcpSalesChannelDetail.ts` | No silent skip while the page is still booting. |
| Plugin Insights view and filter module never executed | Not fixable on this machine: only trunk runs here, where core renders both. Needs a run on a lane below 6.7.10. | n/a | Still open. |
| Navigate-away gap not recorded | `test.fixme('leaving the tab with unsaved changes asks before discarding them')`, as the suite README prescribes for an unimplemented dependency. | `tests/UcpAdmin/ucp-configuration.spec.ts` | Shows as skipped in every run. |
| Duplication with `origin/test-ucp-content-journeys` (#191) | `services/pluginSource.ts` now uses that branch's `importWithShopwareStub`, `salesChannelExtensionFile`, `FeedTemplate` and `readFeedTemplates()` (via the templates' `index.js`), replacing the parallel helpers and the extra stub queue. | `services/pluginSource.ts`, `services/UcpTestDataService.ts`, `tests/UcpAdmin/product-feed-export.spec.ts` | `git diff origin/test-ucp-content-journeys -- tests/acceptance/services/pluginSource.ts` is empty, so this file can no longer conflict. The feed factory remains separate from that branch's `createProductFeed`. |
| 6.6.0 and 6.6.1 have no Meteor components | The Meteor template is chosen from 6.6.2.0, where core first ships the library (verified: v6.6.0.0 and v6.6.1.0 have neither `@shopware-ag/meteor-component-library` nor `mt-checkbox`; v6.6.2.0 has both). `isVersionAtLeast()` gained an optional patch argument. | `agentic-commerce/admin-version.js` and its spec | Jest: 6.6.0 and 6.6.1 use the legacy template, 6.6.2+ the Meteor one. |
| 6.5/6.6 save race (pre-existing) | `onSave` snapshots the UCP form before saving and `saveUcpState()` writes that snapshot, then stores it as the saved state. Each write bumps `ucpState.generation`, and a load started before the write no longer applies its config or preview. On the legacy save path the UCP state is loaded again after the write. | `page/sw-sales-channel-detail/index.js` and its on-save and load-ucp-state specs | Jest: the snapshot is written although a simulated legacy reload replaced the form; the legacy path reloads after the write; a superseded load leaves the saved form alone. Not reproduced on a 6.5/6.6 lane. |

### LOW

| Finding | Fix | Files | Proof |
|---|---|---|---|
| No end-to-end check that a user without `ucp.viewer` sees no column | New test: a non-admin user with only `sales_channel.viewer` finds the channel in the list, and no `ac-sales-channel-list__ucp-status*` hook is rendered. `UcpAclUsers.asSalesChannelViewer()` added. | `tests/UcpAdmin/sales-channel-surfaces.spec.ts`, `fixtures/UcpAclUsers.ts` | Passes. |
| `data-ac-template-select` duplicated the hook | Removed; nothing referenced it. | `sw-sales-channel-detail-base.html.twig` | Rebuilt bundle no longer contains it. |
| Unnecessary promise queue in `pluginSource.ts` | Gone with the convergence on the content-journeys helpers (MEDIUM above). | `services/pluginSource.ts` | |
| Misleading cache comment in `discovery.ts` | Now says only the browser cache is avoided, and that Shopware's HTTP cache serves the document for up to 60 seconds. | `services/discovery.ts` | |
| `docs/ucp-admin-acceptance-plan.md` §2 out of date | §2 rewritten to match the implementation (hooks, helpers, page objects, fixtures, specs, coverage table). | `docs/ucp-admin-acceptance-plan.md` | |
| Two lint warnings in the MCP test | The test no longer branches: it ticks every offered transport, saves, and asserts discovery advertises exactly those, and separately that MCP is offered exactly when `meta.supportsStoreApiMcp` is true. | `tests/UcpAdmin/ucp-configuration.spec.ts`, `page-objects/UcpSalesChannelDetail.ts` (`offeredTransports()`) | Acceptance lint: no warnings. |
| `UcpAclUsers` depended on `AdminPage` and unioned a hard-coded privilege list | Depends on the worker-scoped ATS `AdminSession` and opens a page only when it creates a role user. Privileges are only what the running Administration's privileges service grants for the role keys; the hard-coded list and the file-based privilege union are gone, so drift in the shipped ACL file is no longer hidden. | `fixtures/UcpAclUsers.ts`, `tests/acceptance/README.md` | Setup ACL spec and both list-column tests pass. |
| Draft with UCP switched off not asserted | Unit test: a preview of an inactive draft advertises no service, while the stored (active) config still does. The preview sub-tab is disabled while inactive, so this is not reachable in the browser. | `tests/Unit/CapabilityFilteringProfileContributorTest.php` | Passes. |
| Template-modal cancel path untested | New test: choose Google, cancel, save; the stored export keeps the OpenAI provider, file name, format and body. `cancelTemplateChange()` added. | `tests/UcpAdmin/product-feed-export.spec.ts`, `page-objects/AgenticCommerceFeedDetail.ts` | Passes. |
| Insights timezone edge near midnight | No change needed: core's default range is `7Days`, starting at midnight seven days ago in the user's timezone (`dateAgoValue`, `sw-sales-channel-detail-product-export-insights/index.js:299-316`); rows created now are always inside it. The plugin view uses 30 days. | n/a | Source. |
| No changelog entry for the preview fix | Not added, by standing preference; can be added on request. | n/a | |

### Additional finding while fixing: core list-filter race (trunk)

Choosing an "Export channel" filter in the order or customer list updates the route twice. Core's listing
mixin (`listing.mixin.ts:129-155`) empties `filterCriteria` when the filter's `storeKey` changes, so a search
without the filter can be sent between the two updates and land last. In traces, the list then showed the
unfiltered result and the filter never reached the URL, although it had been stored for the user
(`PATCH /api/_info/config-me`). This is core code on trunk (≥ 6.7.10 core owns this filter), not the plugin.

The filter tests now wait until the filter is stored for the user, then read the list in a new tab of the same
(per-test) user at the list route without a filter in the URL; a fresh load can only take the stored filter.
Two earlier attempts (reloading after the filtered search; waiting for the filter in the URL) still failed under
parallel load, because a reload reads the URL's filter before the stored one.

### Validation after the fixes

| Command | Result |
|---|---|
| `npx jest --config tests/jest/administration/jest.config.js` | PASS: 139/139 (20 suites) |
| `npx eslint src/Resources/app/administration/src/extension/sw-sales-channel` | PASS: 0 errors, the same 7 warnings as before (none in changed lines) |
| `composer test` | PASS: 802 tests, 9 skipped |
| `composer cs` | FAIL: exit 8, only finding is `tests/acceptance/node_modules/flatted/php/flatted.php` (not part of the change) |
| `cd tests/acceptance && npm run typecheck` | PASS |
| `cd tests/acceptance && npm run lint` | PASS: 0 errors, 0 warnings |
| `playwright test --project=Setup --grep-invert @UcpConsole` | PASS: 16/16 |
| `playwright test --project=UcpAdmin --no-deps` | PASS: 16 passed, 1 skipped (the navigate-away `fixme`) |
| `playwright test --project=UcpAdmin --no-deps --repeat-each=3 --workers=1` | PASS: 48 passed, 3 skipped |
| `playwright test tests/UcpAdmin/export-channel-attribution.spec.ts --project=UcpAdmin --no-deps --repeat-each=10` (final filter helper) | PASS: 30/30 |
| `playwright test --project=UcpAdmin --no-deps --repeat-each=3` (final filter helper, two runs, default workers) | PASS: 48 passed, 3 skipped, both runs |

Before the final filter helper, the default-worker `--repeat-each=3` runs failed intermittently in the filter
tests (1 failure in 3 runs, then 2 failures in 2 runs); each failure traced to the core race above.

All Playwright commands ran in `tests/acceptance` with `APP_URL=http://localhost:8000/`. The admin bundle was
rebuilt (`SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1 npm run build`, `assets:install`, `cache:clear`) after the last
production change and before these runs.

### Still open

- 6.5, 6.6 and 6.7 below 6.7.10 have not been run, so HIGH-1, the 6.6.2 boundary and the save-race fix are proven
  by source reading and Jest only.
- The plugin's own Insights view and order/customer filter module are only exercised on those lanes.
- The core list-filter race is worked around in the tests, not fixed; merchants on trunk can still see an
  unfiltered list after choosing a filter. It needs a core issue.
- `@UcpConsole` still fails locally (the CLI cannot decrypt the signing keys the web process wrote); this is the
  local devenv, not the change.
