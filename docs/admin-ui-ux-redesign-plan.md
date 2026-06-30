# Admin UI/UX Redesign Plan — UCP / Agentic Commerce

> Status: **Discussion draft** — no code changes yet. Mockups are ASCII wireframes meant
> to spark a conversation with the team, not pixel-perfect designs.

## 1. Why we are doing this

Three problems were raised about the current admin experience:

1. **It doesn't look like Shopware Admin.** The UCP pages carry ~730 lines of custom SCSS
   (hero shells, metric grids, status pills, JSON `<pre>` blocks). The *form controls* are
   standard `sw-*`/`mt-*` components, but the *page chrome around them* is hand-rolled and
   drifts from the Meteor design language.
2. **It's in the wrong place.** UCP config lives in its own **Settings → UCP** module with
   a custom "overview of all sales channels" index page — while related features
   (Agentic Commerce Integration, Statistics, Product Comparison) already live as **tabs
   inside the sales channel detail**. So configuration for one sales channel is split across
   two unrelated locations, and the custom overview page duplicates the native Sales Channel
   list that already exists.
3. **It's too complex by default.** ~14 config fields + key rotation + a profile-preview JSON
   dump are shown flat to every user. Several of them are non-functional in this milestone
   (discovery budget is deferred, MCP transport is disabled) yet still rendered as
   disabled fields wrapped in explanatory info-alerts — pure noise for a non-technical user.

---

## 2. Current state (as built)

```
Settings
└── UCP  (custom module: sw-settings-ucp)
    ├── Index  ── custom "overview" of ALL sales channels
    │             • Shopware version • channel counts • active/inactive cards
    │             • per-card: "Configure UCP" / "Configure sales channel"
    │
    └── Detail/:salesChannelId  ── the big config form
        ├── Hero (name, status, capability count, transports, domains, version)
        ├── Exposure & profile   (expose toggle, profile URI source, continue URL)
        ├── Security & delivery   (signature policy, idempotency, discovery budget*,
        │                          4× host allowlists, webhook override)
        ├── Capabilities          (7 checkboxes)
        ├── Transports            (4 checkboxes, MCP disabled*)
        ├── Signing keys          (create / retire / delete)
        └── Profile preview       (read-only JSON dump)

Sales Channel  →  Detail
    ├── (native tabs: General, Theme, …)
    ├── Agentic Commerce Integration   (product export: OpenAI / Google feeds)
    ├── Statistics / Insights          (order & customer tracking)
    ├── Product Comparison             (export template + file config)
    └── API access section → shortcut card linking BACK to Settings → UCP detail

* deferred / disabled in this milestone but still rendered.
```

**The core tension:** per-channel UCP settings live under global **Settings**, while
per-channel Agentic Commerce features live under the **sales channel**. A merchant configuring
"Music" has to bounce between two areas, and the shortcut card is a band-aid over that split.

---

## 3. Design principles for the redesign

1. **One home per sales channel.** Everything you configure *for a sales channel* lives
   *in that sales channel*. UCP becomes a tab, not a separate Settings module.
2. **Meteor-first.** Use `mt-card`, `mt-tabs`, `mt-banner`, native field components, and the
   standard two-column card layout. Delete bespoke heroes, metric grids, and status pills —
   replace with the patterns the rest of the admin already uses. **Card-level sub-tabs are an
   allowed, in-core pattern** — the product **Variants** tab uses them (All / Physical / Digital
   variants inside the card); see §4.1.
3. **Progressive disclosure.** A **Basic** view that a non-technical merchant can complete in
   under a minute; an **Advanced** view (a sub-tab / collapsed by default) for hosts, origins,
   webhooks, and signing keys.
4. **Don't render what doesn't work yet.** Deferred/disabled fields are hidden behind a feature
   flag, not shown as greyed-out fields with an apology banner.
5. **No redundant chrome.** The Shopware version is already top-left in the admin; domains and
   capability counts are already on the sales channel — stop repeating them.
6. **Minimal setup, secure by default.** The merchant should only have to *turn UCP on*. Ship
   safe defaults (signature policy **Strict**) and auto-provision what's required (a signing key
   on activation), so a usable, secure configuration exists without touching Advanced.

---

## 4. Proposed information architecture

### Decision A — Move UCP config into the sales channel as one "Agentic Commerce" tab

UCP config is per-sales-channel, so it belongs next to the other agentic features. Today the
plugin already injects **two top-level tabs** (`sw-tabs-item`) into the sales channel detail —
*Agentic Commerce Integration* and *Insights* — and UCP config sits in a separate Settings
module. Consolidate everything under a single top-level tab named **"Agentic Commerce"**
(team decision). This kills the shortcut card and the cross-area bouncing.

**How the content inside that tab is structured — challenged against core (see §4.1).**

#### 4.1 Core-pattern research: how does Shopware structure config-heavy detail pages?

We checked how the core admin handles config-heavy detail pages before committing to a layout:

- **`sw-settings-country-detail`** (General / States / Address handling), **`sw-product` /
  `sw-order` / `sw-flow` detail** — a single top-level `sw-tabs` strip + a `<router-view>` that
  renders a **vertical stack of `mt-card`s inside `sw-card-view`**. One top-level tab level.
- **Card-level sub-tabs *do* exist in core** — the product **Variants** tab renders a tab strip
  *inside* the Variants card (*All variants / Physical variants / Digital variants*). So a single
  card hosting several related sub-sections via an in-card tab strip is an **established
  pattern**, not custom chrome. (Mirror that exact component; confirm whether it's `sw-tabs`
  or the Meteor equivalent during implementation.)

**Conclusion:** core gives us two complementary, sanctioned patterns and we use **both**:
(a) a top-level tab whose body is an `sw-card-view` card stack, and (b) a card that hosts
**sub-tabs** for its own sub-sections. One **"Agentic Commerce"** top-level tab → a card stack
→ where the dense **UCP** card carries sub-tabs and the lighter feature cards stay plain. This
keeps the UCP configuration in one card (not scattered across the stack) while still being fully
idiomatic.

```
Sales Channel → Detail → [ General | … | Agentic Commerce ]      ← ONE top-level tab
                                            │
                                            └── sw-card-view (card stack):
                                                │
                                                ├── UCP                       ← card WITH sub-tabs
                                                │   │   (on/off toggle in the card header, top-right)
                                                │   ├── Exposure
                                                │   ├── Capabilities & Security
                                                │   └── Developer & Advanced settings
                                                │
                                                ├── Product Feed export       ← plain card (no sub-tabs)
                                                └── Agentic Files             ← plain card (no sub-tabs)
```

> **Insights / analytics** (read-only) stays a **sibling top-level tab** — it is mode-different
> from configuration and does not belong inside the UCP card.

### Decision B — Remove the Settings → UCP module and all custom pages (decided)

The whole `sw-settings-ucp` module — the custom **overview/index** page *and* the custom
**detail** page — is **removed**. The **only** entry point becomes the **Agentic Commerce tab**
on the sales channel. The native **Sales Channels** list is the canonical overview, with UCP
on/off surfaced there:

- Add a **"UCP" status column** to the native sales channel list (Exposed / Off). That is the
  cross-channel overview — no bespoke index page.

> Optional, only if a real cross-channel signal exists later: a small dashboard card for
> **signing-key expiry across channels** ("2 keys expire in 14 days"). Not built unless/until we
> have that signal — the native list + column is enough.

### Decision C — Basic / Advanced split

Maps onto the UCP card sub-tabs: **Basic** = Exposure + Capabilities & Security; **Advanced** =
Developer & Advanced settings.

| Basic (Exposure / Capabilities & Security) | Advanced (Developer & Advanced settings) |
|---|---|
| Expose this channel via UCP (header toggle) | Continue URL template |
| Profile domain (selector, only when >1 domain) | Host allowlists (remote profile, agent/webhook) |
| Signature policy (with plain-language helptext) | Embedded origins / frame ancestors |
| Capabilities — the 5 ready, with tooltips (see §6) | Webhook URL override |
| Signing keys (auto-created on activation) | Idempotency toggle |
| | Transports (REST/A2A/Embedded) |
| | Collapsed "Not yet available" (identity, payment, MCP, discovery budget) |
| | Developer: live profile preview |

---

## 5. Mockups

### 5.1 Sales channel list — UCP status surfaced natively (replaces custom overview)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Sales Channels (3)                                        [ Add Sales Channel]│
├──────────────┬────────────┬──────────┬───────────────┬──────────────────────┤
│ Sales Channel│ Type       │ Status   │ UCP           │ Created at            │
├──────────────┼────────────┼──────────┼───────────────┼──────────────────────┤
│ 🛍 Headless   │ Headless   │ ● Online │ —             │ 31 May 2026          │
│ 🎵 Music      │ Storefront │ ● Online │ ● Exposed     │ 1 June 2026          │
│ 🏪 Storefront │ Storefront │ ● Online │ ○ Off         │ 31 May 2026          │
└──────────────┴────────────┴──────────┴───────────────┴──────────────────────┘
        ↑ one new column; no custom page, no duplicated version/counters
```

### 5.2 Sales channel detail — Agentic Commerce tab (card stack; UCP card has sub-tabs)

One top-level **Agentic Commerce** tab. Body is an `sw-card-view` stack: a **UCP** card that
carries sub-tabs (the product-Variants pattern), then plain **Product Feed export** and
**Agentic Files** cards. The on/off toggle sits in the **UCP card header, top-right**, on the
same level as the "UCP" title (a switch in the `mt-card` header action area).

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Music                                                              [ Save ]  │
│ General · Products · Theme · … · ▸ Agentic Commerce · Insights               │
├────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌── UCP ───────────────────────────────────────────────  Expose  ( ●▶ ) ┐  │ ← toggle in card header
│  │  [ ▸Exposure │ Capabilities & Security │ Developer & Advanced ]        │  │ ← sub-tabs (in-card strip)
│  │ ───────────────────────────────────────────────────────────────────── │  │
│  │  Exposure and profile                                                  │  │
│  │  Agents fetch this channel's UCP profile from one of its configured    │  │
│  │  domains. You can only expose a domain set up on this sales channel.   │  │
│  │                                                                        │  │
│  │  Profile domain   [ https://music-trunk-de.localhost:8100 ▾ ]          │  │
│  │  ↳ shown only when the channel has >1 domain; one domain → used auto.  │  │
│  │    Manage domains under the channel's Domains settings.                │  │
│  └────────────────────────────────────────────────────────────────────────┘  │
│                                                                              │
│  ┌── Product Feed export ───────────────────────────────────────────────┐   │ ← plain card, no sub-tabs
│  │  OpenAI / Google product feeds, export template & file config.        │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌── Agentic Files ─────────────────────────────────────────────────────┐   │ ← plain card, no sub-tabs
│  │  /llms.txt, /agents.md and ai-catalog fallback rendering.             │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────────────────┘
```

> **No more "explicit profile URI" (decided):** the *"Use an explicit profile URI" / Custom
> profile URI* option is **removed**. The profile host may only be a domain already configured on
> the sales channel — simpler for the merchant and inherently safe (the profile host is always a
> channel-owned domain, matching the allowlist/SSRF rules). The Exposure copy must explain this.
>
> **Profile domain selector:** when the channel has **more than one domain** it is otherwise
> ambiguous which one the profile + live preview use, so show a selector over the channel's
> domains. With a single domain it is used automatically (no selector). This same domain drives
> the live preview (§10.4).

> **Header toggle:** the master on/off lives in the UCP card header (top-right), not as a field
> in the body — use the `mt-card` header action slot for an `mt-switch`. When **off**, the
> Capabilities/Security and Developer sub-tabs are disabled/dimmed.

### 5.3 UCP card sub-tabs — Capabilities & Security, Developer & Advanced

```
UCP  [ Exposure │ ▸Capabilities & Security │ Developer & Advanced ]    Expose ( ●▶ )
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │  Signature policy   [ Strict — only signed requests ▾ ]   (default)     │ │
│  │  ⚠ "Log only" / "Off" lets unsigned agents in. Strict is recommended.   │ │
│  │                                                                         │ │
│  │  What agents can do on this channel  (each has a one-line tooltip):     │ │
│  │  [✓] Browse catalog  [✓] Cart  [✓] Discounts  [✓] Checkout  [✓] Orders  │ │
│  │  ↳ only the 5 production-ready capabilities appear here. Not-ready ones  │ │
│  │    (identity linking, payment tokenization) are NOT shown in Basic.     │ │
│  │  Signing keys                                                           │ │
│  │  kid: key-2026…  ES256  active  created 12 Jun   [ Retire ][ Delete ]   │ │
│  │  ↳ auto-created when UCP was turned on (see §10).                       │ │
│  │  New key id [ auto ]  Algorithm [ ES256 ▾ ]          [ Create key ]     │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │

UCP  [ Exposure │ Capabilities & Security │ ▸Developer & Advanced ]   Expose ( ●▶ )
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │  Continue URL (template)  [ https://shop.example/checkout/confirm?c=… ] │ │
│  │  ↳ moved here from Basic — integration detail, optional.                │ │
│  │                                                                         │ │
│  │  Allowlists & delivery                                                  │ │
│  │  Agent / webhook hosts [ agent.example × ] [ + add ]                    │ │
│  │  Remote profile hosts  [ + add ]                                        │ │
│  │  Embedded origins [ + add ]    Frame ancestors [ + add ]                │ │
│  │  Webhook URL override  [ https://agent.example/webhooks/orders       ]  │ │
│  │  [✓] Require idempotency keys for write requests                        │ │
│  │  Transports  [✓] REST  [✓] A2A  [✓] Embedded                            │ │
│  │                                                                         │ │
│  │  ▸ Not yet available  (collapsed by default — developer reference)      │ │
│  │     ◌ Identity linking      — needs an identity adapter.   [ Docs ↗ ]   │ │
│  │     ◌ Payment tokenization  — needs a payment handler.     [ Docs ↗ ]   │ │
│  │     ◌ MCP transport         — not usable on this version.  [ Docs ↗ ]   │ │
│  │     ◌ Discovery budget      — deferred to a later milestone.            │ │
│  │                                                                         │ │
│  │  ┌ Profile preview (live) ────────────  🟡 Preview is not saved yet ─┐  │ │
│  │  │ { …re-renders as you edit the config above… }                     │  │ │
│  │  └───────────────────────────────────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────────────────────────────────┘ │
```

> **Live preview (new):** the profile preview must re-render from the **current (edited) form
> state**, not only after Save — otherwise it isn't a preview. While there are unsaved changes,
> show a yellow `mt-banner` ("Preview is not saved yet") so the merchant knows the rendered
> profile reflects pending edits, not what agents currently see.

> Everything uses native `mt-card` / `mt-tabs` / `mt-banner` / field components — no custom
> heroes, metric grids, status pills, or `<pre>` styling.
>
> **Basic shows only what works; the rest is collapsed for developers (team decision):** the
> **Capabilities** sub-tab lists **only the 5 production-ready** capabilities — not-ready ones
> (identity linking, payment tokenization) are **not shown there at all**. They, plus MCP and
> discovery budget, live in a **collapsed-by-default "Not yet available"** group in Developer &
> Advanced, each with a short reason and a docs link — visible to a curious developer, invisible
> noise to the merchant. They render as disabled info rows, not dead form controls. The shown
> capabilities each carry a one-line explanation (tooltip/help) so the merchant knows what they
> permit.

---

## 6. Field-by-field audit (challenge every option)

Legend: **KEEP** (basic) · **ADV** (move to advanced) · **HIDE** (feature-flag until it works) ·
**CUT** (remove) · **MERGE**

Within the UCP card sub-tabs (§4.1, §5): **basic** = the **Exposure** and **Capabilities &
Security** sub-tabs; **advanced** = the **Developer & Advanced settings** sub-tab.

| Element | Today | Proposal | Rationale |
|---|---|---|---|
| Shopware version (hero) | shown | **CUT** | Already top-left in admin chrome. |
| Channel counts / active-inactive cards (index) | custom page | **CUT** | Native sales channel list covers it. |
| Hero capability count / transport summary / domains | shown | **CUT/MERGE** | Duplicates the form + sales channel data below. |
| Expose toggle (`active`) | basic | **KEEP** | The single most important switch. |
| Signature policy | mid-form | **KEEP** (basic) | Security-critical; keep with plain-language help. |
| "Unsigned requests accepted" warning | always-on alert | **MERGE** | Fold into inline helptext under the select, shown only when not strict. |
| Capabilities checkboxes (7) | flat list of 7 (incl. 2 disabled not-ready) | **KEEP** the 5 ready (basic, with per-item tooltip) + **HIDE** the 2 not-ready from Basic → collapsed dev group | The Capabilities sub-tab shows **only the 5 implemented**, each with a one-line explanation. `identity_linking` / `payment_tokenization` are **not shown in Basic at all**; they appear in the **collapsed-by-default "Not yet available"** group (Developer & Advanced) with a reason ("needs adapter/handler") + docs link. |
| Profile URI source ("use domain" vs explicit) | basic dropdown | **CUT** the option; **KEEP** a domain selector (basic, Exposure) | Drop "Use an explicit profile URI" + the free-text Custom profile URI. The profile host may only be a configured channel domain → a domain selector (only when >1 domain). Simpler and inherently safe (channel-owned host). |
| Continue URL template | basic | **ADV** | Integration detail, not first-run. |
| Idempotency toggle | basic | **ADV** | Sensible default = on; advanced users tune it. |
| Host allowlists (4× tagged fields) | basic | **ADV** | Operational/security detail; defaults to channel host. |
| "No explicit allowlist" info alert | always-on | **CUT/MERGE** | Convert to helptext on the allowlist field. |
| Webhook URL override | basic | **ADV** | Integration detail. |
| Discovery budget field + "deferred" alert | disabled field + alert | **ADV** ("Not yet available") | Don't render a dead, editable field in Basic. Show as a disabled info row in the Advanced "Not yet available" card with "deferred — enables in a later milestone". When the feature flag flips on, it graduates to a real Advanced field. |
| Transports checkboxes | 4 shown, MCP disabled | **ADV** | Usable transports (REST/A2A/Embedded) live in Advanced. MCP moves to the "Not yet available" card with a reason + docs link until usable. Drop the always-on "pending transports" alert. |
| Signing keys | own card | **KEEP** (Capabilities & Security sub-tab) | Security-relevant, so it sits with signature policy — but **auto-created on activation** (§10.1) so the merchant needs no action. Full create/retire/delete stays for power users. |
| Profile preview JSON | own card, `<pre>` | **ADV (Developer)** | Useful for devs; collapse and de-emphasize. |

Net effect: a non-technical merchant sees **3 things** (expose, signature policy, capabilities)
instead of ~14 fields + 5 alerts.

---

## 7. Styling / component cleanup

- Replace the two custom page heroes and metric grids with the **standard `sw-page` +
  `mt-card`** layout already used across Settings/Sales Channel.
- Delete custom **status pills** → use `mt-banner` / native status indicators.
- Delete the **summary metric grid** entirely (redundant data).
- The **profile-preview `<pre>`** can keep minimal styling but moves into a collapsed
  Developer card.
- Target: cut the ~730 lines of custom SCSS down to a small remainder (key-row layout, JSON
  block). Most layout should come from Meteor card/grid defaults.

---

## 8. Suggested phasing

1. **Phase 1 — Re-home (highest impact, lowest risk).** Add the **Agentic Commerce** tab on the
   sales channel with a **UCP** card (sub-tabs) + plain **Product Feed export** and **Agentic
   Files** cards; move the existing UCP form into the UCP card. Remove the `sw-settings-ucp`
   module (index + detail) and the shortcut card (Decision B). Keep the field set unchanged for now.
2. **Phase 2 — Sub-tab split + field audit.** Distribute fields across the UCP sub-tabs
   (Exposure / Capabilities & Security / Developer & Advanced) per §6; move the on/off toggle to
   the card header; fold always-on alerts into helptext; add the **preview/profile domain**
   selector (§10.5).
3. **Phase 3 — Native list integration.** Add the UCP on/off status column to the sales channel
   list; the custom overview is already gone from Phase 1.
4. **Phase 4 — Live preview + secure-default behavior.** Make the profile preview re-render from
   the edited form state with the unsaved-changes banner (§10.4); auto-provision a signing key on
   activation and confirm Strict default (§10.1); decide key lifecycle on deactivation (§10.2).
5. **Phase 5 — Meteor styling pass.** Strip custom SCSS, adopt standard card layout.

> ACL note: the existing `ucp.viewer / ucp.editor / ucp.key_rotator` privileges must continue
> to gate the tab and the signing-key actions after the move.

---

## 9. Open questions for the team

1. **Tab structure — resolved.** Tab name = **"Agentic Commerce"**. Body = `sw-card-view` card
   stack: a **UCP** card with **sub-tabs** (Exposure / Capabilities & Security / Developer &
   Advanced — the in-core product-Variants pattern, §4.1), plus plain **Product Feed export** and
   **Agentic Files** cards. **Insights** stays a sibling top-level tab. The Settings → UCP module
   and its custom index/detail pages are removed (Decision B).
2. **Do we keep any cross-channel overview?** Only worth it if we have a real signal
   (e.g. signing-key expiry across channels). Otherwise rely on the native list. → drives
   whether Decision B keeps a slim dashboard or deletes the index entirely.
3. **Capability curation:** confirm which capabilities are actually production-ready so we
   don't advertise ones gated on missing adapters/handlers.
4. **Deferred fields — resolved.** Discovery budget, MCP transport, and not-ready capabilities
   move to the Advanced "Not yet available" card (transparent, disabled rows + reason + docs
   link), not hidden. Open detail: do we *also* want an internal-only feature flag to make them
   live-editable for QA, or is the read-only row enough until the feature ships?
5. **In-page wording:** top-level tab is **"Agentic Commerce"** (decided). Still settle the
   merchant-facing wording *inside* the tab — the admin currently mixes "UCP", "Agentic
   Commerce", and "Agent access". Suggest "Agentic Commerce" everywhere, with "UCP" only where
   the protocol name is technically necessary (e.g. the Developer / profile-preview card).

---

## 10. Behavioral defaults & protocol questions (beyond UI)

These came up alongside the redesign; they shape backend behavior, not just layout.

### 10.1 Minimal setup, secure by default (direction decided)

Goal: the merchant only has to **turn UCP on** and have a secure, working configuration.

- **Signature policy defaults to `strict`.** This is *already* the code default
  (`UcpConfig::$signaturePolicy = 'strict'`). Keep it; the Capabilities & Security sub-tab shows
  Strict pre-selected with plain-language help.
- **Auto-provision a signing key on activation.** When UCP is switched **on** for a sales
  channel that has **no active signing key**, generate one automatically (default `ES256`) so
  Strict mode is immediately usable — no "now go create a key" dead-end.
  - *Implementation note:* hook the activation path (config save where `active` flips `false → true`,
    or first save with `active = true`); if `UcpSigningKeyService` finds no active key for the
    channel, create one. Surface it in the Signing keys list as "auto-created when UCP was turned on".

### 10.2 Key lifecycle on deactivation (open)

When UCP is **deactivated** for a sales channel, what happens to its signing keys?

- **Recommended:** **keep** them (dormant while inactive) — re-activating is seamless and we
  never destroy key material implicitly. Deletion stays an explicit, audited admin action.
- Alternatives to weigh: retire-but-keep, or full delete (clean, but agents must re-fetch and key
  history is lost). → **team decision needed**; default to "keep" unless there's a security reason
  to purge.

### 10.3 Per-agent authorization per key (open — needs protocol research)

Question raised: can UCP restrict a signing **key** to specific **agents**, and if so how do we
model it in the plugin config?

- **Today** the plugin authorizes agents at the **channel** level via host allowlists
  (`agentAllowlist` / `allowedAgentDomains`), not per key.
- Before committing UI: confirm against the SDK/spec whether the profile/key model carries a
  per-key agent binding. If it does, we'd need a key↔agent mapping on the key entity plus admin
  UI (e.g. an "allowed agents" field on each signing key); if not, per-agent control stays at the
  channel allowlist level and we document that explicitly. → **research before design**.

### 10.4 Live profile preview (decided)

The profile preview must reflect the **current edited form state**, re-rendering as the config
changes — not only after Save. While unsaved changes exist, show a yellow `mt-banner`
("Preview is not saved yet") so it's clear the preview shows pending edits, not the live profile
agents currently receive. (UI placement: Developer & Advanced sub-tab, §5.3.)

### 10.5 Profile host = a configured channel domain only (decided)

The **"Use an explicit profile URI"** strategy and the free-text **Custom profile URI** field are
**removed**. The profile may only be published on a domain already configured on the sales
channel. This is simpler for the merchant and inherently safe — the profile host is always a
channel-owned domain, so it always satisfies the allowlist/SSRF rules without extra config.

- **Config impact:** drop the `profileUriStrategy` option and the `customProfileUri` value from
  `UcpConfig`/`UcpConfigService` and the admin form. Migrate any existing explicit-URI configs to
  "use channel domain" (or surface a one-time warning if an explicit URI doesn't match a channel
  domain).
- **Multiple domains:** when the channel has >1 domain, show a **domain selector** (Exposure
  sub-tab) so the merchant picks which one the profile URL and the live preview use; with a single
  domain it is used automatically. The selected domain also drives the live preview (§10.4).
