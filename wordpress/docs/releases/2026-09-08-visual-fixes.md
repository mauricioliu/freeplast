# Visual cascade fixes — staging release, 2026-09-08

Owner confirmed the reported defects, requested fixes, then answered **“sí”**
to commit, push and deploy to staging. This operation targets
**https://freeplast.mliu.site/** only; not `freeplast.cl` or static proposals.

## Identity

- Implementation commit **`3156aae04ad00a74094effce518bdea7da331ea3`**, pushed to main.
- Release **`20260908T231752Z`**, completed **23:24 UTC**.
- Theme **1.0.14 → 1.0.15**. Adapter **1.6.8**, fields **1.0.4**, Woo **11.1.0**,
  Quotes **2.13** unchanged.
- Theme ZIP SHA-256:
  `da22d8c3b979b5dcfd933dda2dc35bc50ecd6a181084c2065b3eff5d1a15aaf1`.
- Theme ZIP only installed. **No migration/bootstrap, page edits, catalog/media
  changes, plugin/vendor installs, mail reconfiguration or production changes.**

Fix details and pre-release red/green evidence:
[local correction record](../visual-review-2026-09-08/local-fix/README.md).
Its “not deployed” statements describe the preceding checkpoint, now superseded
by this separately authorized release.

## Backup, rehearsal and deployed checks

- Paired SQL/files backup at
  `/root/freeplast-wordpress-backups/20260908T231752Z/`; checksums verified.
  Brief maintenance during capture; secrets inherited through environment,
  never printed. Backup includes full files/database, stored privately.
- Restored into isolated **DB + CLI only**, preserving numeric owner 33.
  No rehearsal HTTP listener. Verified **17 products / 7 Woo orders / 2 original
  requests**. Trial theme upgrade passed **100 native state checks**, **47**
  installed theme/adapter file hashes and protected-record equality.
- Temporary project `freeplast-wordpress-restore-20260908t231752z` containers,
  volumes and network removed; absence checked.
- Live deploy first checked the previous **45** source hashes, then repeated
  **100 native state checks**, **47** new hashes and data equality. Maintenance
  cleared, cache flushed, existing WP/DB containers stayed up without restart.
- Protected digest before/after deploy **and after browser smoke**:
  `06041e3bdff0e756cd2eededea0c50918729d3a5a1260fd167f0d8d74ae2bb88`.
  Includes all posts/pages/revisions, postmeta except editor locks, comments,
  order items/meta, taxonomy tables and critical options. No page exclusions
  in this code-only release. Ephemeral sessions/transients are not in the digest.
- Independent mail-containment MU plugin hash unchanged.
- Repeated offline gate passed: `FREEPLAST_SKIP_STACK=1 npm test` (504 adapter
  assertions, 423 aggregate checks plus named suites). Local layout replays had
  364/364 checks; these remain distinct from native runtime evidence.
- No PHP fatal/parse/warning matches in bounded post-install log check.

## Post-release HTTPS/browser smoke

- HTTP 200: Home, catalog, actual product permalink, empty basket, no-match
  product search. Empty-selection checkout redirects to basket as expected.
- Catalog has one shell, one search input, restored introduction/categories.
  The HTTP assertion matches class tokens: native ClassicTemplate appends
  `alignwide`; an initial exact-class-string assertion was corrected, not site code.
- Both deployed CSS responses matched source bytes exactly:
  - `style.css`: `aeb8f8e9b6614f9858d93590eef19cd051154548358a21da889caed18a628c1d`
  - `assets/css/woo.css`: `81232b171fe6bb359dcccf56ebbc52710535350e59a9f1133f0cc7179b4666aa`
- Real Chrome, native staging DOM at 412 px: first three catalog cards **365px**,
  CTA **175px**, inside the cards. Search/categories visible; screenshot inspected.
- Added **one own test Cosechera ×1** from the initial empty selection to inspect
  the hydrated basket. At 1440 px: main **810px**, summary **340px**, **50px** gap,
  no overlap; screenshot inspected. Checkout is centered with **112.5px** margins,
  **1200px** width and **one** privacy notice.
- No personal data entered, no quote submitted or mail sent. Removed that one
  test line and observed “Aún no agregas productos” before closing the owned tab.

These observations are **not owner approval**, a full native failure/race journey,
a matched A comparison, Site Editor or hardware validation. #48 remains partial.

## Operator artifacts and rollback

Scripts, logs, checksums, source identity and theme ZIP:
`/root/freeplast-release-20260908T231752Z/`.
Runtime-readable bundle:
`/opt/freeplast-wordpress/bundle/releases/20260908T231752Z/`.

Prepared/syntax-checked **code-only rollback, not executed**:

```bash
ssh openclaw 'bash /root/freeplast-release-20260908T231752Z/rollback.sh --execute'
```

Requires a separate rollback decision. It reinstalls theme **1.0.14** with full
WP upgrader directory replacement, verifies old hashes and protected data, and
never restores the DB over newer human records. It intentionally restores the
previous presentation defects along with the old code.
