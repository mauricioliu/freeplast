# Native Hetzner staging releases

The canonical `wp-release.json` now targets **only https://freeplast.mliu.site** via `hetzner-vps`, with native WP-CLI as `freeplast`, WordPress `/var/www/freeplast`, and private bundles `/var/lib/freeplast-release/bundles`. The obsolete Compose target and redundant plugin-only config are retired; their history remains in Git. Do not use historical OpenClaw commands. Production freeplast.cl is excluded.

## October 2026 role release

Owner confirmed the DATA tier for the exact two-role addition plus the complete adapter/theme update. This authorization does not import local users, requests, price workbooks, fiscal policy or mail credentials. Existing records, HPOS storage and all other roles remain protected. The sealed role migration captures the original role policy once and permits only its exact expected addition. The HPOS-capable fingerprint protects posts/meta, catalog taxonomy, users/meta, order tables/items, all commercial options and role policy. No fingerprint exclusion or new post-install baseline is permitted.

The migration is deliberately one-shot: it refuses a new capture once either target role already exists. After this release, future releases must remove the migration declaration and select the ordinary standalone `owner-workspace-fingerprint.php`, with their own reviewed tier and authorization. Do not weaken the one-shot guard to reuse it.

## Operator prerequisites

- Explicit owner instruction and tier confirmation, `wp-release` source-matched safety receipt plus its automatic negative regressions.
- Clean committed source, existing local `node_modules` and pinned `wordpress/.tools`; Docker/Compose for isolated local MariaDB restoration. `docker-axi` is not currently installed here; the release driver owns its scoped disposable Docker resources. Never stop unrelated containers.
- Release gate creates a fresh private temporary clone and DB, runs the full native npm test on unused loopback8098, migration regressions and photo tests, then removes only its own temporary directory. `FREEPLAST_RELEASE_TEST_PORT` selects another unused port. It never takes over an existing listener.
- Create `/var/lib/freeplast-release` and `bundles` as root:freeplast0750 (outside webroot); preserve existing directories, refusing collisions with foreign ownership. Backups `/root/freeplast-backups/releases` root0700.
- Coordinate the dedicated `/etc/cron.d/freeplast`: save its hash, move that exact file to a protected release-operations directory during the ceremony, wait for any already-running Freeplast cron worker, restore the identical bytes/owner/mode only after success. WordPress's own cron is already disabled in config. Never stop the shared cron daemon or neighboring services. If installation fails under maintenance, keep this site's cron suspended until authorized recovery.
- Keep the independent staging mail shim byte-identical and `blog_public=0`; never install `local-review-mail-capture.php` on staging.

## Ceremony

```sh
node ~/.agents/skills/wp-release/scripts/release.mjs --config wp-release.json plan
node ~/.agents/skills/wp-release/scripts/release.mjs --config wp-release.json run \
  --tier-confirmed data --authorized '<current exact owner instruction>'
```

The driver performs gate → deterministic package → transfer → paired backup → local isolated restore rehearsal → local trial upgrade → maintenance install → hashes/versions/state/fingerprint verification → public smoke → record. Local restore adapts only the disposable database constants, disables restored cron and has an internal network (no outbound delivery). No ports are published. MariaDB11.8 rehearsal matches the host's major/minor version; local PHP8.3 is not proof of every PHP8.5 behavior, so the host's read-only state checks are required too.

On success: restore the dedicated cron file, confirm maintenance absent, noindex and public/private-entry behavior, final plugin/theme versions and ownership, publish the generated release record and any narrative, and push the source/record commits. A read-only role/query verification is not a human UI or fiscal approval.

On failure: inspect the driver state; never edit sealed config/bundle/baseline to force success. A resume needs fresh owner authorization. The generated rollback is **prepared, not executed**. DATA releases require paired recovery with fresh authorization, a current inspected fingerprint and explicit data-loss-risk acknowledgment; no blind restoration of a backup over later writes. Preserve private backups and logs outside webroot and Git.
