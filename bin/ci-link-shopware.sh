#!/usr/bin/env bash
# Resolve shopware/core, shopware/storefront and shopware/administration in the plugin's own
# Composer tree (.tools/vendor) from one Shopware checkout, so static analysis, rector and the
# unit suite run against that checkout and nothing else.
#
# The alternative -- the plugin tree resolving shopware/core from Packagist while the checkout's
# own vendor/ is loaded next to it -- puts two Shopware versions in one PHP process. PHPStan
# always loads the plugin tree first, and any class one of its autoload files touches is then
# fixed to that version; 6.7.16.0's class alias registry made every 6.6 lane fatal that way.
#
# The packages are path repositories labelled with the checkout's own
# Kernel::SHOPWARE_FALLBACK_VERSION, the version a Shopware source checkout reports for itself and
# the COMPOSER_ROOT_VERSION core's own CI uses on every branch, patch branches included.
# Path repositories are canonical, so Packagist's shopware/* releases are never considered.
# Symlinked, so .tools/vendor/shopware/core resolves to the checkout; bin/run.php relies on that
# to prove both sides are the same tree.
#
# Rewrites composer.json the way the SDK path repositories do; run it before `composer install`.

set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: bin/ci-link-shopware.sh <shopware-checkout>" >&2
  exit 1
fi

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
SHOPWARE_DIR="$(cd "$1" && pwd -P)"
KERNEL="${SHOPWARE_DIR}/src/Core/Kernel.php"

if [[ ! -f "${KERNEL}" ]]; then
  echo "${SHOPWARE_DIR} is not a Shopware source checkout (no src/Core/Kernel.php)." >&2
  exit 1
fi

VERSION="$(sed -n "s/.*SHOPWARE_FALLBACK_VERSION = '\([^']*\)'.*/\1/p" "${KERNEL}")"
if [[ -z "${VERSION}" ]]; then
  echo "Could not read SHOPWARE_FALLBACK_VERSION from ${KERNEL}." >&2
  exit 1
fi

cd "${PLUGIN_ROOT}"

for package in core storefront administration; do
  package_dir="${SHOPWARE_DIR}/src/${package^}"
  if [[ ! -f "${package_dir}/composer.json" ]]; then
    echo "Missing ${package_dir}/composer.json." >&2
    exit 1
  fi

  composer config "repositories.shopware-${package}" "$(jq -cn \
    --arg url "${package_dir}" \
    --arg name "shopware/${package}" \
    --arg version "${VERSION}" \
    '{type: "path", url: $url, options: {symlink: true, versions: {($name): $version}}}')"
done

# The plugin requires only shopware/core; storefront and administration code is analysed and
# unit tested too, so the tree has to carry both.
composer require --dev --no-update --no-interaction \
  "shopware/storefront:${VERSION}" \
  "shopware/administration:${VERSION}"

echo "Linked shopware/core, shopware/storefront and shopware/administration ${VERSION} from ${SHOPWARE_DIR}."