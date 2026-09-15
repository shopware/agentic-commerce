# UCP version support

What UCP version this plugin serves, where that is decided, and what an SDK release that
moves the spec date means for a shop. The reasoning behind the single-version policy lives in
the SDK's `docs/ucp-version-support-policy.md` and is not repeated here; that document is the
decision of record.

## What the plugin serves

Exactly one UCP version: the one the linked `ucp-php-sdk` release serves. Today that is
`2026-08-25`.

It is written down once, in `src/Ucp/UcpProtocol.php` as `UcpProtocol::VERSION`. The capability
catalog and the payment handler stamp it into the profile, and the schema test validates
responses against the SDK's generated schemas for that date.

The plugin does **not** pass `ucp_sdk.version` to the SDK bundle. An SDK release serves exactly
one protocol version and defaults to it, so the only value that could ever be right is the one
the SDK already holds -- and passing it is the one way the two can come to disagree. Version
1.2.x shipped `version: '2026-04-08'` in a bundled `ucp_sdk.yaml` alongside a `>=0.0.5 <0.1.0`
SDK window: `composer update` resolved SDK 0.0.6, which had dropped that version, and the
container build failed inside `assets:install` part-way through a Shopware core upgrade, with
the error naming asset installation rather than the configuration line responsible. Two changes
close that off -- the SDK window is a single patch now, and the version is not configured at
all.

`UcpProtocolVersionGuardTest` asserts that the constant equals the SDK's
`UcpProtocolVersion::current()`. The constant is deliberately **not** derived from the enum: an
SDK release that moved to a new spec date would otherwise silently change what every shop
advertises while `ShopwareDataMapper` still emitted the previous shapes. The failing test is
the intended way for a spec bump to surface.

## A merchant cannot choose a version

There is no UCP version setting in the administration, and a `ucpVersion` value stored in an
older `swag_agentic_commerce_ucp_config` row is read and discarded (`UcpConfig`). The column
could only ever hold one legal value, and that value is a compile-time constant of the plugin
release. After the `2026-08-25` bump every sales channel still held `2026-04-08` and the plugin
rejected its own configuration until the rows were edited by hand; that is why the value is no
longer persisted at all.

UCP itself offers no cheaper way to serve an older version than a whole second profile with its
own endpoints and keys (`supported_versions` in the specification maps each older date to such
a profile). The plugin does not build one, for the reasons in the SDK policy document.

## What an SDK spec bump means for a shop

The plugin requires the SDK at the exact version it was tested against (`0.0.6` today), so a new SDK
release never reaches a shop on its own. It arrives with a plugin release in which:

- `UcpProtocol::VERSION` has been moved to the new date, after `ShopwareDataMapper` and
  `UcpCapabilityCatalog` were reviewed against the new schemas;
- the pin in `composer.json` was moved to the new SDK release;
- the changelog names the new UCP version.

From the shop's point of view an upgrade to such a release changes the version in
`/.well-known/ucp`, and every agent that still speaks the previous version is refused with
`422 version_unsupported` from then on. The previous version is not served alongside.
