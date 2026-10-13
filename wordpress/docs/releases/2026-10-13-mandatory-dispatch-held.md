# Mandatory Google dispatch selection: held-release history and completed recovery

**Latest: published only to staging.** Sealed logic release `20261013T010349Z-272948` completed on source `044605fdfe296886da8b44145b3d4cefab950d16`, installing adapter 1.12.9 / theme 1.0.23. [Final release record](2026-10-13-logic-272948.md). All release phases passed; cron and protected state were preserved. Successful Google suggestion selection in the browser remains unverified. The following held-state evidence is historical, superseded by the recovery closure below. Production was not contacted or changed.

## Authorization and Google configuration

Owner expanded the access-only scope by explicitly selecting “Configurar la clave y completar la publicación en staging”, then authorized a new deployment attempt (“Sí, volver a ejecutar el despliegue en staging”) and a transport-only resume (“Sí, ajustar el transporte y retomar el release”). No quota, billing, API enablement, IAM or production changes were authorized or performed.

Reused the existing authenticated Chrome session. Account `liu.mauricio@gmail.com`, project `freeplast-despacho` / number `878776393566` were confirmed in the live Google Cloud UI and matched the browser-key metadata in pass. The authenticated daily browser was reached through its existing DevTools endpoint; its Google session was not copied or recreated. Other tabs/sessions were not controlled.

Created **Freeplast Direcciones Servidor Staging**, credential ID `0af598db-5b1f-40a9-9842-5cd2efae638a`, with restrictions selected before creation and checked afterward:

- Places API (New) only.
- Exact staging IPv4 `178.105.30.70` and IPv6 `2a01:4f8:c014:7b8::1`, both verified as server egress addresses. No subnet wildcard.
- Existing browser key, project quotas, billing and IAM unchanged.

Stored the private key through pass as `freeplast/google-places-server-staging`, without printing its value. Configured `FREEPLAST_GOOGLE_PLACES_SERVER_KEY` in staging's private `wp-config.php`, preserving file owner/mode. Previous configuration is retained privately at `/root/freeplast-release-operations/dispatch-20261012T234536Z/wp-config.before-server-key.php` (root-private directory, backup mode 0600). PHP lint and the boolean configuration inventory passed.

One real browser prediction request supplied the reviewed Providencia Place ID. A subsequent submission-free WordPress HTTP lookup from staging returned **HTTP 200**, matching Place ID, nonempty canonical address and Chile country component. Provider payloads/key values were not printed. This proved credential/provider transport at the held checkpoint, not the then-uninstalled checkout validation.

## Release phases and failure

Used an isolated clean clone at `/tmp/freeplast-dispatch-finish-SkwFNb/repo`; unrelated main working-tree changes were excluded. The first attempt `20261012T234729Z-194ad2` stopped at the local clean-tree gate because the previous-release registry was initially copied one directory too high. Corrected the clone-only history placement, preserved failed evidence, restored cron, and obtained fresh owner authorization. No code was installed by that attempt.

Release `20261013T010349Z-272948`:

| Phase | Result |
|---|---|
| Safety | source-matched receipt, 46 executable negative regressions and 23 CLI cases passed |
| Gate | complete native release gate passed on committed source |
| Package | plugin 1.12.9 (347 files), theme 1.0.23 (46 files) |
| Transfer | sealed bundle transferred to `/var/lib/freeplast-release/bundles/20261013T010349Z-272948` |
| Backup | paired backup created at `/root/freeplast-backups/releases/20261013T010349Z-272948`; LIVE-CHECK=22 |
| Rehearse | **not completed**: downloading the approximately 90 MiB `files.tgz` was impractically slow |
| Trial/install/verify/smoke/record | **not executed** |

Native test coverage is also recorded in [the completion review](../../../docs/reviews/dispatch-google-selection-2026-10-12.md): 752 real-stack checks, 2671 aggregate checks, Places PHP/JS and photo gates. Native fixtures use synthetic provider transport and suppress real mail.

After a bounded transfer attempt, terminated only the release-owned SSH download child so the unchanged driver recorded a failed fetch. Site maintenance had not begun. Restored cron byte-identically and inspected the original fingerprint before the authorized resume. The resume used a **process-local SSH transport shim**, applying `IPQoS=none` to the same `hetzner-vps` alias only; no target override, host-key change, global SSH config, driver source, sealed bundle/config/manifest or fingerprint modification. Small probes improved, but the resumed full transfer remained at a few KB/s. Terminated only that release-owned download and restored cron again. The partial local archive is not restoration evidence and must not be used as a complete backup.

## Staging state at the held checkpoint (historical)

- Site open; `.maintenance` absent; dedicated cron restored with SHA-256 `acdce0f08554cf34c4a62c0316b0dff873ec24ec61d125befe7240184f270285`.
- **Adapter 1.12.8 / theme 1.0.22 remain installed.** Mandatory server-confirmed selection/references code is not live.
- Protected-record fingerprint still exactly matches the immutable paired-backup baseline, including after the resumed attempt. No orders or quotations submitted.
- Independent mail blocker unchanged (SHA-256 `71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1`); `blog_public=0`.
- Real key/provider operation verified; new checkout browser/layout/device behavior remains unverified. Two disposable browser preparation attempts timed out; no staging visual acceptance is claimed. Shared cart was not edited by the checks.

Local state/logs (do not publish private backup archives or configurations):

- `/tmp/freeplast-dispatch-finish-SkwFNb/repo/.build/wp-release/20261013T010349Z-272948/state.json`
- `/tmp/freeplast-dispatch-finish-SkwFNb/release-retry.log`
- `/tmp/freeplast-dispatch-finish-SkwFNb/release-resume.log`
- `/tmp/freeplast-dispatch-finish-SkwFNb/transport-bin/ssh`

## Delegated recovery, same day

Owner requested a live herd worker. Created independent session `freeplast-publicacion-81478ae9`, worker `publicacion-1`, `openai-codex/gpt-6-astra` through Pi, medium. Jev returned ambiguity and preserved uncertain Android/Flutter requirements; preflight permitted local work only. The worker therefore diagnosed locally, without SSH/browser/provider/deployment effects; lead retained external operations. No requirement or native-tool equivalence was fabricated.

Worker verified that both restore and trial fetch the entire backup independently and that phase retries reset the local download. Synthetic source/transport tests passed when independently rerun by the lead. After finishing, the lead reran the complete native release gate against committed source: 752 real-stack checks, 2671 aggregate checks and native photo gates passed. Full output `/tmp/freeplast-dispatch-finish-SkwFNb/lead-herd-gate.log`. Worker deliverable `/tmp/freeplast-dispatch-finish-SkwFNb/worker-network-diagnosis.md`; manifest `~/.herd/manifest-freeplast-publicacion-81478ae9.json`. Pane remains open.

With fresh explicit owner approval, lead qualified the existing staging IPv6 destination while preserving the original pinned SSH identity (`HostKeyAlias=178.105.30.70`, strict checking, no known-host edits). Identity authentication and exact staging home passed. The single 8 MiB synthetic receive probe timed out after 90.001 seconds (exit 124), failing the transfer threshold. No backup-archive probe or release resume followed; no new staging writes occurred. The initial timing utility was unavailable, so that command never ran; the actual probe used Bash's timing builtin. No repeated network attempts or unreviewed route fallback were performed.

Final lead checks: HTTP 200, no maintenance, cron present with original hash, adapter still 1.12.8, protected fingerprint still equal to original paired-backup baseline. Diagnosis is verified; **publication is not complete**. Exact network fault remains unconfirmed. Genuine blocker: a stable SSH transfer path capable of completing the paired-backup restoration/trial. Neither IPv4 nor IPv6 has demonstrated that capability.

## Completed supervised recovery

Owner explicitly requested continued babysitting. Lead reactivated the same worker for local implementation, not live effects. A private resumable cache and exact-argv SSH seam were developed outside the sealed repo. Lead found and reproduced cache traversal and short-write defects; the worker corrected them and added regressions. Hard-kill pending-file recovery, exact binary/stdin passthrough, actual targetFilesGet compatibility, metadata drift, timeout/owned-child cleanup and private enum-only diagnostics were tested. Lead's final independent offline gate: **39 tests passed**. Original worker guards remain disabled; no native Android/Flutter/browser tool equivalence was claimed. A stopped fleet server was restored to the same named session/native Pi history without a new model or substitution.

A separately authorized first inspection failed SSH255 after101.260s, with lead finally closing the owned child; no valid receipt or payload cache resulted. After another explicit bounded authorization, a diagnostic inspection passed in4.591s. Authenticated metadata/full hashes matched the remote manifest and LIVE-CHECK22. Because the historical state never contained the backup manifest digest, the newly reviewed observed manifest was pinned separately, not represented as historical evidence or written over the release baseline. The first180s prefetch preserved50,529,861 verified bytes; the second completed in159.568s with **95,903,478 exact payload bytes** across six files, full hashes and closing origin/manifest/SSH0 checks. Third session was not used. Private cache was independently rechecked offline; no bytes/keys exposed. This demonstrates resumability for this backup, not a diagnosed network cause or generally improved throughput.

Fresh owner authorization: “Sí, reanudar y publicar solo en staging”. Preflight reconfirmed staging home, original fingerprint, original cron owner/mode/hash, mail shim, noindex and sealed bundle. Only dedicated cron was moved into `/root/freeplast-release-operations/cache-resume-...`; its worker drained, original fingerprint rechecked. Resume used the exact existing release/config/bundle via a process-local PATH shim, intercepting only the six original backup cats. No sealed source/config/manifest/baseline or release safety receipt was changed. Restore, trial, maintenance install, installed hashes/versions/state/fingerprint, public smoke and record all passed; driverexit0, state COMPLETE. Log `/tmp/freeplast-dispatch-finish-SkwFNb/lead-release-resume.log`.

Postchecks independently confirmed adapter1.12.9/theme1.0.23, HTTP200, maintenance absent, original protected fingerprint, original mail blocker/noindex and component ownership freeplast:freeplast. Dedicated cron restored with exact original bytes/root:root0644/hash. Lead transport copies disabled afterward; private backup/cache remain outside webroot/Git. Production, quotas, billing, API enablement and IAM unchanged; no rollback performed.

Limited real-browser checks: Google widget loaded; canonical textarea readonly, references separate, missing selection blocked by actual capture handler before a safety sentinel could allow Woo checkout; no real submission. No-dispatch hid address/cleared provenance. Public Providencia queries did not produce selectable suggestions in this browser, despite observed RPC200; therefore actual selection, replacement after selection, mobile/keyboard/human acceptance are **not certified**. The temporary test cart was emptied; original protected fingerprint reconfirmed afterward. No real order, quotation or mail was sent.

## Superseded next step at the held checkpoint

Resolve or wait out the staging-to-operator SSH transfer bottleneck. Then inspect the retained failed state/config/bundle and a fresh live fingerprint, coordinate this site's cron, and obtain **fresh authorization** to resume the same sealed release. All restore/trial/install/verify/smoke gates still apply. If protected records changed, do not overwrite the original baseline; follow the release recovery rules or prepare a newly authorized release with a new backup. Previous completed release `20261011T140349Z-17d550` remains retained for compatible recovery; rollback is not authorized or executed.
