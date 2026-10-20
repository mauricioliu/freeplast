# DEV → staging catalog display release — 2026-10-20

Owner requested «has un release de la capa visual de DEV a staging» and, after comparison showed a catalog-data delta rather than missing CSS, confirmed DATA with «confirmo». Target is only https://freeplast.mliu.site; production is excluded.

## Reviewed delta

Read-only snapshots resolved all 17 existing products by unique matching slug, never by copying DEV database IDs (several differ). The sealed `catalog-display-release/review.json` contains exactly 54 row operations: 12 product rows (menu_order and the Romano → G2 display title only), 35 category/featured relationship additions/removals, two new category terms, and five taxonomy rows (four category descriptions/counts and featured count).

The nine featured products, in order: Universal Ventilada Negra, Universal Ventilada Color, Cosechera 3/4, Traversa G1, Traversa UPC, Traversa G2, Pollera, Frutera, Merlucera. Categories: Agrícola, Carnes, Productos del mar, Otros, matching the contained DEV configuration. Existing slugs, IDs, product content/excerpts, attributes, variations, quantities, images and commercial settings are not rewritten. Unrelated taxonomy memberships survive.

Public staging CSS `style.css` and `assets/css/woo.css` SHA-256 matched repository source. Screenshots had different browser zoom/DPR (DEV 1.125, staging 1.25); no release can synchronize the owner's per-origin browser zoom or current basket/session. This migration is not a full DEV database/media import or an assertion of pixel parity.

## Safety design

Separate `wp-release-catalog-display.json`, sealed closure and immutable stdin baseline; exact reviewed before-state required at capture. Original full owner-workspace fingerprint retained. Only a globally exact approved after-state projects the 54 rows back to their originals, including removed relationships and newly inserted terms, in the original numeric row order. All other product fields, metadata/variations/images, catalog records, users, legacy and HPOS requests, commercial options, roles and settings remain protected. This comparison means the protected invariant is unchanged, not zero database writes.

Writes require release-owned maintenance, InnoDB, locked exact before/after rows and a transaction. Retry accepts only an exact complete before or after state; partial/foreign states fail closed. Commit uncertainty never constitutes success. Cache invalidation is confined to catalog post/term caches and Woo product transients. No mail, external API calls, product save hooks, source user import or commercial-data migration.

32 offline checks pass, including compatibility with the standalone fingerprint, approved-delta projection, deleted/inserted rows, unexpected edits to protected tables/options, foreign baselines and staging guards. A separate read-only safety review found no confirmed blocker; the restored MariaDB trial is mandatory evidence for the real SQL path. A connection-loss partial write must retain maintenance and be inspected/recovered, not be asserted rolled back merely because ROLLBACK was attempted.

## Execution evidence

Pending the ordinary full project gate, paired backup, restore rehearsal, trial upgrade, live verification and smoke. The generated per-release record will provide actual outcomes. Dedicated staging cron must be suspended/restored with identical bytes; the independent staging mail shim must remain unchanged. Rollback is prepared only; because this is a migration, recovery is paired with fresh authorization and data-loss-risk acknowledgement.

Human visual acceptance, the real commercial workflow, real email delivery, browser zoom and session state remain outside these automated checks.
