# OpenClaw WordPress operations

Load this reference for RUNBOOK Gate 1, provisioning, deployment, backup or rollback.

## Connection and observed topology

Connect from the development machine with:

```bash
ssh openclaw
```

Observed 2026-09-03: Ubuntu on ARM64, root SSH access, host Nginx, Docker/Compose, existing
Compose projects, and no active host PHP/MySQL service. Nginx proxies other containerized apps
through ports bound to `127.0.0.1`. Treat this as a snapshot: re-probe before planning.

## Preflight

Run read-only checks and save their bounded output to `wordpress/DEPLOYMENT.md`:

```bash
ssh openclaw 'set -eu
  hostname
  id
  docker --version
  docker compose version
  docker compose ls
  docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Ports}}"
  ss -ltn
  df -h / /var/www
  find /etc/nginx/sites-enabled -maxdepth 1 -type l -printf "%f -> %l\n"
  test ! -e /opt/freeplast-wordpress
'
```

Also verify:

- approved hostname has no existing Nginx `server_name`;
- chosen loopback port is unbound;
- `/opt/freeplast-wordpress` and intended volume names do not exist;
- at least 10 GiB disk remains;
- DNS resolves to OpenClaw before requesting TLS;
- existing containers are healthy before any mutation.

A failed collision check returns to Gate 1 with a new name/port; it is not solved by adopting
or deleting the conflicting resource.

**Done when:** every target resource is new, capacity is sufficient and the exact bounded
impact is recorded.

## Compose contract

Place the deployed stack at `/opt/freeplast-wordpress/`. Use a project name that cannot
collide with existing stacks, for example `freeplast-wordpress`.

Required services:

- `db`: supported MariaDB release, internal network only, persistent named volume, healthcheck;
- `wordpress`: supported WordPress/PHP Apache image for ARM64, persistent WordPress volume,
  depends on healthy database, restart policy, HTTP published only to
  `127.0.0.1:<approved-port>:80`;
- `cli`: compatible WP-CLI image sharing the WordPress volume and database network, invoked
  only with `docker compose run --rm cli ...`.

Use tested stable versions and record resolved image digests in DEPLOYMENT.md. Verify image
architecture before pulling. Keep database credentials in `.env` with mode `0600`; generate
separate random database root, application and WordPress administrator secrets. `.env.example`
contains keys without values.

Mount or copy the repository-owned theme and plugin through a repeatable deployment script.
Production runtime does not depend on the developer checkout.

### Compose proof

```bash
docker compose --env-file .env config --quiet
docker compose --env-file .env up -d
docker compose ps
docker compose exec -T db healthcheck.sh --connect --innodb_initialized
```

Adapt the database health command to the selected official image and record the verified
command beside its image digest.

**Done when:** config validates, all long-running services are healthy, only the approved
loopback port is published and restart reproduces the same healthy state.

## Nginx and TLS

Create one new file under `/etc/nginx/sites-available/<approved-hostname>` and one symlink in
`sites-enabled`. Follow an existing OpenClaw proxy vhost for headers and TLS convention,
without copying unrelated locations.

Proxy to `http://127.0.0.1:<approved-port>`. Forward at least Host, client address, forwarded
chain and HTTPS scheme. Set an upload limit appropriate for WordPress media. Protect dotfiles
and sensitive backup/config extensions at Nginx as defense in depth.

Before reload:

```bash
cp -a /etc/nginx/sites-available /root/nginx-sites-available.pre-freeplast-$(date -u +%Y%m%dT%H%M%SZ)
nginx -t
```

Use the server’s existing wildcard certificate only after confirming the approved hostname is
covered. Otherwise obtain a certificate through the server’s established ACME process after
DNS resolves. Run `nginx -t` again, then reload Nginx.

Inside `wp-config.php`, honor the forwarded HTTPS header before WordPress calculates scheme;
verify generated admin, media and REST URLs remain HTTPS.

**Done when:** `curl -I https://<approved-hostname>/` reaches only the new WordPress origin,
HTTP redirects to HTTPS, admin/REST/media URLs are HTTPS and `nginx -t` passes.

## WordPress bootstrap

Use the Compose `cli` service. Set:

- site URL and home URL: `https://<approved-hostname>`;
- locale: `es_CL`;
- timezone: `America/Santiago`;
- permalink structure: `/%postname%/`;
- staging visibility: discourage indexing;
- administrator email: owner-approved value.

Create the administrator credential without echoing it. Transfer it through the owner-approved
secret channel. Remove bootstrap files containing credentials immediately after use.

Disable dashboard file editing with `DISALLOW_FILE_EDIT`. Keep automatic security updates
consistent with the owner’s maintenance policy. Do not add SMTP credentials until the mail
provider and secret channel are approved.

**Done when:** WP-CLI returns the expected URL, locale, timezone and permalink values and the
administrator can log in through HTTPS.

## Deployments

Build deterministic theme/plugin ZIPs from repository files and record SHA-256 checksums.
Deploy by checksum, then activate through WP-CLI. Database migrations are versioned,
idempotent and executed by plugin activation/upgrade code.

A deployment records:

- UTC timestamp and operator;
- Git commit or source archive checksum;
- container image digests;
- theme/plugin ZIP checksums;
- migration version;
- pre-deploy backup IDs;
- verification result;
- rollback command.

Use maintenance mode only for the smallest necessary window and always clear it in a trap.

## Backup and restore

Before catalog import, plugin migration or release:

1. export the database with the selected MariaDB image’s supported dump command;
2. archive uploads and the deployed theme/plugin;
3. hash both artifacts;
4. store them outside the live Compose volumes;
5. run a restore rehearsal into temporary names and verify WP-CLI can read the restored site.

A backup that has not completed a restore rehearsal is not release evidence.

**Done when:** database and files restore into an isolated stack and the restored catalog and
quote counts match the source backup.

## Rollback

Rollback is scoped to the new Freeplast resources:

1. enter maintenance mode if the origin still runs;
2. restore the prior theme/plugin artifact and run its documented down-migration policy, or
   restore the paired database/files backup;
3. verify the origin through its loopback port;
4. run `nginx -t` and restore/reload the prior vhost when the proxy changed;
5. leave existing unrelated Compose projects and static `/var/www/html/freeplast/` proposals
   unchanged.

For full staging removal, disable only the approved hostname, bring down only the
`freeplast-wordpress` Compose project, and retain named volumes until the owner explicitly
approves deletion.

**Done when:** the prior reviewed state answers through HTTPS, data counts match its backup and
unrelated containers/vhosts are byte-for-byte or status-equivalent to their preflight state.

## Server finish line

Provisioning is complete only when health, HTTPS, backup restore, scoped rollback and
unrelated-service checks all have recorded proof. A running container by itself is not the
finish line.
