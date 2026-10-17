<?php
/** Local-only, one-shot PG22 meta patch; no historical catalog import. */
$fp_quantity_patch = true;
require __DIR__ . '/update-catalog-minimums.php';
