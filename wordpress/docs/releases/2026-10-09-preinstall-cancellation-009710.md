# Pre-install cancellation: 20261008T230816Z-009710

Target: **https://freeplast.mliu.site only**. Production `freeplast.cl` untouched.

**Status: cancellation completed at 2026-10-09 03:43:07 UTC. Staging reopened on theme 1.0.21 / adapter 1.12.3; cron restored.** This is not a successful deployment record. Theme 1.0.22 remains unpublished.

## Why installation stopped

The desktop visual implementation is commit `8cbb9c815c1cbde42e68380ad9c2620840d2102d`, theme 1.0.22 and unchanged adapter 1.12.3. LOGIC release gate/package/transfer/backup passed in the earlier session. Resume with owner quote `continua el trabajo` passed local restore rehearsal (LIVE-CHECK 20) and isolated trial after moving the release clone to disk-backed storage.

The original local download had a truncated `files.tgz` and empty checksum files. Remote hashes remained valid. Disk-backed retry downloaded all 93402053 bytes and verified the original manifests. The historical truncation cause is not proven; `/tmp` quota was plausible, but fresh 128 MiB probes passed in both locations.

Install then rejected `protected records changed since backup; refusing install`, before the immutable chain's first theme/plugin installation. The operation-owned maintenance guard remained active and the dedicated Freeplast cron stayed paused.

## Read-only diagnosis

Owner authorized inspection with `autorizo`. Per-row/per-column hash comparison reproduced both full fingerprints using the unchanged protection contract. The backup was restored into an isolated internal-network Docker stack with cron disabled and plugins/themes skipped. Disposable resources were removed and absence confirmed.

Only difference: `posts.ID=64`, an empty `post` in `auto-draft` status, had been deleted. Every other protected row matched, including quote requests, commercial options, product records/meta, users, comments, order lines and addresses. WordPress's automatic stale-draft cleanup is compatible with this difference, but neither the actor nor deletion time was established.

- Backup fingerprint: `c6870a5f9d8283497ade1606cd1e2362dc7760aabd244c233a5ed3d560655dec`.
- Inspected current fingerprint: `9993500b517fa4c047e92f851b4860edf453202d8cd8c960146aa5adde8ca91d`.
- Installed source hashes match completed release `20261008T191746Z-1e969b`, theme **1.0.21**, adapter **1.12.3**.

No data was reinserted merely to satisfy the old fingerprint. No database/files restoration or deployment was performed by the inspection.

## Authorized recovery design

Owner authorized implementing, testing and applying a safe cancellation with a new `autorizo`, explicitly to reopen without restoring or modifying data.

The failed bundle was created in a temporary clone without prior registry history, so its sealed rollback wrapper has no previous bundle configured. It was not edited. Instead, the global `~/.agents/skills/wp-release/` now has a certified `cancel-preinstall` command accepting the separately retained, completed previous state.

Controls:

- Same sealed configuration, target and install scope; both local and remote manifests pinned to retained state hashes.
- Exact reviewed install runtime; original failure must be the pre-install fingerprint rejection and no install phase/fingerprint may exist.
- Operation-owned lock; no remote `FINGERPRINT-PRE`/install-start sentinel.
- Old installed source hashes, versions, old state hook, and inspected current data fingerprint before reopening.
- Public status/asset checks against **previous sealed ZIPs**, not the newer working tree; old-code/data checks repeated after smoke.
- Failure re-holds maintenance. No theme/plugin installation, SQL import/reset, baseline replacement or ad hoc guard removal.
- Terminal local cancellation record plus remote tombstone; old phase/failure evidence remains intact. Any later deployment requires a new release and fresh paired backup.

A read-only independent agent review found and helped close an absolute-backup-path guard defect. Native testing exposed stdin consumption by Docker exec; the recovery program is now safely quoted separately from stdin. Both have negative regression coverage. Native testing also caught incorrect quote handling in the self-test WP-CLI helper; fixed helper quoting and explicit numeric fixture-ID assertions prevent false evidence.

## Evidence and application result

Full certification passed at `2026-10-09T03:41:28.714Z`: 7 plan regressions, 46 executable safety cases, 23 CLI cases, five complete native fixture releases, verification failure/resume, native cancellation preserving a post-backup row, real code-only/paired rollback, and data migration/recovery on both rehearsal backends. Owned Docker resources were removed. Certified source hash: `b21cb4ed5f5819d2732aa097583b7a155f950e4283b99a3220b95dacfd926167`.

The supported cancellation was applied with owner quote `autorizo`. It completed nine public HTTP smokes (all 200, four assets byte-identical to the previous bundles), old installed-source/version/state checks, and unchanged current fingerprint before/after. Separate final GETs confirmed Home/Catálogo and byte-identical old public `style.css` (SHA-256 `3737a5ddd8be64dc7083fa51987f5bd3333482cdab31b0fc3bbb386d98c997d4`).

Final read-only target checks confirmed no maintenance marker, lock or MU guard; the remote cancellation tombstone exists; previous installed-source hashes and every original backup hash pass. Dedicated `/etc/cron.d/freeplast` was restored from its retained original and its checksum passed. The protected fingerprint still matched **after cron restoration**. No SQL restoration or new artifact installation occurred.

Local state is terminal `cancelled`, with cancellation evidence separate from the original install failure and unfinished release phases. State/bundle evidence was copied byte-for-byte into the main repository's ignored `.build/wp-release/` and its registry adopted the cancelled entry. Do not resume the old `/tmp` clone. For any later deployment, plan against the last **completed** implementation commit `e5a4a7799d63bc984714122c322c0c3f62f39262` explicitly: a cancelled registry entry is not a deployed baseline. A new release ID, authorization and full fresh ceremony are required.

Private evidence root: `/home/mauricio-liu/.cache/freeplast-release-20261008T230816Z-009710/`:

- Active clone/state: `repo/.build/wp-release/20261008T230816Z-009710/`.
- Diagnostic hashes and findings: `diagnosis/` (no raw customer values in reports).
- Original skill sources: `skill-before-cancel/`.
- Recovery wrapper/log: `cancel-authorized.sh`, `cancel.log`; exit status `cancel.exit` is 0.
- Complete certified run: `diagnosis/self-test-certified.log`; earlier failed fixture runs retained separately, not represented as passes.
- Global skill changes retained as `wp-release-cancel-hardening.patch` and `skill-after-cancel/`; source paths are `scripts/release.mjs`, `scripts/cancel-preinstall.sh`, safety/CLI/self-tests, `RECOVERY.md`, and `REFERENCE.md`.

## Limits

This recovery is not visual acceptance or publication of the desktop fixes. No physical-phone review, valid quote submission, mail delivery or business sign-off is claimed. Public smoke observes a brief open window; external writers/cron require separate coordination. Historical binaries must not be run directly to bypass the cancellation tombstone.
