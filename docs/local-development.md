# Local Development (plugin maintainers)

This section is about developing the plugin itself against three Shopware lanes. To run UCP on a
shop you already have, see [Set up UCP on a sales channel](#set-up-ucp-on-a-sales-channel) above;
the full lane workflow is in [docs/manual-testing.md](manual-testing.md).

This repository keeps plugin source, QA tooling, and CI helpers only. Local Podman/Mutagen lane
orchestration is intentionally not versioned here, because it is workstation setup, not plugin code.

If you use the three-lane setup (`trunk`, `6.6.x`, `6.5.x`), keep the bootstrap helpers outside the
repository, for example under `~/scripts/agentic-commerce/`. The local helpers support these
environment variables instead of hard-coded personal paths:

- `AGENTIC_COMMERCE_PROJECTS_ROOT`
- `AGENTIC_COMMERCE_PLUGIN_ROOT`
- `AGENTIC_COMMERCE_SDK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_66_ROOT`
- `AGENTIC_COMMERCE_SHOPWARE_65_ROOT`
- `AGENTIC_COMMERCE_BASE_URL`

Add them to your shell profile (`~/.zshrc`, `~/.bashrc`, etc.):

```bash
export AGENTIC_COMMERCE_PLUGIN_ROOT=~/Documents/Projects/SwagAgenticCommerce
export AGENTIC_COMMERCE_SDK_ROOT=~/Documents/Projects/ucp-php-sdk
export AGENTIC_COMMERCE_SHOPWARE_TRUNK_ROOT=~/Documents/Projects/shopware-trunk
export AGENTIC_COMMERCE_SHOPWARE_66_ROOT=~/Documents/Projects/shopware-6-6-branch
export AGENTIC_COMMERCE_SHOPWARE_65_ROOT=~/Documents/Projects/shopware-6-5-branch
export AGENTIC_COMMERCE_PROJECTS_ROOT=~/Documents/Projects
export AGENTIC_COMMERCE_BASE_URL=http://trunk.localhost:8088
```

Adjust the paths to match your local checkout layout.

The plugin stores tooling dependencies in `.tools/vendor`, not `vendor`, so lane-local Composer
installs do not collide with the Shopware runtime dependency graph.

### One Shopware per process

PHPStan and the unit suite load exactly one Composer tree. With a `.tools/vendor` present it is that
tree, and PHPStan analyses the `shopware/core` installed there. A plain `composer install` resolves
the newest release from Packagist. To analyse against a lane instead, resolve the lane's packages
into `.tools` the way CI does:

```bash
bin/ci-link-shopware.sh "$AGENTIC_COMMERCE_SHOPWARE_66_ROOT"
composer update
SHOPWARE_PROJECT_DIR="$AGENTIC_COMMERCE_SHOPWARE_66_ROOT" composer ci
```

In this mode PHPStan uses the plugin's rules only, as CI does; core's own rules (`common.neon`) run
only when you analyse from inside a Shopware project.

The script rewrites `composer.json` with path repositories for `shopware/core`, `storefront` and
`administration`; do not commit that change. Never load a lane's own `vendor/` next to `.tools`: PHP
would combine classes from two Shopware versions, and a release that preloads classes in an autoload
file turns that into a fatal. Three checks refuse such a run. `bin/run.php` stops when
`SHOPWARE_CORE_DIR` or `SHOPWARE_PROJECT_DIR` names a different core than `.tools` holds, the
generated PHPStan autoload file loads only one tree, and `tests/bootstrap.php` fails the run when
two registered autoloaders map `Shopware\Core\`, `Shopware\Storefront\` or
`Shopware\Administration\` to different directories.

`composer bc-check` runs `roave/backward-compatibility-check` from its own tree, as core does, so
its constraints never pick the versions the plugin is analysed against. Install it once with
`composer install -d vendor-bin/roave-backward-compatibility-check`.
