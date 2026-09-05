# Retired private catalog/quote implementation

Retained as source history after the owner-approved WooCommerce replacement (2026-09-05, ADR-0001). This directory is NOT packaged or activated by the current deployment. Original historical quote posts remain untouched in the database and are also mapped into Woo Orders.

Do not reactivate the legacy plugin against the migrated catalog or rerun its JSON synchronizer. For rollback restore the paired pre-migration database/files backup, not just one plugin. The deployed original plugin is retained inactive for recovery.
