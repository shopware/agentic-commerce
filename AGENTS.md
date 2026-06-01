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
- Browser validation is mandatory on each lane after admin UI changes.

## Further References

- [docs/shopware-version-differences.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/docs/shopware-version-differences.md)
- [docs/manual-testing.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/docs/manual-testing.md)
- [docs/full-ucp-parity-plan.md](/Users/b.meyer/Documents/Projects/SwagAgenticCommerce/docs/full-ucp-parity-plan.md)
