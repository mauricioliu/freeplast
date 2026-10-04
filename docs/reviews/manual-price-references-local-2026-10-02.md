# Two manual price references — local implementation, 2026-10-02

Owner approved loading two per-product reference prices, showing quantities/pallet equivalence and letting the quotation operator choose or type the offered price. No automatic mixed-pallet or quantity-tier policy is inferred. Scope: existing local reviewer only; no staging/production deployment.

## Source and cautious mapping

Private source: `~/Downloads/Precios productos (2).xlsx`, title “Precios a sept 2026”, SHA-256 `c602398b7f7694d70677869124feef1f78339a1568418c40316fa9ade8103cea`. File unchanged. Read the explicit pallet/unit rows without executing formulas; no customer or sales import.

Loaded 9 unambiguous source columns into the existing private price-list row, resolved to native product IDs by exact catalog slug:

| Workbook label | Catalog slug |
| --- | --- |
| Polleras | caja-pollera |
| Traversas tipo G1 | traversa-para-bins-tipo-g1 |
| Traversas tipo UPC | traversa-para-bins-tipo-upc |
| Universal cerrada color | caja-universal-cerrada-color |
| Cosecheras | caja-cosechera-3-4 |
| Tomateras | caja-tomatera |
| Frutilleras | caja-frutillera |
| Fruteras | caja-frutera |
| Totes | tote |

8 source columns remain unimported: Palteras (bulk amount needs confirmation), Traversas tipo G2 (Romano equivalence unconfirmed), Planas Negras, Bins, Universal cerrada, Universal reciclada, Universal color, Tacos. No fuzzy matching or automatic product creation. The two Universal labels without a ventilated/black qualifier are deliberately not guessed. Missing source values are pending, never zero. Other catalog products remain without tier references.

Private reviewed plan, unresolved values, loader and original database backup live outside the web root in `~/.local/state/freeplast-owner-review/`: `price-reference-plan.json` (0600), `load-price-references.php`, `before-price-references.sqlite` (0600). Source commercial workbook/manifest are not copied into the repository or served directory.

## Behavior

- `price-references.php` extends the same private Price List payload with optional `references` keyed by native product/variation identity: `units`, `small`, `bulk`, provenance text. It is not a second catalog or a live workbook sync.
- Legacy single-price readers/suggestions remain intact. Legacy saves preserve the reference map. Reference-only changes never prefill or alter the chosen offer amount; no quantity threshold selects a reference.
- Native variation record wins as a whole, including explicitly pending values; otherwise the parent record applies.
- Maintainer (still administrator/Woo manager only) can edit both reference prices and packaging with positive-integer validation. Any invalid reference refuses the combined save. Source provenance retained for unchanged entries and replaced with a manual-adjustment note when edited.
- Quotation operator sees two reference amounts and “Usar precio” actions. A click changes only the input, sets unsaved state and disables review until saved. The operator may type any valid custom offer price afterward. Without JS, both references remain visible and can be typed into the normal server-validated offer field.
- Packaging equivalence updates on quantity changes and reset without changing the price. No cross-product pallet aggregation; remainders remain explicit, not rounded to purchase multiples.
- Prior single-price apply functionality remains when a legacy price exists. Issued views continue to render the frozen projection/PDF, not current reference values.

## Evidence

- Before local load: consistent SQLite backup. Loader fails outside exact local environment/hostname and refuses overwriting a different maintained reference. Post-load checks confirmed the full map, unchanged legacy prices, and unchanged hashes of all draft/work/preview/issued-version options.
- `FREEPLAST_SKIP_STACK=1 npm test` passed: aggregate checker 1,243; price-list suite 112, workspace JS suite 28 (reported separately, not additive). Covers parent/variation fallback, no automatic suggestions, pending values, invalid input, atomic rejection, saved-work preservation and quantity crossings without price changes.
- Existing quotation-only real HTTP regression passed 41 checks after installing the local code. This test advances a synthetic draft revision with unchanged inputs; the import itself did not change any draft revision.
- Chrome: mobile 412×915 and desktop 1440×1000 inspected in one screenshot batch, no desktop overflow. Actual button click copied the bulk reference, marked work unsaved and blocked review; changing quantity across the threshold preserved that choice; form reset restored the original quantity and price. No browser save/approval/mail performed.
- Public Store API sample still returns the technical zero and no reference field. Prices were not written to public product metadata.
- Full disposable-stack regression, live MySQL/HPOS equivalence and human visual acceptance not claimed. Separate prior security hold remains. No commit/push/deploy.

## Owner confirmations (2026-10-02, later)

- **Palteras → Caja Paltera** confirmed, including the $600 bulk amount; its 1–4 pallets value stays pending (`S/I` in the source).
- **Traversas tipo G2 → Traversa para Bins Tipo Romano** confirmed.
- Local references now cover **11 native products**; pending: Planas Negras, Bins, Tacos, Universal cerrada, Universal reciclada, Universal color.
- Owner asked for the tiered price on the product page. In the owner panel the two references now render always visible on each product section (no disclosure toggle), still manual-apply. The **public** product page stays without prices: quote-only is the recorded product constraint, so public tiered prices would be a separate owner decision.

Offline suite re-passed (1,243 aggregate; 112 price-list, 28 workspace-JS, 43 workspace-offer reported separately). Local reload verified identical preserved-state invariants; browser check at 412px shows both tiers without overflow.

Entry: `http://mliu:8096/cotizaciones/`. Example: synthetic request 64. The saved example price intentionally remains unchanged until the operator chooses and saves.

## Data hub entry follow-up

Owner requested a dedicated route for all maintained quotation data and approved the suggested name. `http://mliu:8096/mantenedor/` now redirects the owner to a new private **“Mantenedor de datos”** hub screen (`fpw-data`) listing existing private screens only: **Precios** (list + volume references) and **Ventas** (spreadsheet import/history). New maintainers join through the `fpw_data_hub_entries` filter without new authority.

Boundary: the entry mirrors `/cotizaciones/` (exact path, GET/HEAD, no-store, fixed login return) but requires `manage_woocommerce` — the quotation-only account receives the owner-only 403, guests reach login, and the hub screen itself re-checks the capability. Alternatives (`/datos/`, `/administracion/`) were rejected as generic or confusing; the route matches the owner's existing “Mantenedor de precios” vocabulary.

Evidence: offline suite re-passed (1,244 aggregate; access 53, price list incl. hub render 115, reported separately). Local HTTP regression passed 47 checks (guest routing, safe return, POST refusal, quotation-only 403 on the hub). Browser as administrator verified `/mantenedor/` → hub with both rows at 412px without overflow. Staging remains unchanged; source uncommitted.

## Dedicated data-maintainer account follow-up

Owner requested an account exclusively for the data hub. New role **`fpw_data_manager`** (“Mantenedor de datos”) holds exactly `read` + `fpw_manage_data`; it never grants `manage_woocommerce`, quotations, products, orders, settings or users. Local synthetic user **`mantenedor`** (pass `freeplast/local-data-manager`) holds only that role.

`quotation-access.php` now owns both restricted roles and a shared boundary: `fpw_can_manage_data()` gates the hub, the price maintainer and the sales importer (render, save front door and per-request screen registration so the native page gate admits exactly one of the two caps); the data allowlist admits only the hub, those two screens and the user's own profile; its dashboard lands on the hub; quotation surfaces answer 403; authenticated REST and application passwords are denied for both restricted roles.

Evidence: offline suite re-passed (1,244 aggregate; access 56, price list 116, sales register 86, reported separately), including per-request registration caps, data-role entry and the exact route allowlist. Real HTTP: quotation-only regression 47 checks; new `data-access-http-test.py` passed 18 checks (login lands on the hub, both screens open, price reference editors render, all ten probed technical routes 403, quotation entry refuses the data role, REST denied). Browser as `mantenedor`: `/mantenedor/` → hub, `/cotizaciones/` explicitly denied. Staging unchanged; source uncommitted.
