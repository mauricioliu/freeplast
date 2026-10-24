# Local variation photos — ticket 1

Ten existing variations only: closed/ventilated Universal crates, Blanco/Rojo/Amarillo/Azul/Verde. Inventory: lead's `catalog-local-inventory.json`, local home `http://mliu:8131`; own image IDs were all zero. Parent images and original media remain untouched.

## Assets

- Red: unchanged reviewed catalog sources `../catalog-photos/caja-universal-{cerrada,ventilada}-color.webp` (960×960). Both inspected: closed/ventilated red crates respectively.
- Other eight: Codex native `image_gen` recolors, one attempt each, no retries/refusals/paid API. Originals retained at `~/Pictures/ai/20261023-freeplast-{cerrada,ventilada}-{blanco,amarillo,azul,verde}.png` (1254×1254 RGB PNG).
- Derivatives: ImageMagick resize to 960×960 WebP, quality 90. `manifest.json` seals every file with SHA-256, dimensions, identity and provenance. No originals replaced.
- Inspection: eight-output contact sheet checked against both red sources; colors, closed vs perforated geometry, perspective, logo and overall framing retained. These are reference recolors, not proof of physical color accuracy. Final integrated mobile/desktop visual approval remains lead-owned.

## Apply (lead only)

First verify a local database **and uploads** backup. Copy this directory and `wordpress/scripts/{import-color-photos.php,lib/color-photos.php}` into the same relative layout accessible to local WP-CLI. No worker executed this importer against WordPress.

```
wp eval-file wordpress/scripts/import-color-photos.php plan
wp eval-file wordpress/scripts/import-color-photos.php apply <fresh-token> http://mliu:8131
wp eval-file wordpress/scripts/import-color-photos.php plan
```

Plan is read-only. Apply requires local environment, exact home, sealed WebP assets, exact variation ID/parent slug/color, empty own image (or identical existing file), and fresh token. All rows preflight before writes. Existing conflicting images block; never overwrite or delete them. A repeated fresh plan/apply is a no-op. Verified attachments from an interrupted assignment are reused by hash. Original photos, parent records, prices and quote documents are not modified.

On any failure, inspect reported file/attachment and current plan before retrying; completed assignments remain. Do not blindly replay an uncertain upload. Restore is a lead-owned backup operation, not implemented here.

## Checks

`php wordpress/scripts/color-photos-test.php` checks all ten real hashes/formats/dimensions and mocks WordPress persistence for environment/home/identity/conflict/token refusal, successful attachment, idempotency and interrupted-assignment reuse. Included in `npm test`.

Runtime still required: apply after backup; confirm all ten variation images and thumbnail/full-size media; choose/change/clear on featured cards and product sheets at mobile/desktop; check an unassigned image shows explicit unavailability. Woo owns sheet image/lightbox switching; the theme never changes variant identity or cart transport. Coordinate a theme asset-version bump on integration so browsers receive changed JS/CSS.
