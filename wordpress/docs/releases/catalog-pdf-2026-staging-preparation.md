# PDF 2026: staging DATA preparation (not deployed)

Stage-2, 2026-10-13. Source base `413ba25`. **PARTIAL step 6; no staged installation, backup, restore/trial, checkout test or visual acceptance yet.** Steps 7–8 staging subtasks remain pending. Production is excluded.

## Read-only native preflight

Target: `hetzner-vps`, native WP-CLI as `freeplast`, `/var/www/freeplast`, `https://freeplast.mliu.site`. Confirmed home/siteurl, environment `staging`, `blog_public=0`, `DISABLE_WP_CRON=true`, maintenance absent. Installed adapter `1.12.10`, theme `1.0.24`.

Resolved all 15 products through the existing PDF planner: unique `_fp_source_id`, matching SKU, slug and type; both five-color variation families remain intact (10 children). All 15 descriptions/excerpts match the pre-PDF reference at `df459a2^`; no later merchant copy conflict found. Full native diff inspected against the reviewed ledger. Unknown PDF values remain untouched; categories, G2/Romano identity, photographs, prices, quantity policy and variations remain unchanged.

| Native ID | Slug | PDF page |
|---|---|---|
| 10 | caja-cosechera-3-4 | 7 |
| 12 | caja-universal-cerrada-negra | 8 |
| 14 | caja-universal-ventilada-negra | 10 |
| 16 | caja-universal-cerrada-color | 9 |
| 23 | caja-universal-ventilada-color | 11 |
| 30 | caja-tomatera | 12 |
| 32 | caja-frutillera | 13 |
| 34 | caja-frutera | 14; capacity retained from summary 3 |
| 36 | caja-paltera | 15 |
| 38 | caja-merlucera | 20 |
| 40 | caja-pollera | 19 |
| 42 | tote | 6 |
| 44 | traversa-para-bins-tipo-upc | 18 |
| 46 | traversa-para-bins-tipo-g1 | 16 |
| 48 | traversa-para-bins-tipo-romano | 17 |

Full owner-workspace protected digest before/after preflight was identical:
`2c734949b0ed010cdeb7bcb699ac773d63b93e375c231bc3b3c6f37e50d92966`.

Independent staging mail shim SHA256:
`71164446f3f7432311ed917e412c161f8076acda57a4b3eb02b095a63009d5f1`.
Its bytes match the repository shim blocking every `wp_mail` on this staging hostname; no mail was attempted. Cron file SHA256:
`acdce0f08554cf34c4a62c0316b0dff873ec24ec61d125befe7240184f270285`.
Cron was not suspended or changed. Release/bundle directories already root:freeplast0750; backup directory root0700.

Private operator evidence (outside Git/webroot):
`~/.herd/plan-mode/plan-mv6vrtm2-rsaa.stage-private/preview-4.json` (complete native preview), `expected-delta.json` (locally derived exact proposed rows, **not a driver backup baseline**), `offline-tests-2.log`. Earlier failed read-only transport probes remain in this private directory; none performed a catalog mutation. Do not publish raw snapshots or commercial rows.

## New release closure

`wp-release-catalog-pdf.json` reuses the native target, full committed-source release gate, state verifier and isolated local rehearsal. `package-catalog-pdf.py` builds deterministic adapter/theme ZIPs and a sealed five-file migration closure, without downloads. Theme version becomes **1.0.25**; no visual redesign. Source reference hash remains `a88c84feddf1ad54f10587d23cd99a3e1d3c8d110b1d88768350d7a7e84e9f3a`.

`catalog-pdf-release/review.json` holds only IDs/source identities and hashes of the exact reviewed existing post + existing `_product_attributes` row. It binds capture to this inspected state, not any later JSON synchronization. Drift requires a new inspected change, never silently changing the seal. Capture refuses an already-applied release; an exact completed state can be verified/retried only with its original sealed baseline.

The closure changes **only** `post_content`, `post_excerpt` and the existing `_product_attributes.meta_value` for these identities. Raw transactional compare-and-swap deliberately avoids Woo save hooks, timestamps, lookup-table churn and unrelated effects. Technical local attributes use Woo's native serialized representation; existing flags, merchant attributes, variable Color and unknown facts are preserved. No creates, deletes, timestamps, taxonomy changes, option writes or commercial writes. Native Woo planner verifies the resulting facts after apply.

Apply requires the driver's release-owned maintenance lock/guard, original full protected digest, InnoDB, row locks and exact original/expected state. Partial/foreign states fail closed. COMMIT uncertainty is an inspection stop, not permission to replay. The fingerprint duplicates the standalone owner-workspace contract (including users, roles, catalog taxonomy, HPOS and commercial options), projecting only **whole rows exactly equal to the derived expected rows** back to their captured originals. Unexpected bytes stay visible in the digest; no broad exclusions or post-install baseline adoption.

## Executed checks

- `FREEPLAST_SKIP_STACK=1 npm test`: green (1946 aggregate checks); 135 new offline delta/fingerprint checks, deterministic ZIP/closure/CLI regression and existing suites. Native HTTP stack skipped.
- Exact compatibility with standalone owner fingerprint; expected-delta equality; changed product/meta/user/HPOS/commercial-option/category rows fail invariance; null/variation/taxonomy/production guards exercised offline.
- Actual native preflight snapshots matched all 15 sealed before hashes; local proposed baseline re-derived/validated.
- PHP lint for closure/fingerprint; `git diff --check` clean.
- `release.mjs --config wp-release-catalog-pdf.json plan`: valid config, mandatory DATA tier. **No `run` issued.**

## Remaining ceremony, after lead SOURCE INTEGRATED / RELEASE GO

Lead accepted the private preview/exact-row delta for gate on 2026-10-13. Commit/integration and fresh full release gate remain required. At lead request the entire offline `.build` is preserved outside the worktree at `~/.herd/plan-mode/plan-mv6vrtm2-rsaa.stage-private/offline-build-preserved`, leaving no `.build` for the lead's fresh native gate on unused loopback18093. Preserve existing acceptance/release history. Follow [native runbook](native-staging-runbook.md) and wp-release MIGRATIONS/RECOVERY protocol; use this dedicated config, not the completed role migration or historical OpenClaw target.

1. Source-matched driver safety receipt/negative regressions. Private deterministic bundle and paired DB/files backup, checksums and immutable MIGRATION-BASELINE.
2. Isolated MariaDB restore and native trial must prove raw serialization, real transaction/rollback behavior, native reads and exact protected delta. **Offline mocks do not prove MariaDB persistence or rollback.** Stop before live installation if any gate fails.
3. Coordinate only `/etc/cron.d/freeplast`, retaining exact hash/owner/mode; wait for its existing worker. Preserve mail shim bytes/noindex. No unrelated services stopped.
4. Driver-owned maintenance install; exact source/version checks, native migration verification and original protected fingerprint, public smoke. Restore cron only on verified success.
5. Step 7: all 15 rendered fiches against ledger, ten color identities, quantities/options/basket and one new run-owned contained synthetic request. No historical request used. Lead owns browser responsive 320/375/768/1440 and keyboard review; HTTP assertions are not visual acceptance.
6. Step 8: generated sanitized release record, staging URLs, synthetic fixture reference and remaining human decisions. Production needs separate approval.

No production, browser, device, Maps, checkout submission or external delivery was performed. New closure transaction/native trial and driver safety receipt remain unverified in this preparation; source is uncommitted for lead integration.
