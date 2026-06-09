# Agent Notes

This plugin supports Shopware `6.5.x`, `6.6.x`, and `trunk` from one codebase.
Do not duplicate administration modules, controllers, or transport logic per
Shopware version. Use shared code plus explicit feature detection.

## Administration Build Matrix

The administration build system differs by lane:

| Lane | Required admin build validation |
| --- | --- |
| `6.5.x` | webpack only |
| `6.6.x` | webpack and Vite |
| `trunk` / current `6.7` | Vite only |

Use `bin/ci-admin-smoke.sh <shopware-dir> <mode>` for build validation:

```bash
bin/ci-admin-smoke.sh /path/to/shopware-6-5-branch webpack
bin/ci-admin-smoke.sh /path/to/shopware-6-6-branch webpack
bin/ci-admin-smoke.sh /path/to/shopware-6-6-branch vite
bin/ci-admin-smoke.sh /path/to/shopware-trunk vite
```

The script handles the important differences:

- `6.5.x` rejects Vite and relaxes local Node engine checks for webpack
  validation.
- `6.6.x` can run both paths; the script toggles `ADMIN_VITE` in
  `var/config_js_features.json` and restores it afterward.
- `trunk` rejects webpack and uses Vite.
- Every build must be followed by an admin shell/browser check. A successful
  JavaScript build alone is not enough.

## Administration Compatibility Rules

- Keep UCP under the `shop` settings group on `6.5.x`/`6.6.x` unless the
  `commerce` group exists. `trunk` uses `commerce`.
- Legacy `sw-card` and newer Meteor `mt-card` wrappers differ. Layout fixes must
  work for both.
- `6.5.x` can still consume legacy static administration assets. If the browser
  shows stale labels or layout, check installed assets under
  `public/bundles/swagagenticcommerce/` before changing source code.
- Do not copy or sync `var/plugins.json` manually. It is generated per Shopware
  lane by `bundle:dump`; if UCP is missing from the admin shell, rerun the lane
  admin smoke instead of reusing metadata from another lane.
- Treat `src/Resources/public/` and
  `public/bundles/swagagenticcommerce/` as generated admin output. The lane sync
  helper ignores these paths and the admin smoke script cleans them before each
  build to avoid webpack/Vite cross-lane pollution.
- Browser validation is mandatory on each lane after admin UI changes.

## Runtime Compatibility Rules

- Sales-channel UCP config lives in the plugin table
  `swag_agentic_commerce_ucp_config`. `SystemConfig` is only a legacy fallback
  and read-through backfill path; do not add new UCP settings there.
- Keep REST, A2A, embedded, and MCP on the shared SDK operation/capability
  layer. Shopware-specific MCP code is limited to the `/ucp/mcp` proxy and
  Store API MCP tool registrations.
- MCP write tools must expose object payload schemas (`payload` plus `id` where
  needed), not JSON-string payload arguments.
- Embedded pages require configured `embeddedAllowedOrigins`; the plugin returns
  controlled `403` responses for missing or non-allowlisted `Origin` headers and
  sets CSP frame ancestors from `embeddedFrameAncestors`.

## Further References

- [docs/shopware-version-differences.md](docs/shopware-version-differences.md)
- [docs/manual-testing.md](docs/manual-testing.md)
- [docs/full-ucp-parity-plan.md](docs/full-ucp-parity-plan.md)
