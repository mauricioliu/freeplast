# DEV / Staging code synchronization

Verified 2026-10-14T23:02Z. Owner requested consolidation, push and identical code in DEV and Staging; subsequently confirmed DATA-level deployment if needed, without commercial imports/migrations.

## Git consolidation

- Published the 29 previously unpushed main commits and committed the original UI cleanup as `c1a9165`.
- Integrated `herd/freeplast-iiu3-c8a6-integration` (six additional commits) into main as `6a75c67add6032699b699ffdb741e395fe736a52`.
- Resolved overlapping documentation in favor of the more complete release history; preserved the integration branch's strengthened native assertions for incomplete/obsolete previews, including approval blocking.
- Final tree exactly matches integration commit `f273dcd`; no runtime changes were invented during the merge. Historical prototype and superseded Ralph branches were not merged or deleted.

## Installed code parity

Full SHA-256 inventory, including detection of additional files, matches across:

1. main: `wordpress/wp-content/{plugins/freeplast-woo,themes/freeplast}`;
2. DEV: the same directories inside `freeplast-local-wordpress-1`, read-only bind mounts from this checkout;
3. Staging: `/var/www/freeplast/wp-content/{plugins/freeplast-woo,themes/freeplast}` on `hetzner-vps`, serving `https://freeplast.mliu.site`.

All **395 files** match exactly (349 plugin, 46 theme); staging has no symlinks in these component directories. Sorted manifest SHA-256: `3b315916d299217bae1a90f3dc2ed0fd4bc2f20fe3cc762b4f0ecdfee84041ec`.

Installed plugin **1.12.11**, theme **1.0.25**. Staging was already installed by the completed [a871c8 release](../../wordpress/docs/releases/2026-10-14-logic-a871c8.md); integrating that source into main made DEV identical without a redundant installation. **No new release, maintenance window, SQL write, import, migration, backup restoration or cron change was necessary or performed.**

## Checks

- Final merged tree: `FREEPLAST_SKIP_STACK=1 npm test` PASS, **2039** aggregate syntax/dependency/deployment checks plus reported offline suites. `git diff --check` passed.
- All nine configured staging smoke checks passed: five public pages HTTP 200 and four exact source-matched assets.
- DEV home, shop, quotation, about and contact pages HTTP 200 on loopback8131; local services healthy.
- Staging maintenance and release lock absent; `blog_public=0`; existing cron SHA-256 `acdce0f08554cf34c4a62c0316b0dff873ec24ec61d125befe7240184f270285` and independent mail shim SHA-256 `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1` match the completed release record.
- Current read-only protected-record fingerprint: `ff09c4d07f56dfdf1e181f6841b076413d33a904dfe446cd3fe4571d6d7a0442`. This is a current observation, not a claim of equality to the earlier release's historical database state.

Evidence: `.scratch/dev-staging-sync/{repo-files,dev-files,staging-files}.sha256`, `final-offline-tests.log` (private local, ignored).

## Scope and limits

Parity covers the Freeplast plugin/theme source, not WordPress core, third-party dependencies, database contents or environment-specific configuration. DEV retains synthetic data and local containment; Staging retains its own records, noindex and mail protection. Production `freeplast.cl` was not contacted. No fresh native integration gate or authenticated browser/physical-device acceptance was performed; the previous release record retains its native gate evidence.
