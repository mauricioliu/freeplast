# Consolidated staging candidate — 2026-10-03

**Status: local candidate verified; not deployed.**

Owner instruction: «quiero que todo pase a staging, realiza un orden de todo. quiero que todo esté en staging, commited, pushed and deployed.» Target: **https://freeplast.mliu.site**, never freeplast.cl. Proposed tier: **logic** (permissions, private data maintenance, quotation issuance and public interaction changes). Explicit tier confirmation remains required by ADR-0002 before target contact. The release procedure also requires explicit invocation; local preparation does not bypass either gate.

## Scope and order

1. **Development tooling and repository hygiene.** Preserve the existing Impeccable skill, portable Codex hooks, project design model and critique notes. Ignore local configuration, live sessions, raw review captures and scratch output; do not delete them. These tools belong to Git, not the WordPress deployment.
2. **Complete functional code and tests.** Consolidate all accumulated owner-workspace, data-maintainer and interaction fixes together, including previously untracked modules. Adapter **1.12.0**, theme **1.0.20** (theme asset version updated too). Do not cherry-pick the two final test/banner fixes without their dependencies.
3. **Product/design documentation and review history.** Retain specifications, decisions, sanitized reports and the corrected Hetzner staging topology.
4. **Release after confirmation.** Adapt the obsolete Compose configuration to the actual native Hetzner topology, then paired backup → restore rehearsal → trial upgrade → maintenance install → verification → smoke → release record, with recovery prepared before installation. No ad hoc copy over the live plugin/theme.

## Functional inventory

- Owner inbox: Pending / Sent / All, scoped queries, search and simple mobile/desktop presentation.
- Quotation preparation, preview, frozen PDF access and commercial tracking; buyer recipient visible before approval; honest unsaved-price origin.
- Data hub and dedicated restricted data/quotation roles, plus strictly bounded access to the quotation operator's own basket.
- Private base prices and optional manual volume references; packaging equivalence; no automatic tier selection or mixed-pallet policy.
- Quote-only checkout without a coupon form, Spanish contextual control names including variants, associated email-error text.
- Unit/DOM/native/HTTP fixtures covering the modules and authorization boundaries.
- Local-only mail capture utility and tests are committed as developer tooling under `wordpress/scripts/`; **not installed in staging** and not present in application ZIPs.

## Content vs. application release

This candidate publishes functionality, **not a copy of the disposable local database**. Local test users, passwords, quotation70, captures, historical fixtures, workbook data, Google credentials and synthetic fiscal settings are not deployment inputs. Staging preserves its own database and catalog. Its independent mail suppression/noindex protections must remain intact. Additional commercial data imports or live email activation need their own explicit contract.

All accumulated source changes are included; no functional file is silently left out as “foreign WIP.” Nine existing commits ahead of origin/main are also part of the authorized push. Private/transient artifacts remain locally preserved and ignored, not published merely to achieve a clean status.

## Local candidate evidence

- Full disposable native gate on isolated http://127.0.0.1:8098: **710 native checks, 1990 aggregate PASS**, including approved PDF bytes, issuance failure handling and permission negatives. No real mail.
- Independent workspace offline gate: **1280 aggregate PASS**.
- `npm run test:photos`: PASS, including seven sealed-photo checks and five CLI checks. This is not authorization to rerun the photo migration on staging.
- Deterministic `package-woo.py`: adapter/theme ZIPs built; only the plugin and theme ZIPs are application installation inputs. Vendor versions remain pinned; no upgrade of WooCommerce/Quotes is requested.
- Earlier independent Chrome checks: banner mobile412/412, desktop1440/1440, visible simulation and no covered controls; see the interaction-fix report. No new physical-device acceptance claimed.
- High-signal secret scan over 119 candidate files found no private keys, common provider tokens, bearer credentials or credential-bearing URLs. Raw browser captures and local session/config files are excluded separately; this limited scan is not a universal secret-audit guarantee.

Local logs: `.scratch/herd-quotation-fix/lead-evidence/{native,offline,photos}-release-candidate.log` (ignored). Application bundles: `wordpress/.build/woo-release/` (ignored).

## Remaining deployment gates

`wp-release.json` still describes **retired OpenClaw/Compose infrastructure and a historical photo migration**. It must not be run unchanged. Current target is SSH `hetzner-vps`, native `/var/www/freeplast`, MariaDB and dedicated PHP-FPM; see `2026-10-02-fresh-hetzner.md`.

Before any remote contact: explicit logic-tier confirmation. Before installation: native-topology driver/config support, complete paired backup with verified restore, trial against restored MariaDB data, protected-record fingerprint equality and installed artifact integrity, rollback instructions, preservation of staging mail/noindex boundaries. Local SQLite tests do not substitute for this trial. Final physical-device and commercial acceptance remain human-owned.
