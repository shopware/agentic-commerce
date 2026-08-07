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

echo "OK: administration bundle is ${bundle_bytes} bytes and entrypoints.json advertises ${entrypoint_js} js entry/entries."
