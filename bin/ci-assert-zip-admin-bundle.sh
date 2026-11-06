#!/usr/bin/env bash
# Assert that a packaged extension zip actually contains a usable administration bundle.
#
# Release 1.2.0 shipped a zip whose administration/js/<name>.js had been overwritten by a
# 2.4 KB loader shim, and whose .vite/entrypoints.json therefore pointed back at that shim.
# Every Shopware version silently loaded nothing: 6.5/6.6 executed the shim as a classic
# script and 6.7 dropped the bundle entirely, with the failure swallowed by the admin's
# `injectPlugin` catch. Nothing in `shopware-cli extension validate` catches that.
#
# This guards the two invariants that make one zip work on 6.5, 6.6 and 6.7:
#   - administration/js/<name>.js is the real compiled bundle (6.5 + 6.6 read this path)
#   - .vite/entrypoints.json advertises a non-empty js list (6.7 reads this)

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-assert-zip-admin-bundle.sh <extension-zip>" >&2
  exit 1
fi

zip_file="$1"

for dependency in unzip jq; do
  if ! command -v "${dependency}" >/dev/null 2>&1; then
    echo "Missing required dependency: ${dependency}" >&2
    exit 1
  fi
done

if [[ ! -f "${zip_file}" ]]; then
  echo "No such zip: ${zip_file}" >&2
  exit 1
fi

readonly PLUGIN="SwagAgenticCommerce"
readonly TECHNICAL_NAME="swag-agentic-commerce"
readonly PUBLIC_PATH="${PLUGIN}/src/Resources/public/administration"
# The compiled bundle is ~97 KB. A loader shim is ~2.4 KB. Anything under this floor means
# the real bundle never made it into the archive.
readonly MIN_BUNDLE_BYTES=50000

workdir="$(mktemp -d)"
trap 'rm -rf "${workdir}"' EXIT

bundle="${workdir}/bundle.js"
unzip -p "${zip_file}" "${PUBLIC_PATH}/js/${TECHNICAL_NAME}.js" >"${bundle}" 2>/dev/null || true
bundle_bytes="$(wc -c <"${bundle}" | tr -d ' ')"

if [[ "${bundle_bytes}" -eq 0 ]]; then
  echo "FAIL: ${PUBLIC_PATH}/js/${TECHNICAL_NAME}.js is missing from ${zip_file}." >&2
  echo "      Shopware 6.5 and 6.6 load the administration from exactly that path." >&2
  exit 1
fi

if [[ "${bundle_bytes}" -lt "${MIN_BUNDLE_BYTES}" ]]; then
  echo "FAIL: ${PUBLIC_PATH}/js/${TECHNICAL_NAME}.js is only ${bundle_bytes} bytes." >&2
  echo "      That is a shim, not the compiled administration bundle." >&2
  echo "      Check that zip.assets.after_hooks is not overwriting the build output." >&2
  exit 1
fi

if ! grep -q 'sw-sales-channel-detail-agentic-commerce' "${bundle}"; then
  echo "FAIL: the administration bundle does not register the Agentic Commerce views." >&2
  exit 1
fi

entrypoints="$(unzip -p "${zip_file}" "${PUBLIC_PATH}/.vite/entrypoints.json" 2>/dev/null || true)"

if [[ -z "${entrypoints}" ]]; then
  echo "FAIL: ${PUBLIC_PATH}/.vite/entrypoints.json is missing from ${zip_file}." >&2
  echo "      Shopware 6.7 discovers plugin administration assets only through that file." >&2
  exit 1
fi

entrypoint_js="$(jq -r --arg name "${TECHNICAL_NAME}" \
  '.entryPoints[$name].js // [] | length' <<<"${entrypoints}")"

if [[ "${entrypoint_js}" -eq 0 ]]; then
  echo "FAIL: entrypoints.json has no js entry for \"${TECHNICAL_NAME}\"." >&2
  echo "      Shopware 6.7 keys on the bundle's container prefix; a mismatch here drops" >&2
  echo "      the plugin from /api/_info/config without any error." >&2
  jq . <<<"${entrypoints}" >&2
  exit 1
fi

# The two discovery paths do not have to name the same file. shopware-cli emits
# content-hashed assets (js/<name>-PMJ7ZT4L.js) with an unhashed alias next to them:
# 6.5/6.6 build the unhashed path by convention and never read entrypoints.json, while
# 6.7 follows entrypoints.json to the hashed one. Checking only the unhashed file would
# pass an archive whose hashed target is missing or misnamed, which 404s on 6.7 exactly
# as silently as the original bug did. So resolve what entrypoints.json actually points
# at and verify each referenced asset ships.
mapfile -t referenced < <(jq -r --arg name "${TECHNICAL_NAME}" \
  '.entryPoints[$name] | (.js // []) + (.css // []) | .[]' <<<"${entrypoints}")

zip_index="${workdir}/index.txt"
unzip -Z1 "${zip_file}" >"${zip_index}" 2>/dev/null || unzip -l "${zip_file}" >"${zip_index}"

for asset in "${referenced[@]}"; do
  # "/bundles/swagagenticcommerce/administration/js/x.js" -> "administration/js/x.js"
  relative="${asset#/}"
  relative="${relative#bundles/}"
  relative="${relative#*/}"
  archive_path="${PLUGIN}/src/Resources/public/${relative}"

  if ! grep -qF "${archive_path}" "${zip_index}"; then
    echo "FAIL: entrypoints.json references ${asset}, which is not in ${zip_file}." >&2
    echo "      Expected archive member: ${archive_path}" >&2
    echo "      Shopware 6.7 loads exactly that file; a missing target 404s silently." >&2
    exit 1
  fi
done

# The file 6.7 actually executes must be the real bundle too, not just present. Without
# this the hashed target could be a stub while the unhashed alias carries the real code.
entry_js_path="$(jq -r --arg name "${TECHNICAL_NAME}" '.entryPoints[$name].js[0]' <<<"${entrypoints}")"
entry_relative="${entry_js_path#/}"
entry_relative="${entry_relative#bundles/}"
entry_relative="${entry_relative#*/}"

entry_bundle="${workdir}/entry.js"
unzip -p "${zip_file}" "${PLUGIN}/src/Resources/public/${entry_relative}" >"${entry_bundle}" 2>/dev/null || true
entry_bytes="$(wc -c <"${entry_bundle}" | tr -d ' ')"

if [[ "${entry_bytes}" -lt "${MIN_BUNDLE_BYTES}" ]] \
  || ! grep -q 'sw-sales-channel-detail-agentic-commerce' "${entry_bundle}"; then
  echo "FAIL: the asset 6.7 loads (${entry_js_path}, ${entry_bytes} bytes) is not the" >&2
  echo "      compiled administration bundle." >&2
  exit 1
fi

echo "OK: 6.5/6.6 load js/${TECHNICAL_NAME}.js (${bundle_bytes} bytes);" \
     "6.7 loads ${entry_js_path} (${entry_bytes} bytes); all ${#referenced[@]} referenced assets ship."
