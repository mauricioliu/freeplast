# Freeplast staging — deployment record (issue #14)

The isolated, password-protected staging deployment of the complete build
(issues #2–#13). Everything an operator needs to deploy, verify, back up
and roll back lives here and in `infra/`. The repository carries the
artifacts and their mechanical checks; the server-side execution is the
operator step recorded below. The packaging for independent operation
and human review is `HANDOFF.md` (issue #15).

**Status:** DEPLOYED 2026-09-04 (operator run executed by the pi agent
session with the owner present). Preflight clean → deploy → verify.sh all
checks `ok` → backup + restore rehearsal verified (17/17 `fp_product`).
Three on-server fixes applied during the run, patched at source and listed
below. Gate 3 (human visual approval) remains the owner/client step.

On-server fixes from the operator run (source patched, re-deploy safe):
1. `deploy.sh` — the stack directory needs `chmod 0711` so nginx (www-data)
   can traverse to `nginx/.htpasswd` (was `0700` → every proxied request
   500'd on htpasswd open); secrets stay 0600/0400 and unreadable.
2. `verify.sh` — `wp plugin list --format=name` is not a valid format on
   this WP-CLI (silent failure through `|| true` → false "not active");
   now `--format=csv --fields=name | tail -n +2`.
3. `backup.sh` — mariadb 11.4 `mariadb-dump`/`mariadb` read `MYSQL_PWD`,
   not `MARIADB_PWD` (dump failed "using password: NO"); secret still
   travels through the container environment, never argv.

## Approved resources (all collision-checked by `infra/preflight.sh`)

The hostname, stack directory and loopback port below are declared exactly
once, in `infra/staging.sh` (issue #16): every script sources that shared
definition, deploy.sh renders the vhost template
(`nginx/staging.conf.tmpl`) from it, and Compose reads the port back from
the deploy-written `.env` — a staging host or port change is a one-place
edit. This table remains the record of the values as deployed.

| Resource | Value |
| --- | --- |
| Hostname | `freeplast.mliu.site` (PRD §Staging; DNS must resolve to OpenClaw — preflight check 9) |
| Compose project | `freeplast-wordpress` (`name:` in `infra/compose.yaml`) |
| Stack directory | `/opt/freeplast-wordpress` |
| Origin HTTP | loopback only — `127.0.0.1:8092` → container 80 (nothing published elsewhere) |
| Database volume | `freeplast-wordpress_db_data` (private named volume) |
| WordPress volume | `freeplast-wordpress_wp_data` (private named volume) |
| Network | `freeplast-wordpress_freeplast` (project-scoped bridge) |
| Images | `mariadb:11.4`, `wordpress:7.1-php8.3-apache`, `wordpress:cli-php8.3` (multi-arch, ARM64 host — digests in §Post-deploy records) |
| Nginx vhost | `/etc/nginx/sites-available/freeplast.mliu.site` + symlink in `sites-enabled/` (rendered from `infra/nginx/staging.conf.tmpl`) |
| htpasswd | `/opt/freeplast-wordpress/nginx/.htpasswd` (0640, root:www-data; rendered by deploy.sh) |
| TLS | the server's approved convention — paths supplied through `.env` (`TLS_CERT_PATH`/`TLS_KEY_PATH`); preflight verifies the certificate covers the hostname and is not near expiry; the wildcard covering `*.mliu.site` is the expected form |
| Backups | `/root/freeplast-wordpress-backups/<UTC-stamp>/` + `/root/nginx-sites-available.pre-freeplast-<UTC-stamp>` (Nginx pre-change backup) |
| Secrets | `/opt/freeplast-wordpress/.env` (0600) and `/opt/freeplast-wordpress/.secrets/credentials` (0400) — generated on the server by `openssl rand`, never printed, never committed (`.env.example` carries names/comments only) |

Collision discipline (OPENCLAW.md): any preflight failure means choosing
a NEW name/port/path — never adopting or deleting the conflicting
resource. `ALLOW_EXISTING_STACK=1` (set automatically by re-runs of
deploy.sh against the existing stack) downgrades only the stack's own
resource checks to notes; hostname/port/Nginx/TLS/DNS/health collisions
stay fatal.

## What the deployment enforces

- **Isolation:** dedicated Compose project, private named volumes,
  project-scoped network, loopback-only origin. The database publishes
  no port; the WP-CLI sidecar starts only on demand (profile `tools`).
- **HTTPS + noindex, public review surface:** host Nginx terminates TLS
  for the approved hostname only and sends `X-Robots-Tag: noindex,
  nofollow` on every response; WordPress itself installs with
  `blog_public 0`. The edge Basic Auth gate was removed by owner
  instruction (2026-09-04 posture change — the staging site serves
  anonymous visitors; `/wp-admin/` and `/wp-login.php` stay gated by the
  WordPress login). `nginx -t` validates before every
  reload, and the prior configuration is backed up first.
- **Origin opacity:** the edge hides the origin runtime header
  (`X-Powered-By`) from every proxied response (issue #23) — the staging
  site never discloses the PHP version; no other origin header is
  touched, and verify.sh fails if the header ever reappears.
- **WordPress identity:** locale `es_CL`, timezone `America/Santiago`,
  home/site URLs `https://freeplast.mliu.site`, permalinks
  `/%postname%/`, `DISALLOW_FILE_EDIT`, `--skip-email` installs.
- **Secrets:** MariaDB root/application and the WordPress administrator
  are generated on the server, live only in
  mode-0600/0400 files, travel through the environment (never argv,
  never output), and reach the owner only through the approved secret
  channel. The two Basic Auth pairs are still generated and stored the
  same way but are unused at the public edge since the 2026-09-04
  posture change (retained for a possible return to a gated surface).
- **Safe test mail:** the plugin fails closed (issue #10) and the stack
  sets `FREEPLAST_CQ_MAIL_MODE=suppress` — non-delivery until the owner
  approves test recipients; switching to `redirect` additionally needs
  `FREEPLAST_CQ_MAIL_TO` (an approved test address). `live` is a
  production-only value; verify.sh fails if it is ever set here.
- **Catalog:** the reviewed `data/products.json` synchronizes through
  WP-CLI and a repeated dry run must report `created=0 updated=0 …
  errors=0` or the deployment aborts.
- **Unchanged neighbors:** static proposals under `/var/www/html/freeplast`,
  existing websites, unrelated Compose projects, certificates and
  unrelated Nginx configuration are never referenced for mutation; the
  preflight baseline records them and rollback re-checks them.

## Operator runbook (from the development machine)

```bash
# 0. Mechanical proof of the artifacts (offline)
npm test

# 1. Copy the repository's wordpress/ onto the host (outside the stack dir)
rsync -a --delete <repo>/wordpress/ openclaw:/opt/freeplast-wordpress-src/

# 2. Read-only preflight (supply the approved TLS convention)
ssh openclaw '
  TLS_CERT_PATH=/path/to/fullchain.pem TLS_KEY_PATH=/path/to/privkey.pem \
  bash /opt/freeplast-wordpress-src/infra/preflight.sh'

# 3. Deploy (generates secrets, validates Compose, bootstraps WordPress,
#    synchronizes the catalog, installs the vhost, verifies)
ssh openclaw '
  TLS_CERT_PATH=/path/to/fullchain.pem TLS_KEY_PATH=/path/to/privkey.pem \
  WORDPRESS_ADMIN_EMAIL=<owner-approved address> \
  bash /opt/freeplast-wordpress-src/infra/deploy.sh /opt/freeplast-wordpress-src'

# 4. Transfer the credentials to the owner through the approved secret
#    channel (they live in /opt/freeplast-wordpress/.secrets/credentials,
#    mode 0400 — print only the path, never the file)

# 5. Re-run verification any time
ssh openclaw 'bash /opt/freeplast-wordpress-src/infra/verify.sh'
```

Re-running deploy.sh against the deployed stack updates it: existing
secrets are kept, `wp core is-installed` guards the install, the bundle
is refreshed and `docker compose up -d` reconciles the services.

## Backup and restore rehearsal

```bash
ssh openclaw 'bash /opt/freeplast-wordpress-src/infra/backup.sh'
```

Dumps the database (password via container environment, never argv),
archives the whole WordPress volume, hashes both artifacts outside the
live volumes, then rehearses the restore into the temporary project
`freeplast-wordpress-restore` (loopback 8093), verifies the restored
`fp_product` count matches the live stack through WP-CLI, and tears the
rehearsal down. Run before every catalog import, plugin migration or
release, and before any server-level change.

## Rollback (bounded to the new resources)

```bash
ssh openclaw 'bash /opt/freeplast-wordpress-src/infra/rollback.sh'
# named volumes retained; after explicit owner approval only:
ssh openclaw 'bash /opt/freeplast-wordpress-src/infra/rollback.sh --purge-volumes'
```

Removes the approved-hostname vhost (after `nginx -t`) and brings down
only the `freeplast-wordpress` Compose project; verifies the remaining
Compose projects and the static proposals are untouched. Named volumes,
`.env`, `.secrets`, deployment records and backups stay on disk until
the owner explicitly approves deletion.

## Post-deploy records (filled 2026-09-04, operator run by pi agent)

Issue #16 single-sourcing re-run (executed 2026-09-04T18:11Z, operator
run by pi agent): `deploy.sh` took the existing-stack path against the
running stack — same secrets, same resolved Compose configuration, loopback
mapping unchanged — and `verify.sh` reported `verification clean`.
Preflight first needed one source fix, committed before the run: the
loopback-port check was unconditionally fatal, so no re-deploy could ever
pass while the stack held its own port; it now accepts the stack's own
published mapping (`docker compose port`) under `ALLOW_EXISTING_STACK=1`
and stays fatal for any foreign binding. The rendered vhost is proven
byte-identical by `npm test`.

Issue #23 origin-header strip re-run (executed in the same
2026-09-04T18:11Z run): the vhost re-rendered from the template — the
proxied `location /` carrying `proxy_hide_header X-Powered-By;` — and
reinstalled (`nginx -t` before the reload, prior configuration backed
up); an authenticated response through the edge now carries no
`X-Powered-By` (verified independently after the run), and `verify.sh`
asserts the same — `verification clean` with the origin-runtime row. The
run also reinstalled theme `freeplast` 0.8.1 and the current plugin into
the persistent volume; catalog synchronization reported zero changes.

- [x] Re-run preflight output: all checks `ok` (port as redeploy note) —
      `/root/freeplast-wordpress-backups/preflight-20260904T181152Z.txt`
- [x] Re-run verify.sh output: all checks `ok`, including the new
      `authenticated response carries no X-Powered-By` row; baseline
      Compose projects unchanged (cutulab, frappe-lms, open-wearables)
- [x] Public review surface (owner instruction, executed same day): edge
      Basic Auth removed from the vhost template, vhost re-rendered and
      reinstalled; anonymous `/` → 200, `/wp-admin/` → 302 (WordPress
      login gate), noindex and the X-Powered-By strip unchanged; verify.sh
      now walks the matrix anonymously

- [x] Preflight output: all checks `ok`, baseline recorded —
      `/root/freeplast-wordpress-backups/preflight-20260904T102325Z.txt`
- [x] Resolved image digests —
      `/opt/freeplast-wordpress/deployment-record-20260904T102325Z.txt`
      (mariadb@sha256:611a2fcc…, wordpress@sha256:ae66461…,
      wp-cli@sha256:2b5e9d4…)
- [x] `nginx -t` ok; TLS: `/etc/nginx/ssl/mliu.site/{fullchain,key}.pem`
      (wildcard `*.mliu.site`, CN=mliu.site, expires 2026-11-18, dns-only)
- [x] verify.sh output: all checks `ok` (routes, 401 anonymous, auth 200s,
      404, 302 wp-admin, noindex ×2, es_CL, America/Santiago, URLs,
      plugin+theme active, catalog idempotent, mail suppress)
- [x] backup.sh run + restore-rehearsal verified —
      `/root/freeplast-wordpress-backups/20260904T103048Z`
      (first attempt 102916Z failed on MARIADB_PWD → fixed, see above)
- [x] UTC timestamp: 2026-09-04T10:23–10:31Z · operator: pi agent (Mauricio present)
- [x] Owner informed of the credentials path (secret channel used: this
      pi session with the owner; file never entered the repository)
- DNS: `freeplast.mliu.site A 178.105.30.70 dns-only` created in
      Cloudflare (zone 93dca869…), matching the lms.mliu.site convention.

## Pending owner inputs

- ~~Final approval of the hostname/DNS~~ — resolved 2026-09-04: DNS A
  record created in Cloudflare (dns-only), staging live at
  https://freeplast.mliu.site.
- Approved staging test-mail recipients (until then the stack stays on
  `suppress`).
- Google Places/Routes key (optional; console-restricted, supplied as
  `FREEPLAST_GOOGLE_API_KEY`, never committed).

## On-server run output

Executed 2026-09-04T10:23–10:31Z by the pi agent session with the owner
present. Full preflight/verify outputs live beside the backups
(`preflight-20260904T102325Z.txt`, deployment-record-20260904T102325Z.txt).
Final verify: `verification clean — staging answers the acceptance matrix
through HTTPS`. Neighbor check: cutulab, frappe-lms, open-wearables and
the static proposals unchanged from the preflight baseline.
