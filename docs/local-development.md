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
