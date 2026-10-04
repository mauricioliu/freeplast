# Consolidated staging candidate — 2026-10-03

**Status: DEPLOYED to staging on2026-10-04 after DATA-tier confirmation.** Release `20261004T170723Z-bbc521`, application source `59fa378`. All phases passed; see [the deployment receipt](2026-10-04-data-bbc521.md). The preparation/preflight notes below are historical, not outstanding blockers.

Owner instruction: «quiero que todo pase a staging, realiza un orden de todo. quiero que todo esté en staging, commited, pushed and deployed.» Target: **https://freeplast.mliu.site**, never freeplast.cl. Initial proposed tier: **logic**. The owner subsequently confirmed «Confirmo logic; usa wp-release para staging», authorizing the read-only target preflight. That preflight found an intentional protected-data delta: the new plugin creates two restricted roles absent on staging. Corrected minimum tier: **data**, confirmed by the owner's «confirmo» in response to the explicit bounded role-migration/deployment question. No target mutations were made during that preflight; do not bypass the protected role-policy fingerprint.

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

## Required deployment gates (subsequently completed)

The historical `wp-release.json` described retired OpenClaw/Compose infrastructure and a photo migration; it was not executed. The canonical config is now adapted to SSH `hetzner-vps`, native `/var/www/freeplast`, local isolated MariaDB rehearsal and the bounded role migration. The redundant plugin-only config is retired. See `native-staging-runbook.md` and `2026-10-02-fresh-hetzner.md`.

Before installation: explicit **data-tier** confirmation for the bounded role-policy migration, native-topology config support, complete paired backup with verified restore, trial against restored MariaDB data, protected-record fingerprint expected-delta verification and installed artifact integrity, rollback instructions, preservation of staging mail/noindex boundaries. Local SQLite tests do not substitute for this trial. Final physical-device and commercial acceptance remain human-owned.

## Authorized read-only target preflight

- SSH `hetzner-vps`: native `/var/www/freeplast`, home URL exactly `https://freeplast.mliu.site`, PHP8.5.4. Disk space sufficient (68GiB available). Existing cron must be coordinated during the maintenance window.
- Installed: adapter1.6.8, theme1.0.18, WooCommerce11.1.0 and Quotes2.13, all active.17 products,10 variations,1 request; **HPOS enabled**, no historical legacy mappings. The historical CPT-only `record-state.php` and two-mapping `verify-woo-state.php` must not be used for this target.
- Existing standalone `owner-workspace-fingerprint.php` protects both CPT and HPOS tables, commercial options, users and role policy; it is the suitable starting point. `wp-release.owner-workspace.json` also needs native-target and full plugin+theme scope adaptation; its current Compose runner is retired.
- Existing staging mail MU shim matches recorded SHA-256 `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1`; mail suppression count2 and noindex intact. No emails sent, no writes or new accounts.
- Staging roles currently include standard WordPress/Woo roles and `ventas_freeplast`, but neither `fpw_quotation_manager` nor `fpw_data_manager`. `quotation-access.php` registers both on `init`, with exactly `read` plus the respective single custom capability. Installation therefore changes the protected `user_roles` option, even without creating any users.
- Required migration scope: those two exact role definitions only, preserving every other role and capability. Capture original policy, enforce release-owned maintenance, verify the exact expected delta, and retain full protected-record integrity. Do not drop roles from the fingerprint or replace the baseline after install. No catalog/photo/workbook/fiscal/customer-data import is proposed.
- No release driver run, remote bundle transfer, backup mutation, maintenance hold or installation has begun. Local Docker29.7.2/Compose5.5.1 are available for isolated MariaDB rehearsal; `docker-axi` executable is absent. The release skill has an existing self-test receipt, not yet revalidated for a real run.
