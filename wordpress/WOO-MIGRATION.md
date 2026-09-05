# Freeplast — Woo migration and operating record

Date: 2026-09-05. Scope approved explicitly by the owner: replace the repo implementation and **https://freeplast.mliu.site/**, back up first, preserve catalog/media/history; **do not change freeplast.cl**. Decision: [ADR-0001](../docs/adr/0001-woocommerce-quote-only.md). No paid plugin licenses.

## Acceptance review update — 2026-09-05

[Post-migration review and evidence](../docs/reviews/woo-acceptance-2026-09-05/README.md): **not ready for operational acceptance**. Two simultaneous submissions from one anonymous session created duplicate orders **67/68**; the retained `ventas_freeplast` role lacks Woo order capabilities. Additional findings: silent quantity rollback on network failure, eight unnamed Home links, and the variable-product button's missing disabled semantics/low contrast. No fixes or new release were deployed in this review.

Offline checks remain 29; read-only runtime checks passed 68 after three new technical orders. Admin private-note persistence and unpriced HTML request-email rendering checked; real delivery, restricted-user login, physical mobile acceptance and performance measurements remain pending. See the review for exact scope rather than interpreting these checks as full acceptance.

## Current implementation

- Existing WordPress 7.1 / PHP 8.3 / MariaDB stack, `/opt/freeplast-wordpress` on SSH alias `openclaw`; Nginx/hostname unchanged.
- WooCommerce **11.1.0**, Quotes for WooCommerce (TechnoVama) **2.13**, `freeplast-woo` **1.0.2**, existing standalone Freeplast block theme adapted as **1.0.0**.
- Dependencies pinned by URL and SHA-256 in `woo-dependencies.json`; no commercial extension installed.
- Woo owns products, variations, guest sessions, cart quantity mutations, checkout, request persistence, notes and Orders administration. JSON is now import/reference material, **not** an ongoing synchronizer.
- `/tienda/` and `/producto/<slug>/`: native Woo catalog and products.
- `/cotizacion/`: native **Cart block**, titled **Productos a Cotizar**.
- `/datos-y-envio/`: native **classic checkout**, titled **Datos y envío**. Deliberate choice: quote extension's address options are not equivalent in Checkout Blocks.
- Small adapter: fiscal/dispatch fields, local validation, terminology, request-only side-effect guards, old routes, unpriced request emails. It uses Woo's existing checkout-review hook/session for form drafts, not a separate session or persistence service.
- Legacy code moved to `legacy/`; original installed plugin remains **inactive** on staging. Do not reactivate or sync JSON into the migrated site.

## Preserved data

- **17 products converted in place**, retaining IDs, slugs, attachment relationships, image files and original `_fp_*` metadata. Native Woo attributes expose the existing specifications; **10 color variations** created.
- Two original private `fp_quote` records remain, including every `_fpq_*` field. Their Woo counterparts: original **40 → order 58**, original **39 → order 59**. Old references retained; mapped metadata compared field-for-field by runtime checks.
- Old status/history/delivery-provider details remain in imported metadata; they are not translated into a new six-state workflow.
- Existing pages' pre-migration contents saved in metadata when replaced. Legacy URL aliases retained. Theme branding, photography, v6 shell and product direction reused; **no new visual approval implied**.
- Technical Woo price value `0` enables native purchasability internally. It is **not a commercial offer**. Public HTML prices/totals, priced structured-data offers and quote-email amounts are suppressed. Woo APIs/admin can still expose the technical zero; this is not a claim that every API contains no amount fields.

## Quote-only boundary

- Guest intake; no required registration, payment, automated invoice, checkout stock hold or stock reduction. Gateway restricted to the extension's no-charge quote gateway. Payment URL redirects to contact. Requests remain unpaid.
- Required: name, phone, email, company, RUT, giro and dispatch choice. Dispatch requires a nonempty full address; textarea prompts street, number, commune and region. No geocoding/completeness certification or automatic route distance. RUT is required, not checksum-validated; phone normalization is not yet implemented.
- Initial pending order label: **Solicitud recibida**. Sales works in Woo **Pedidos** and native private order notes. Other Woo statuses and extension completion controls still need operator review; do not treat them as priced-document functionality or payment authorization.
- Submitted details stored separately from editable core contact details. Local fiscal/dispatch metadata is displayed in Woo administration; a dedicated correction UI is not added.
- Quote-request emails reuse extension events with an unpriced template. Ordinary order/invoice and priced-quote emails disabled. **Independent MU plugin blocks all `wp_mail` on staging**; it records a count, no recipient/payload log. Actual delivery is not tested/enabled.

## Backups and recovery

Private server paths (not committed; contain personal data):

1. **Before migration:** `/root/freeplast-wordpress-backups/20260905T111748Z` — database + full WordPress files + checksums; isolated restore rehearsal recovered 17 original products before any replacement.
2. **After migration:** `/root/freeplast-wordpress-backups/20260905T114819Z` — paired backup; isolated restore matched **17 catalog products / 4 Woo orders / 2 original requests**. This captures adapter 1.0.1; subsequent 1.0.2 extends draft retention only.
3. **Before acceptance-test submissions:** `/root/freeplast-wordpress-backups/20260905T124710Z` — paired backup of adapter **1.0.2**; isolated restore matched **17 / 4 / 2**. Predates technical orders 66–68; not a post-review database snapshot.

`infra/backup.sh` now counts both architectures and Woo orders in its rehearsal, then tears down only its temporary restore project. It does not replace the live volumes.

Explicit paired rollback, as root on `openclaw`:

```bash
bash /opt/freeplast-wordpress-src/infra/restore-woo-backup.sh \
  /root/freeplast-wordpress-backups/20260905T111748Z --execute
```

This verifies hashes and staging hostname, enables maintenance, restores database **and** files, then flushes caches/routes. Failure leaves maintenance enabled for investigation. Do not restore one component alone. A rollback discards newer live changes; take a fresh paired backup first. Live rollback itself was **not executed**; isolated restorations were.

## Reproduce checks / package / release

```bash
npm test                         # offline only; no server or submissions
npm run woo:package              # wordpress/.build/woo-release/
```

If the project PHP tool is unavailable, set `PHP_BINARY` to an absolute PHP executable. Current offline result: **16 local field assertions + 13 syntax/dependency/deployment checks**. These are not simulated Woo integration coverage.

Read-only runtime checks after the bundle is copied to staging:

```bash
cd /opt/freeplast-wordpress
docker compose run --rm -T cli wp eval-file /bundle/verify-woo-state.php
```

Observed: **62 state checks passed** (catalog/variation counts, historical metadata and lines, native pages, request/payment/stock guards, required fields and containment count).

The HTTP regression **creates one clearly marked test order**; it is not part of `npm test`:

```bash
python3 wordpress/scripts/verify-woo-http.py --execute-staging
```

Never run it on production or while staging mail containment is disabled. Recorded technical orders **63 and 64** remain for evidence, with `example.invalid` addresses. Order 63 exercised submission but exposed a confirmation-template issue; after that fix, order 64 passed the full regression. Do not mistake these for customer requests. The subsequent acceptance review retained technical order **66** (normal regression, plus one private audit note) and **67/68** (same-session concurrent duplicates); all use synthetic `example.invalid` contact data. Never process/resend/delete them automatically.

Release procedure: package locally; upload bundle files to `/opt/freeplast-wordpress/bundle/` and scripts to `/opt/freeplast-wordpress-src/infra/`; on the server run:

```bash
FREEPLAST_BACKUP=/root/freeplast-wordpress-backups/<verified-stamp> \
  bash /opt/freeplast-wordpress-src/infra/deploy-woo.sh
```

The migration is resumable: marked products/pages/orders are skipped, not re-synchronized. It still enforces quote-only site options. Do not use it to overwrite later Woo catalog edits. Old `infra/deploy.sh` deliberately refuses execution; older build/target/verification documents are marked historical.

## Observed integration results and limits

- Anonymous HTTP: missing color rejected; simple product quantity updated **70 → 140**; **5 red Universal** units; RUT missing rejected; dispatch without address rejected; successful request confirmation with exact quantities and cleared cart. Server records unpaid, no stock reduction, empty destination for no dispatch.
- Browser: hydrated native Cart; changing quantity to **140** and proceeding displayed **×140** at checkout. On adapter 1.0.2, name, giro and optional message survived checkout → cart → checkout without submitting. Native remove announced removal, disabled continuation during the operation and ultimately left **0 items**; browser returned to Home. Conditional address toggles hidden/required correctly; optional marker removed when required. No checkout JS errors observed (only jQuery Migrate informational log).
- Privacy copy corrected to quotation purpose and correct policy link. Provisional-photo notice restored. Repeated singular/plural search normalized for `cajas` only, not an unsupported semantic synonym engine.
- Only selected desktop rendering observed. **No hardware/mobile/keyboard/screen-reader approval, fresh complete axe audit or Core Web Vitals measurement.** Do not call UI validated.
- **Updated by acceptance review:** quantity transport failure and two concurrent HTTP submissions were exercised; recovery feedback and idempotency failed as detailed above. Browser double-click behavior, actual checkout-response interruption, real email delivery, restricted-sales-role acceptance and full operator workflow approval remain untested/unapproved. HPOS declaration is not a two-mode runtime certification; this review confirmed live HPOS is disabled.
- Provisional photos, unknown commercial minimums/steps/specifications, response-time promises and outstanding Q4–Q10 remain owner decisions. Migration does **not** close UX-01–UX-17 wholesale. Some native ancillary strings still say “carrito”; primary journey labels use the approved terminology.
