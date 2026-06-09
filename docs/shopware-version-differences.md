# Shopware Lane Differences

This file documents the practical differences between the supported Shopware
lanes so we do not rediscover the same issues during `6.5`, `6.6`, and `trunk`
validation.

The goal is not to describe every Shopware difference. It is a working list of
the differences that already affected `SwagAgenticCommerce` development,
administration builds, browser validation, or local lane tooling.

## Quick Matrix

| Topic | `6.5.x` | `6.6.x` | `trunk` |
| --- | --- | --- | --- |
| Admin build mode | `webpack` | `webpack` and `vite` | `vite` |
| Settings group for UCP | `shop` | `shop` fallback unless `commerce` exists | `commerce` |
| Admin card implementation | legacy `sw-card` is common | mixed | Meteor `mt-card` is common |
| Plugin admin public output used at runtime | legacy `static/*` plus loader bridge | can hit both legacy and Vite paths | Vite assets |
| Discovery support | unavailable | unavailable | available only when the trunk bridge exists |
| MCP transport | never | never | only once the Store API MCP endpoint exists (see note) |
| Local PHP runtime | `8.2` | `8.3` | `8.4` |

> **MCP on trunk is blocked upstream.** The Store API MCP endpoint the UCP
> plugin proxies to ships with
> [shopware/shopware#17228](https://github.com/shopware/shopware/pull/17228).
> Until it merges, `trunk` does not advertise the MCP transport. MCP is gated on
> a single runtime capability — whether `StoreApiMcpServerController` exists —
> which the plugin uses to decide what `/.well-known/ucp` advertises.
> `bin/ci-smoke.sh` asserts the strict "supported &hArr; advertised" invariant
> server-side, and the public-profile Playwright tests skip their MCP-specific
> checks while the transport is absent and verify once it appears. No per-lane
> flag to maintain.

## Rules We Must Keep

- Always validate both:
  - admin build succeeds
  - admin UI actually loads in the browser
- Always validate both:
  - storefront build succeeds
  - storefront UI actually loads in the browser
- Keep all Shopware-line-specific branches commented in code.
- Run the three lanes next to each other. Do not switch a single active lane.
- Keep Shopware, plugin, and SDK checkouts two-way synced per lane.

## Administration Differences

### 6.5.x

- `6.5` still uses the legacy administration pipeline heavily.
- The plugin is discovered through a legacy entrypoint under:
  - `src/Resources/public/administration/js/swag-agentic-commerce.js`
- The runtime can still consume legacy built assets from:
  - `src/Resources/public/static/*`
- If those legacy static files are stale, the browser can show old labels,
  old Twig overrides, or old module registration behavior even when the
  source under `src/Resources/app/administration/src/*` is already correct.
- `6.5` does not expose the newer `commerce` settings group in the same way as
  `trunk`. UCP must fall back to `shop`.
- Browser validation is mandatory. A green webpack build alone does not prove
  the module is correct on `6.5`.
- The local storefront dependency install can fail on arm64 because Puppeteer
  tries to download Chromium during `npm install`.
- For `6.5` storefront dependency installation, set:
  - `PUPPETEER_SKIP_DOWNLOAD=1`
  - `PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=1`

### 6.6.x

- `6.6` is the transition lane.
- Both administration build paths must be considered valid targets:
  - `webpack`
  - `vite`
- UCP settings placement still needs the `shop` fallback unless the lane
  exposes the `commerce` group.
- Browser validation must be done after both build modes at least once, because
  a module can build in one mode and break in the other.

### trunk

- `trunk` is the cleanest reference lane for current administration behavior.
- UCP belongs under the `commerce` settings group.
- Meteor `mt-card` layout behavior is common here, so spacing fixes must target
  both `sw-card` and `mt-card`.
- Browser validation on `trunk` is still required, but it is usually the most
  truthful lane for current UX work.

## Sales Channel Page Differences

- The UCP shortcut on the native sales-channel detail page must be appended to
  the API section, not the whole page.
- The correct override block is:
  - `sw_sales_channel_detail_base_options_api`
- Do not override the root sales-channel detail block. That can hide the native
  Shopware configuration tabs and leave only our shortcut card visible.
- The shortcut title should stay:
  - `Agent access`
- The action should stay:
  - `Configure UCP`

## Version Label Differences

- Shopware core's own left sidebar developer version label is not ours.
- It can still show raw values like:
  - `6.5.9999999.9999999 Developer Version`
- Our UCP module must format the version summary itself.
- For dev branches that contain placeholder numbers like `9999999`, the UCP
  page should show:
  - `6.5-dev`
  - `6.6-dev`
  - `6.7-dev`
- Do not treat the core sidebar label as proof that our formatting is wrong.
  Check the UCP overview card itself.

## UCP SDK Listener Safety

- The SDK request listener must only touch UCP routes.
- The SDK exception listener must only format UCP-route exceptions.
- If the exception listener is global, unrelated Shopware failures such as
  `/admin` can degrade into raw UCP JSON documents instead of normal admin
  error handling.
- This already happened on `6.5`, so this is now a regression-sensitive rule.

## Local Lane Runtime Traps

### JWT key permissions

- On local lanes, `/admin` can fail with `500` if:
  - `config/jwt/public.pem`
  - `config/jwt/private.pem`
  are too permissive.
- Local fix inside the lane container:
  - `chmod 600 /var/www/html/config/jwt/*.pem`
- If `/admin` suddenly returns JSON or `500`, check this early.

### Mutagen / container sync

- Lanes run in parallel. There is no active-lane switch in the intended
  workflow.
- Use `$HOME/scripts/agentic-commerce/ensure-lane-sync {65|66|trunk}`
  to start a lane and ensure its persistent sync sessions are healthy.
- `$HOME/scripts/dev-startup.sh` delegates the three agentic lanes to
  that helper, so a reboot startup must not recreate plugin/SDK sessions as
  one-way replicas.
- Base Shopware, plugin, and SDK sync sessions must be `two-way-resolved`.
- The two-way sessions are required so container-side fixes, generated assets,
  public bundle output, and formatter output are visible in the host checkouts.
- Base Shopware syncs must still ignore the separately synced runtime checkouts:
  - `/custom/plugins/SwagAgenticCommerce`
  - `/custom/ucp-php-sdk`
- Base Shopware syncs must also ignore installed plugin admin bundles:
  - `/public/bundles/swagagenticcommerce`
- Base Shopware syncs must still ignore heavy runtime dependency/state folders:
  - `/vendor/`
  - `**/node_modules/`
  - `/var/`
- Plugin syncs must ignore generated plugin admin bundles:
  - `/src/Resources/public/`
- `var/plugins.json` is lane-local generated Shopware metadata. Never copy it
  between lanes or sync it from the plugin checkout; rerun `bundle:dump` or the
  admin smoke script when the admin shell does not advertise UCP.
- Do not run multiple lanes against the same container name. Two-way sync is
  safe only because each lane now has its own container target.
- Use `$HOME/scripts/agentic-commerce/sync-status` before blaming a
  lane. It prints the Mutagen mode and container file presence for every lane.

### Compose targeting

- The Compose service is intentionally named `web` in every lane. Service names
  are project-local; the project/container names are what make them unique.
- Prefer the lane helpers when jumping into containers:
  - `$HOME/scripts/agentic-commerce/lane-shell 65`
  - `$HOME/scripts/agentic-commerce/lane-shell 66`
  - `$HOME/scripts/agentic-commerce/lane-shell trunk`
- For one-off commands, use:
  - `$HOME/scripts/agentic-commerce/lane-exec 65 php -v`
- Plain `docker compose exec web bash` also works from a lane directory because
  the local `.env` pins `COMPOSE_FILE=compose.yaml:compose.override.yaml`.
- Do not remove the upstream `docker-compose.yaml` in `6.5`; use explicit
  compose files instead.

### PHP versions

- Current local runtimes are:
  - `6.5`: PHP `8.2`
  - `6.6`: PHP `8.3`
  - `trunk`: PHP `8.4`
- `ghcr.io/shopware/docker-dev:php8.1-node24-caddy` and
  `ghcr.io/shopware/docker-dev:php8.1-node20-caddy` are not published, so the
  current `6.5` lane cannot switch to PHP `8.1` with the same image family.

### No lane switching or host symlink workflow

- Do not use host symlinks, `docker cp`, or manual rsync to update lane
  containers.
- The source of truth is the persistent Mutagen session for each lane:
  Shopware base checkout, plugin checkout, and SDK checkout.
- Generated source-independent files are produced inside the lane containers.
  Dirty Shopware checkouts after builds are expected, but plugin admin bundles
  under `src/Resources/public/` and installed UCP bundles under
  `public/bundles/swagagenticcommerce/` must stay lane-local/disposable.
- If a file looks stale, check `sync-status` before rebuilding or copying
  anything manually.

## Styling / Layout Differences

- `trunk` and newer administration bundles often render Meteor `mt-card`
  wrappers with their own margins.
- `6.5` and older screens can still use legacy `sw-card` behavior.
- When normalizing page spacing, target both:
  - `.sw-card`
  - `.mt-card`
- The desired layout direction for UCP settings is:
  - one column on every lane
- Do not accept a layout just because it looks fine on `trunk`.
  Recheck it on `6.5` and `6.6`.

## Validation Checklist Per Lane

For every lane, verify all of these explicitly:

1. `admin build`
   - `6.5`: webpack
   - `6.6`: webpack and vite
   - `trunk`: vite
2. `admin browser`
   - UCP overview opens
   - UCP detail opens
   - native sales-channel detail still works
   - the UCP shortcut is appended after `API access`
3. `storefront build`
4. `storefront browser`
   - homepage loads
   - cart page loads
5. `UCP runtime`
   - `/.well-known/ucp` returns `200`

## If 6.5 Looks Wrong Again

Check these in order:

1. `/admin` returns HTML and not a JSON error document
2. JWT key permissions are `600`
3. the installed legacy plugin assets under
   - `public/bundles/swagagenticcommerce/static/*`
   are current
4. the installed admin loader under
   - `public/bundles/swagagenticcommerce/administration/js/swag-agentic-commerce.js`
   is current
5. the browser is not holding a stale SPA tab or stale asset cache

## Maintenance Rule

When we discover a new lane-specific trap, add it here immediately after the
fix lands. This file is the memory for version drift.
