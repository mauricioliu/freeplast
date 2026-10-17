# PG22 catalog selection (one-shot, not a synchronizer)

WooCommerce remains the editable catalog. `wordpress/data/products.json` is historical and is neither changed nor imported by this update.

## Scope

- Featured, ascending native menu order: Universal Ventilada Negra, Universal Ventilada Color, Cosechera 3/4, Traversa G1, UPC, G2, Pollera, Frutera, Merlucera.
- Traversas and Tote use the existing Agrícola category. Native recommended ordering already sorts featured products by menu order, keeping G1 → UPC → G2 consecutive.
- Romano becomes **Traversa para Bins Tipo G2**, without changing its ID, source ID, SKU or `traversa-para-bins-tipo-romano` slug. Existing URLs and relationships remain valid.
- Pediluvios becomes draft + hidden, not deleted. Native public Woo catalog/search/purchasability exclude it. Historical order lines and documents are not touched.
- Other products only lose featured status. Prices, specifications, media, variations and relationships outside category assignment are protected by full before/after snapshots.

## Guarded application

Not run automatically on startup, upgrade or page load. Current tool **rejects staging and production**. This work order permits only explicitly disposable local/development databases, never the daily local database. Do not point it at any persistent database.

With writers paused, maintenance enabled and a verified paired database/files backup, use WP-CLI on the disposable site:

```sh
FREEPLAST_DISPOSABLE_CATALOG=1 wp eval-file wordpress/scripts/update-catalog-selection.php plan /private/receipt.json
# Review all receipt rows and the printed token before applying.
FREEPLAST_DISPOSABLE_CATALOG=1 FREEPLAST_CATALOG_BACKUP_VERIFIED=1 FREEPLAST_CATALOG_WRITERS_PAUSED=1 \
  wp eval-file wordpress/scripts/update-catalog-selection.php apply /private/receipt.json <reviewed-token>
```

The receipt directory must be private (0700), outside the webroot; receipt is exclusively created with 0600 permissions. Apply requires `.maintenance`, InnoDB, a DB advisory lock, row locks, an unchanged inventory/token and protected-state verification before commit. Flags acknowledge independently checked conditions; they do not establish backup or pause evidence.

Success writes `freeplast_catalog_selection_pg22` once. Repeating the command then performs no product writes, even if staff subsequently change names, featured selection, categories or ordering. No runtime enforcement overwrites daily commercial edits. Do not delete that marker to force a replay. An uncertain commit requires inspection, never automatic retry.

Theme Home now requests nine cards. Database-stored template overrides, actual native HTTP flows, taxonomy caches, transactional rollback and visual mobile/desktop output still need the coordinator's disposable native integration gate. Native Woo permits editors to inspect draft products; this patch does not alter staff capabilities.

## Offline check

```sh
PHP_BINARY=/path/to/php FREEPLAST_SKIP_STACK=1 npm test
/path/to/php wordpress/scripts/catalog-selection-test.php
```

Synthetic test covers review guards, identity drift, merchant drift, exact featured order, categories, G2 identity, draft/hidden archive, variant preservation and repeat/no-overwrite behavior. No database/service/browser is started.
