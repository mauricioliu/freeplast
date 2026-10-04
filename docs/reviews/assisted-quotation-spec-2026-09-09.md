## Problem Statement

Freeplast receives **Solicitudes de cotización (Quote Requests)** for wholesale products, but the owner must assemble pricing and customer context manually before responding. Its external **Carrier** uses small freight vehicles and charges by distance, yet takes too long to provide a **Carrier Charge**. Waiting for that amount delays the whole commercial response.

The owner wants to prepare an accurate-enough offer sooner: use current product prices, requested quantities, actual customer purchases and road distance to prefill as much as possible, then exercise the final commercial judgment. Freeplast explicitly accepts absorbing a difference between the dispatch price it offers and the eventual Carrier Charge, for the destination, quantities and validity of the issued offer.

The current WordPress/WooCommerce service receives unpriced Quote Requests. Its technical Woo orders are not evidence of purchases, its product zero prices are technical sentinels, and its restricted Ventas role cannot issue priced documents. Adding address suggestions alone does not solve the business problem.

> **Scope and authorization:** this issue synthesizes the owner's approved planning decisions. Publication and the requested `ready-for-agent` label do not authorize implementation, deployment, real customer emails, live imports or production changes. Source-file mappings and the dispatch formula remain explicit external prerequisites below; no agent may invent commercial data to clear them. Testing seams are technical proposals for confirmation, not a claim that the owner has already approved or executed those tests.

## Solution

Extend Freeplast with an owner-controlled quotation-preparation workflow:

**Quote Request received → owner email with private link → prefilled Quotation Draft → owner review and Price Adjustments → preview → “Aprobar y enviar” → versioned Quotation emailed to the buyer as a PDF.**

The draft presents the requested products and options, quantities, current net CLP prices, customer identity and available Purchase History. A private WordPress **Mantenedor de precios (Price List)** supports direct editing and Excel updates. Actual purchases come from Freeplast's separate **Sales Register** spreadsheet; imports initially run manually in WordPress with preview, validation and confirmation.

For dispatch, the system uses the Delivery Address and Warehouse to obtain a fresh **Dispatch Distance** and propose an **Estimated Dispatch Price** through an explicit, editable rule. The owner sees the amount and **Dispatch Estimate Breakdown**, can inspect or recalculate distance and can override the amount. The buyer receives a separate **Quoted Dispatch Price**, not the internal distance/rate calculation.

The owner decides volume discounts manually. Missing information or a provider failure does not lose the Quote Request or prevent a useful draft and notification. Required gaps prevent approval until the owner resolves them, including manual pricing where needed.

A sent **Quotation Version** preserves the customer-facing prices and conditions approved at that moment. Its **Quotation Validity** defaults to seven days and is editable before approval. Changing the Price List, importing later sales or obtaining a different route result must not rewrite that sent offer.

## User Stories

### Request intake and notification

1. As a prospective buyer, I want to submit the existing Quote Request with products, options and quantities, so that asking for an offer does not require making a purchase.
2. As a prospective buyer, I want the public request journey to remain unpriced, so that preliminary internal estimates are not mistaken for an approved offer.
3. As a buyer requesting dispatch, I want assistance finding my Delivery Address, so that Freeplast can identify the intended destination more reliably.
4. As a buyer with a rural or unrecognized address, I want to provide the destination without depending on a successful Google suggestion, so that my request is not lost.
5. As a buyer not requesting dispatch, I want an earlier typed destination excluded from my submitted delivery details, so that Freeplast does not quote an unwanted service.
6. As a buyer, I want retries and lost responses to recover the same submitted attempt, so that one intention does not create duplicate Quote Requests.
7. As the owner, I want an email after a Quote Request has been received durably, so that I can start preparing the offer promptly.
8. As the owner, I want that email to link directly to the relevant private Quotation Draft, so that I do not have to search for the request.
9. As the owner, I want a usable draft even when a price, customer match or distance is unavailable, so that external problems do not halt the whole workflow.
10. As the owner, I want to open and review the private workflow from my phone, so that I can respond without returning to a desktop.
11. As a buyer, I want the intake acknowledgement distinguished from a final Quotation, so that receipt of my request does not imply that pricing has been approved.

### Customer identity and Purchase History

12. As the owner, I want Purchase History sourced from completed sales in the Sales Register, so that I do not confuse previous requests with actual purchases.
13. As the owner, I want customer matching primarily by normalized company RUT, so that ordinary formatting differences do not hide the same customer's history.
14. As the owner, I want similar names alone not to merge customers, so that one company's purchases are not attributed to another.
15. As the owner, I want an unmatched customer labeled “Sin historial asociado”, so that incomplete imports are not presented as proof of a new customer.
16. As the owner, I want matching ambiguity or missing identifiers visible, so that I can resolve uncertainty instead of relying on an invented association.
17. As the owner, I want the historical information actually supported by the imported sales data available beside the draft, so that I can judge pricing using real past transactions.
18. As the owner, I want historical sale prices distinguished from current Price List prices, so that an old negotiated amount is not silently reused as today's price.
19. As the owner, I want to know which sales import supplies the available history and when it was loaded, so that I can judge its freshness.
20. As a buyer, I want my purchase information restricted to authorized Freeplast users, so that a notification link or another customer cannot expose it.

### Price List and imports

21. As the owner, I want a private WordPress Price List, so that current product prices have one maintained authority.
22. As the owner, I want to edit prices directly in that screen, so that a small correction does not require preparing a spreadsheet.
23. As the owner, I want to upload Excel to update the Price List, so that bulk price changes do not require editing every product manually.
24. As the owner, I want sales imports and price imports presented as separate operations, so that historical transactions cannot accidentally replace current prices.
25. As the owner, I want an import preview before any changes take effect, so that I can inspect the proposed updates.
26. As the owner, I want invalid rows and unresolved product associations identified, so that a malformed file does not silently corrupt prices or history.
27. As the owner, I want to cancel a preview without changing data, so that reviewing the wrong file is harmless.
28. As the owner, I want an explicit confirmation to apply a validated import, so that uploading alone does not change the business data.
29. As the owner, I want repeated import attempts not to duplicate completed sales or reapply an uncertain batch blindly, so that Purchase History remains trustworthy.
30. As the owner, I want the import result to identify what was applied and what remains unresolved, so that I can act on failures rather than assume success.
31. As the owner, I want product and variation identities preserved during price updates, so that a color or product does not receive another item's price.
32. As the owner, I want unmapped products handled explicitly instead of matched by fuzzy names, so that imports do not create plausible but wrong prices.
33. As the owner, I want existing historical data and unrelated catalog content preserved during imports, so that updating prices or sales does not mutate products, photos or Quote Requests unexpectedly.

### Draft preparation and manual judgment

34. As the owner, I want the draft prefilled with the request's actual products, chosen options and quantities, so that I do not retype the buyer's selection.
35. As the owner, I want available current net CLP prices prefilled from the Price List, so that preparing an ordinary offer is fast.
36. As the owner, I want volume discounts to remain my manual decision, so that the system does not invent commercial rules.
37. As the owner, I want to make a Price Adjustment for the offer without changing the Price List, so that a negotiated exception does not become the default for everyone.
38. As the owner, I want a clear distinction between a suggested amount and my adjustment, so that I can review what I changed before approval.
39. As the owner, I want existing drafts to keep their prices after Price List updates, so that a later import does not silently change work in progress.
40. As the owner, I want an explicit refresh from current prices that protects my manual adjustments, so that I can adopt new defaults deliberately.
41. As the owner, I want missing values shown as pending rather than zero, so that incomplete pricing cannot masquerade as a complete offer.
42. As the owner, I want to complete required prices manually, so that a missing maintained value does not force me to abandon the draft.
43. As the owner, I want subtotal, dispatch, IVA and final total clearly separated, so that I can verify the complete amount before committing it.
44. As the owner, I want my saved draft edits to survive revisiting the private link, so that reviewing from different devices does not lose my work.
45. As the owner, I want conflicting or stale edits detected rather than silently overwriting newer work, so that approval reflects the version I actually reviewed.

### Dispatch estimate

46. As the owner, I want road distance from the Warehouse to the intended Delivery Address, so that I can estimate dispatch without waiting for the Carrier Charge.
47. As the owner, I want distance described as a driving reference for the small freight operation rather than certified vehicle access, so that I understand its limitations.
48. As the owner, I want to recalculate using the stored destination, so that permanent storage of Google distance and route responses is unnecessary.
49. As the owner, I want ambiguous or incomplete destinations identified before relying on a route, so that a commune-level result is not presented as an exact delivery point.
50. As the owner, I want an editable, explicit dispatch pricing rule, so that the estimate reflects Freeplast's chosen approximation rather than an opaque calculation.
51. As the owner, I want the estimated dispatch amount and its calculation components displayed together, so that I can assess and adjust the proposal.
52. As the owner, I want the dispatch approximation checked against actual Carrier Charges before its values are adopted, so that the proposed amount is as close as practical to the real cost.
53. As the owner, I want to override the proposed dispatch amount before approval, so that unusual circumstances remain my commercial decision.
54. As the owner, I want a route or provider failure to leave an explicit pending condition and a manual path, so that I can still prepare the offer.
55. As the owner, I want a changed destination to invalidate the previous route association and dispatch suggestion, so that I do not approve a price for the wrong journey.
56. As a buyer, I want one separate dispatch amount in the final Quotation, so that I understand what I am being charged without receiving Freeplast's internal estimation formula.
57. As a buyer, I want Freeplast to respect its approved dispatch amount during the offer's validity for the stated destination and quantities, so that a later higher Carrier Charge is not automatically passed on to me.

### Approval, delivery and versions

58. As the owner, I want a preview of the buyer-facing Quotation, so that I can inspect exactly what I am about to send.
59. As the owner, I want approval blocked while required amounts or conditions are incomplete, so that an unfinished draft is never issued accidentally.
60. As the owner, I want the final action to be explicitly “Aprobar y enviar”, so that preparation alone does not create a commercial offer.
61. As the owner, I want Quotation Validity to default to seven days and remain editable before approval, so that ordinary offers are quick while exceptions are possible.
62. As a buyer, I want the approved Quotation by email with a PDF attachment, so that I can read and retain the offered prices and conditions.
63. As a buyer, I want the PDF to show product options, quantities, amounts, taxes, dispatch and validity consistently with the approved preview, so that the commercial document is unambiguous.
64. As the owner, I want the sent Quotation Version preserved independently of later data changes, so that I can recover what was offered.
65. As the owner, I want changed quantities, destination or commercial terms to require a reviewed new version, so that the old document is not silently rewritten.
66. As the owner, I want double clicks and retried approval requests not to issue a second version or blindly resend the same message, so that uncertain network outcomes do not duplicate offers.
67. As the owner, I want document-generation and mail failures distinguished from successful handoff, so that I do not assume the buyer received something that was not sent.
68. As the owner, I want recovery to operate on the already approved version, so that a send retry does not recalculate a different offer.
69. As a buyer, I want Purchase History, Sales Notes and the Dispatch Estimate Breakdown excluded from my email and PDF, so that internal review information is not accidentally disclosed.
70. As the owner, I want original Submitted Details preserved alongside current working information, so that preparing the offer does not erase what the buyer actually requested.
71. As an existing restricted Ventas user, I want my established read/private-note functions preserved without new commercial powers, so that this owner workflow does not weaken the current permission contract.
72. As the owner, I want no payment, stock reservation, automatic invoice or recorded purchase caused merely by issuing a Quotation, so that an offer remains distinct from a completed sale.

## Implementation Decisions

### 1. Approved domain extension and existing contracts

- This is a new commercial-document capability following request intake, not a rename of a Quote Request into a Quotation. Preserve the domain distinctions among request, draft, approved version, actual purchase, estimated dispatch price and Carrier Charge.
- Explicitly extends ADR-0001's first-release exclusions for preparation/issuance of priced commercial documents and internal distance consultation. It does **not** replace Woo as Catalog Source or owner of Products, variations, Quote Basket, sessions and Quote Request persistence.
- The public request journey remains unpriced and request-only. Scope replaces the older blanket prohibition on priced documents only for the private owner workflow and its deliberately approved buyer-facing document.
- Preserve original Submitted Details, historical Request References, existing request identity/recovery behavior and unrelated product/media content. Do not reconstruct the retired bespoke request system or infer purchases from native Woo order rows.
- No vendor forks or unapproved paid licenses. Evaluate the pinned quotation extension's supported capabilities before choosing internal implementation details. Do not globally re-enable all its commercial email/order actions as a shortcut.
- This specification does not select a new table, post type, framework, role name or PDF library. Those are implementation choices constrained by native ownership, protected data, dependency reproducibility and the behavioral contracts here; document a consequential trade-off rather than silently changing ADR-0001.

### 2. Module responsibilities and interfaces — proposed technical shape

- Prefer one deep **Quotation workflow Module** behind a small owner-facing **Interface** for preparing/viewing a draft, explicitly refreshing its suggestions, saving adjustments, previewing, approving and recovering delivery of a fixed version.
- Request intake, private screens and notification links call that same Interface. Do not scatter arithmetic, approval validation or version creation across independent screen and email implementations.
- The Module owns commercial draft/version invariants and composes price lookup, purchase-history lookup, dispatch estimation, document rendering and mail delivery. Internal organization is not an instruction to create a class or abstraction for every noun.
- Reuse WordPress authentication, capability checks, request hooks, persistence facilities and mail transport. Extend the existing adapter where it connects the Module to Woo; do not make the theme or browser authoritative for prices, totals, identity or permissions.
- Distinct variable external behavior—Google and mail transport—uses narrow **Adapters** at existing transport hooks where practical. Tests should vary those Adapters, not replace the entire Woo stack with a fake.
- Persist a durable relationship between a Quote Request, its working draft and approved versions without overwriting the source request. A recoverable request-processing retry must not create duplicate initial drafts or owner notification intents.
- Document/PDF libraries and Excel readers must be maintained, licensed compatibly and pinned through the project's packaging discipline. No executing spreadsheet macros, imported formulas or remote references as application logic.

### 3. Owner access and notification audiences

- Notification channel is email. The owner receives a private link to the relevant draft after durable intake; no priced offer goes to the buyer at this point.
- The draft link requires WordPress authentication and explicit authorization. Possessing a URL, knowing a request reference or presenting a valid nonce alone does not grant access to commercial data.
- The existing restricted Ventas role remains read/private-note-only. The new operations are for the authorized owner; do not grant them via broad capabilities already held by restricted staff. Any later delegation is a separate decision.
- All mutation interfaces—price editing, imports, draft edits, route recalculation, approval and recovery—enforce capabilities, CSRF protection and request/version ownership on the server.
- Use explicit owner and buyer projections. Current request metadata is shared by administrative and customer email paths; adding private history or dispatch calculations to the shared projection is not acceptable.
- Keep the existing unpriced receipt acknowledgment. Extend or route the owner notification deliberately rather than layering an extra duplicate message onto each existing receipt event.
- Private data, uploaded workbooks and quote PDFs must not become public media URLs or cacheable public-page content. Contain the same data in error logs and test artifacts.

### 4. Price List and manual adjustments

- The private WordPress Price List is the authority for **current net product prices in CLP**. An Excel file is an update input, not a second live runtime authority.
- Support direct editing and a distinct bulk price-import workflow. Preserve product and chosen-variation identities; existing catalog SKUs are candidate stable associations, not proof that the unprovided Excel uses those codes.
- Do not overwrite public technical zero-price sentinels indiscriminately or expose maintained commercial prices through storefront markup, Store API responses, fragments, receipts or request confirmations.
- Prefill the available prices into each newly prepared draft. Price List changes do not retroactively update saved draft prices; offer an explicit refresh and do not silently erase Price Adjustments.
- Volume discounts and customer-specific exceptions are manual owner decisions initially. No automatic quantity tiers, customer scoring, inferred loyalty discounts or reuse of the last historical sale price as today's default.
- A Price Adjustment affects the draft, not the general Price List. Preserve enough distinction between the suggested and chosen amounts for meaningful review.
- Missing prices are absent/pending values, not numeric zero. A deliberately entered amount is distinct from a missing one; do not invent a zero-price commercial policy.
- Display product subtotal, dispatch, IVA and total separately. Use deterministic monetary arithmetic and a single calculation path shared by draft, preview and PDF; never binary floating-point approximations in one layer and unrelated totals in another.
- The active request-only Woo installation currently disables native tax calculation and uses CLP with zero display decimals. Do not globally turn on public checkout taxes to implement private quotations. Confirm precise taxable bases/rate/rounding for the commercial document before operational acceptance; net CLP plus visible IVA was agreed, a numerical fiscal algorithm was not.

### 5. Sales Register, identity and Excel workflow

- Actual Purchase History comes from the owner's Sales Register, initially a manually uploaded spreadsheet. It is not generated from Quote Requests, sent Quotations, Woo technical order totals or the commercial workflow state.
- Associate Customer primarily by normalized company RUT. Normalize formatting consistently for lookup; do not silently introduce a new mandatory public-input rejection policy merely to improve historical matching.
- Missing, invalid or ambiguous identifiers produce an explicit unresolved association. Similar names and contact emails alone do not justify an automatic merge. An empty match displays “Sin historial asociado”, not a definitive “Cliente nuevo”.
- Show only historical fields genuinely supported by the source after inspecting it. Do not promise imported item-level prices, units, revenue metrics or dispatch costs if the workbook lacks them.
- Price imports and sales imports have separate purposes, previews and application steps. Upload/read/preview do not mutate the live Price List or Purchase History. Applying requires explicit confirmation of the reviewed batch.
- Validate file type and bounded file/resource sizes, row structure, required identifiers, amount interpretation and product associations. Report row-specific errors and distinguish unresolved records from legitimate empty values.
- A batch cannot silently apply a different workbook or stale proposed change set after preview. Cancellation or rejected validation must not change live data. Applying and retrying must have a traceable result, not an ambiguous partially reported success.
- Repeated sales uploads must not duplicate purchases. The transaction/line identity and update-vs-append semantics require examination of the real source; do not invent a collision-prone key from customer/date/amount or treat the RUT alone as a sale identifier.
- Repeated price imports with the same values must not manufacture new commercial changes, and neither kind of import may delete unrelated products, historical requests or previous commercial versions.
- Retain a bounded import receipt identifying actor, time, source/batch and outcome so history freshness and recovery are observable. This is not authorization to retain raw customer workbooks forever.
- Automatic polling, scheduled synchronization and external spreadsheet authorization are deferred until the source location and operating arrangement are known. Initial periodic updates are manual, not a hidden automatic synchronization promise.

### 6. Delivery Address and Google integration

- Add assistance only to the dispatch-address part of the existing classic request form. Preserve native Woo serialization, submitted-attempt identity, conditional address requirements and the existing visual journey outside that scoped enhancement.
- The technical recommendation is the official Places autocomplete widget, using supported customization and a native/manual fallback. The exact channel/version must be verified; do not substitute legacy widget examples blindly or treat it as an enhancer attached to the existing textarea.
- A suggestion selection identifies a place, not guaranteed deliverability or precise access. Distinguish the source of the destination from its precision and the availability of a usable route. A commune, road or ambiguous match is not automatically a complete delivery point.
- Preserve the customer's confirmed address and appropriate Place ID/provenance. Place IDs are supported route endpoints and can be retained; obtaining and storing coordinates is not a prerequisite. Google recommends Place IDs over arbitrary coordinates for route access behavior.
- Preserve original submitted destination information. When the owner corrects the working destination, invalidate the old place association and dispatch suggestion without rewriting Submitted Details. Editing or clearing a previously selected address must not submit its stale Place ID as if it still matched.
- “Sin despacho” excludes the delivery destination from the submitted record and does not invoke dispatch pricing. “No dispatch requested” is distinct from “dispatch requested but not yet priced”.
- A provider failure or absence of an exact result must not reject an otherwise valid Quote Request. Preserve manual destination entry and show the owner what needs clarification.
- Do not treat hidden browser-submitted IDs, formatted addresses, distances or prices as verified server evidence. Resolve the destination through a trusted provider path when required for a calculation; reject unsupported payload shapes and display untrusted content safely.
- The documented Warehouse address is the intended single origin. Verify the actual outbound access point before treating its provider association as operationally correct.
- Routes API can accept a Place ID or an address directly; a separate Geocoding API and Address Validation API are not mandatory dependencies. Do not provision them without a demonstrated need.
- Use an appropriate driving route as a pricing reference for the small-freight operation, not a promise of a truck-safe itinerary or delivery ETA. Current documented Google large-vehicle coverage does not include Chile.
- One-origin/one-destination `computeRoutes` is the technical recommendation for an integrated distance consultation. No distance matrix, fleet optimizer, live tracking or mandatory embedded map is required.
- Road-distance semantics, handling of return travel/peajes and exact service coverage remain coupled to the pending dispatch-rule calibration; a Chile suggestion filter is not proof that Freeplast serves every destination in Chile.

### 7. Dispatch price, explanation and retention

- The owner needs a monetary suggestion, not only a link to Maps. Obtain distance for owner review/recalculation and evaluate an explicit, editable dispatch-pricing rule.
- Rule design and coefficients must be checked against actual historic Carrier Charges. No approved per-km amount, fixed fee, minimum, automatic doubling, toll surcharge or learned model exists yet. An unconfigured rule must show pending pricing and allow manual completion, not produce an invented estimate.
- Explain the current calculation to the owner: relevant inputs, rule and resulting amount, plus any owner override. The suggestion and final chosen amount must remain distinguishable.
- The buyer-facing document includes a separate Quoted Dispatch Price, not the Dispatch Estimate Breakdown, Purchase History, Carrier Charge or internal Sales Notes.
- Freeplast respects its approved Quoted Dispatch Price during Quotation Validity for the stated destination, quantities and conditions, absorbing a higher Carrier Charge. Changes to destination or quantities require review and a new Quotation Version, not a silent surcharge on the old document.
- Do not persist Google kilometers, durations, coordinates or raw route/Places responses as an indefinite order history. Store the permitted destination identification and recalculate when needed; do not put route results into durable emails or buyer PDFs.
- A saved commercial amount and customer-facing Quotation Version are distinct from a stored provider response. Preserve approved prices and conditions without treating versioning as permission to archive Google route data. Any retention of derived information beyond this conservative design requires an applicable policy basis.
- The historical internal Google breakdown need not be reconstructed from a stored raw response; a fresh calculation may differ. Clearly distinguish a current suggestion from an already approved amount, and never rewrite that amount automatically.
- Follow Google attribution and terms/privacy obligations. Send only the necessary destination information, not RUT, email, purchase history or requested products.
- Separate browser and server credentials and apply suitable API/application restrictions. Browser keys are visible by design; server credentials remain private. Configure usage quotas and budget alerts; low expected request volume is not a guarantee of zero charges or a spending cap.

### 8. Preparation, approval, document generation and delivery

- Associate an initial working draft with each durably received request and prefill what is available. Repeated processing of the same saved request must converge on the same draft/notification intent rather than make another offer.
- Retain the draft and notify the owner even if a price lookup, sales association, Maps calculation or optional enrichment is unavailable. Mark required gaps and offer manual resolution; do not couple receipt success to external service availability.
- Draft editing and preview do not issue a Quotation. Approval is an explicit authorized action over the actual reviewed draft revision; stale edits or a changed draft must not cause approval of unreviewed content.
- Calculate and validate on the server before approval. Required prices, requested dispatch amount and necessary commercial conditions must be complete. A missing match in Purchase History is advisory, not a reason to block an otherwise complete offer.
- Quotation Validity defaults to seven days and is editable before approval. Freeze the effective validity dates/conditions in the approved version; later changes of defaults must not affect it.
- The preview and PDF use the same approved monetary values and buyer projection. Include selected products/options, quantities, net line amounts, subtotal, dispatch, IVA, total, destination where applicable and validity. Keep the related Request Reference visible while distinguishing the commercial version from the original request.
- Exact numbering and document presentation can follow the existing Freeplast identity and an explicitly documented version identifier; no final PDF design or additional commercial/legal clauses have been approved. Do not invent bank details, delivery commitments, legal terms or logo assets.
- Create a fixed approved version and render its PDF only through approval. Subsequent delivery recovery uses that same approved content; it must not reread current Price List values or recalculate a changed flete.
- Keep preparation, approved-document readiness and mail handoff outcomes distinguishable. Rendering failure must not send a partial document, and mail failure must not be displayed as successful buyer receipt.
- Deduplicate double approval activation and known retries at the durable operation level. Mail transport acceptance is not proof of delivery, and network uncertainty is not an exactly-once guarantee: make unknown outcomes explicit rather than blindly resending or claiming delivery.
- An intentional changed offer produces a new reviewed version. Existing approved versions/PDFs remain retrievable to authorized users and do not become editable projections of current catalog data.
- Issuing a Quotation does not reserve stock, charge a card, create an invoice, create a confirmed purchase or import a fictitious sale into Purchase History.

### 9. Data evolution and release safety

- Price List, Purchase History, import receipts, Quotation Drafts, approved versions and delivery outcomes are new commercial data. Choose storage and migration paths only after inspecting the active stack and source workbook contracts; preserve existing identities and protected records.
- Update obsolete “no commercial documents in this release” operational descriptions explicitly when implementing this new stage. Do not weaken public request-only regressions or the restricted Ventas policy under cover of updating documentation.
- ADR-0002's release ceremony and ADR-0003's protected-record/expected-delta discipline remain in force. This is not a surface-only field edit. Any eventual data changes need bounded migration, backup, restore rehearsal, appropriate trial and authorized release treatment.
- Staging first, human visual review, and separate production authorization remain required. Nothing in this issue authorizes changing the live production site or reusing real historical requests as test fixtures.

## Testing Decisions

### Primary proposed seam: the real WordPress/Woo request-to-quotation journey

Prefer **one high-level acceptance seam**: act as guest buyer and authenticated owner through the delivered WordPress/Woo interfaces in the existing disposable installation, then observe private screens, authoritative persisted records, generated PDF content and contained mail attempts. Exercise the Quotation workflow Module through the same Interface used by production actions.

This is a **proposal for the owner's narrow technical confirmation**, not a reopened business interview and not an already approved test result. Do not claim that source-string checks, a standalone mock or an offline arithmetic unit test alone proves the workflow works.

A good test asserts externally meaningful behavior: a single durable request/draft for one attempt; correct product/variation/quantity; explicit missing values; safe import application; protected history; manual adjustments; exact approved totals/validity; an immutable document; and appropriately contained notifications. Avoid assertions coupled to private helper names, database layout, incidental wrappers or the particular PDF library.

### Existing test precedent and minimal additions

- The active Woo check runner already combines PHP adapter checks, native DOM/jQuery behavior and a disposable WordPress + SQLite + pinned Woo/Quotes stack. Extend that stack rather than build a parallel fake business application.
- Existing native HTTP checkout-race scenarios cover concurrent submissions, attempt identity, lost-response recovery, identical-content new requests and read-only native record checks. Extend observations to the associated draft/owner notification without replacing those guarantees.
- The disposable stack already intercepts mail through the WordPress mail hook. Extend its contained observation to synthetic approved-version attachments and delivery failure states; never send actual customer email during regression tests. Its current subject-only attempt log is not sufficient by itself to verify PDF contents, recipients or real delivery.
- Existing permission regressions use valid sessions and valid actor nonces, independent native-record digests and isolated positive controls. Reuse this methodology for import, draft, approval, PDF access and route operations; a nonce failure alone is not a permission test.
- Existing checkout DOM/native-handler tests are appropriate for conditional dispatch, value retention, selection invalidation and `updated_checkout` behavior. Supplement them with actual widget/browser observation at the permitted stage; a mocked widget cannot prove Google integration or mobile usability.
- Google transport uses deterministic synthetic success, ambiguous-result, timeout, quota, invalid-response and no-route fixtures. This is a narrow external Adapter substitution, not a replacement for native request/draft persistence.
- Test workbook imports using synthetic files through preview/confirm interfaces, not just a private parser. Once real column mappings are known, add sanitized representative fixtures without committing commercial/customer source data.
- Add focused calculation or parsing tests only where they provide deterministic coverage impractical at the high seam. They supplement—not replace—the full approval/preview/PDF agreement test. There is no prototype state machine or schema snippet to treat as an implementation contract.

### Required behavioral matrix

1. **Normal request to offer:** seed known current prices and synthetic actual sales; submit a real Quote Request; observe one owner notification intent with private draft link; open as owner; inspect lines, matched history and suggestions; adjust a product price and dispatch amount; preview; approve; verify one fixed version and contained buyer email with matching PDF.
2. **Unpriced public regression:** maintain the public basket/details/confirmation/receipt contract and absence of internal commercial price leakage, including variation fragments and native endpoints. No payment, stock change or automatic invoice occurs.
3. **Request idempotency:** concurrent same-attempt submissions and lost-response recovery create neither extra requests nor initial drafts/owner notifications. A genuinely new identical request remains a separate request and draft. Recovery does not consume a newer unrelated Quote Basket.
4. **Customer identity:** formatted-equivalent RUTs match; different RUTs with similar names do not; missing/invalid/ambiguous identifiers stay unresolved; no sales match displays “Sin historial asociado”. An earlier Quote Request or Quotation alone does not appear as a completed purchase.
5. **History separation:** importing new historical transactions changes the supported history projection but not current Price List values, draft Price Adjustments, original Submitted Details or existing approved versions.
6. **Price import:** upload/preview/cancel changes nothing; confirmed validated associations update only intended prices; bad values, unknown products, conflicting rows, stale preview and repeat confirmation cannot silently corrupt data. Cover distinct variations and missing price versus genuine entered numeric values.
7. **Sales import:** the approved source identity/update contract prevents duplicates across repeat uploads/retries; partial failures and ambiguous matches have explicit outcomes. Unrelated sales, catalog entities, requests and sent versions remain unchanged. Real-format acceptance waits for source samples.
8. **Pricing persistence:** draft prefill uses current net CLP values; manual discounts change only the draft; subsequent Price List updates do not alter it; explicit refresh does not silently erase overrides. Reload preserves saved adjustments.
9. **Monetary consistency:** under the confirmed fiscal/rounding configuration, line totals, subtotal, dispatch, IVA and final total agree between private screen, preview and approved PDF. Include large quantities, adjusted prices and rounding edges. Missing required values block approval; no absent value becomes zero.
10. **Dispatch branches:** no-dispatch request omits destination and provider calls; valid selected destination can yield a suggestion; manual rural address, broad geographic match, stale/cleared selection, provider error and no route produce truthful review/pending states without lost intake.
11. **Dispatch pricing:** configured test rules expose their actual inputs/result; no configuration produces pending rather than fabricated money; owner overrides work; destination changes invalidate stale suggestions. Regression coefficients are synthetic test inputs, not production tariffs.
12. **Provider retention/privacy:** only permitted destination identity is durable; route distances, times and raw payloads are absent from long-lived request metadata, mail bodies, PDFs, logs and backups created from persisted data. Recalculation changes the current suggestion, not the amount of an approved offer. Attribution/privacy requirements are exercised in the relevant rendered surfaces.
13. **Owner versus buyer projection:** buyer email/PDF contains approved dispatch amount but no history, internal Notes, carrier cost or calculation breakdown. Unauthorized users cannot obtain private content via direct links, endpoints, caches or document URLs.
14. **Owner authorization:** restricted Ventas and guest actors with otherwise valid requests cannot edit prices, apply imports, mutate drafts, issue/recover delivery or access newly protected data. Preserve existing restricted read/private-note behavior. Positive controls prove that tests detect a deliberately removed guard in an isolated fixture.
15. **Approval concurrency:** duplicate activation and stale revisions do not create extra approved versions or approve unreviewed data. Required-field failures preserve the draft. Preview/save alone create no approved buyer document or outbound final offer.
16. **Version/validity:** default seven-day validity and owner changes freeze in the approved version; later price/rule/default changes do not rewrite it. Revised destination/quantities/terms require another reviewed version. Prior PDF content remains recoverable.
17. **Delivery recovery:** simulate PDF rendering failure, mail rejection and ambiguous transport outcome; show accurate status, preserve the approved version and prevent blind duplicate attempts. A retry never adopts newly changed catalog prices. Record mail attempts/handoff evidence without asserting buyer receipt.
18. **Non-destructive evolution:** perform any approved migration against disposable representative data and compare protected request/catalog/history records. Recovery follows the authorized release contract; do not replace the baseline after an unexpected mutation.

### UI, performance and test safety

- Design the new owner workflow mobile-first around 412 CSS px and adapt for desktop. Exercise keyboard operation, labels, error focus, long product names, readable price tables, pending states and preview/approval separation.
- Defer Google loading when dispatch assistance is not needed, bound external calls and handle unavailability without stalling request receipt. Bound workbook processing/resources; do not rely on unbounded synchronous parsing of arbitrary uploads.
- Automated accessibility checks, responsive browser observations, PDF extraction and screenshots are evidence classes, not human visual acceptance. Final phone/desktop review belongs to the owner. No visual prototype for the new private screens/PDF has been approved.
- Tests use fresh synthetic run-owned fixtures, private source samples only where authorized, independent mail containment and mocked paid-provider traffic. No real customers, production imports, real mail or spend-generating provider tests by default.
- Publishing this issue does not start a dev server, run a device, execute a shared-site mutation or require running the full stack suite. Future execution must respect the repo's server/device authorization rules and approved release ceremony.

## Out of Scope

- Implementation, test execution against shared environments, staging release or production deployment as part of this spec-publication task.
- Public price lists, public pre-approval estimates, automatic buyer-facing shipping calculators during request intake, registration requirements, payments, stock reservation or reduction, automatic invoices and converting an issued Quotation into a completed purchase.
- Automatic volume discounts, algorithmic customer scoring, price optimization, inferred historical-price reuse or automatic approval/sending without owner action.
- Automatic spreadsheet synchronization, Google Drive/ERP integrations or a scheduling mechanism before the external source is known. The initial imports are manually invoked in WordPress.
- Inventing workbook columns, product correspondences, transaction identities, fiscal rules, transport tariffs, return/toll assumptions or deployment credentials to make an implementation appear complete.
- Fleet routing, truck-specific access certification, live tracking, promised travel times, multiple-warehouse optimization or the Carrier's own dispatch management system.
- Indefinite persistence of Google kilometer/time/coordinate data, raw provider payloads or automatically emailing the internal dispatch breakdown to the buyer.
- A public customer portal, online acceptance/signature, payment collection, automated reminders, CRM replacement or tracking the entire sales lifecycle beyond the scoped document workflow.
- Broadening the existing restricted Ventas role to manage pricing, imports or approval; introducing paid licenses without approval; replacing native catalog/basket/request persistence or editing vendor code.
- Redesigning the approved public A · Directa journey, unrelated corporate pages, catalog photos, product facts or stock policies.

## Further Notes

### Approved decisions versus unresolved inputs

The owner's successive approvals establish the business workflow, WordPress Price List with Excel updates, manual volume discounts, separate actual-sales import, normalized-RUT association, frozen draft prices with explicit refresh, internal flete breakdown, binding approved dispatch amount under stated conditions, email/PDF issuance after preview and approval, manual preview/confirm imports, incomplete-draft recovery, and default seven-day configurable validity.

The following remain **explicit readiness gates for the affected behavior**, not permission to guess:

| Input or decision | Needed to finish | Safe behavior until resolved |
| --- | --- | --- |
| Representative Sales Register workbook | Columns, genuine transaction/line identity, date/amount semantics, duplicate/update rules and supported history detail | Synthetic contract tests and unmapped/pending preview; no real sales import or fabricated purchase history |
| Existing price workbook, if any | Product/variation keys, units, column mapping and data normalization | Native private manual pricing and synthetic import tests; no inferred fuzzy matches |
| Historical fletes with destination and Carrier Charge | Calibrated editable rule, coefficients, return/peaje treatment and useful approximation quality | Dispatch price remains pending or explicitly manually entered; no invented automatic tariff |
| Confirmed fiscal calculation policy | Applicable IVA rate/base, precision and rounding for product and dispatch amounts | Do not declare totals fiscally correct or operationally accepted from a guessed algorithm |
| Google project/credentials and verified Warehouse access point | Restricted authorized requests and meaningful route origin | Manual destination/pricing flow and deterministic provider fixtures; no claim of live Maps validation |
| Service coverage and route-distance interpretation | Whether geographic exclusions apply and the journey basis relevant to the pricing rule | Do not silently reject regions or double road distance based on assumptions |
| Narrow confirmation of proposed testing seam | Owner agreement with the real-Woo end-to-end test strategy | Record it as proposed; no claim of prior test-strategy approval |

The issue receives `ready-for-agent` because that label was explicitly requested for spec publication. It is not evidence that these external inputs have arrived, that all business coefficients are known or that implementation/release has been authorized. Defined contracts can be planned and tested with synthetic data once implementation is separately authorized; real-data calibration/import acceptance cannot bypass the gates.

### Architectural precedence and related work

- **ADR-0001** remains authoritative for native Woo catalog/request ownership and the distinction between a Quote Request and a purchase. This scope introduces the separate priced-document capability intentionally deferred by its first release; it does not retroactively redefine historical request records.
- **ADR-0002** and **ADR-0003** retain their release and protected-data contracts. A future implementation must document any consequential architecture/storage decision and reconcile operational descriptions explicitly rather than silently remove safety constraints.
- **#40** governs the approved A · Directa public journey. This spec changes only scoped address assistance and the new private/commercial-document stage; it does not authorize replacing its public UI or weakening its unpriced-intake guarantees.
- **#11** is historical pre-Woo address/distance work. Its closed status is not evidence of active Google assistance in the current adapter, and its older storage assumptions do not override the retention decisions here.
- **#37** and the existing Ventas restriction regressions remain constraints. No implementation agent may enable approval by removing those guards wholesale.

### Sources and test precedent

Repository locations are provided here as discovery aids, not binding implementation interfaces:

- Glossary: `CONTEXT.md`; ADRs: `docs/adr/`.
- Research and full conversation decision record: `docs/reviews/dispatch-google-maps-2026-09-09.md`. Later approval sections supersede the initial research frontier. These documents contain local uncommitted changes at publication time; the complete normative requirements are included in this issue so a remote reader need not infer missing decisions from unavailable working-tree changes.
- Active adapter/request mail presentation: `wordpress/wp-content/plugins/freeplast-woo/`; current schema and public technical price/tax defaults are also visible in the Woo migration script.
- Existing test entry point: `npm test`, through `wordpress/scripts/check-woo.mjs`.
- Primary existing stack seam: `wordpress/scripts/woo-stack-harness.mjs` and `wordpress/scripts/woo-checkout-race.py`, with independent mail containment and read-only WP-CLI record observations.
- Authorization precedent: `wordpress/scripts/woo-ventas-guard.py` and `wordpress/scripts/woo-ventas-state.php` plus their self-tests. Native checkout/UI precedent: `wordpress/scripts/checkout-native-test.mjs` and `wordpress/scripts/checkout-form-js-test.mjs`.
- No test suite, server, device, provider credential or real email was exercised to publish this spec. Reading the harness is not a passing test result.

Google primary sources consulted on 2026-09-09:

- [Places widget](https://developers.google.com/maps/documentation/javascript/place-autocomplete-new) and [widget reference](https://developers.google.com/maps/documentation/javascript/reference/places-widget).
- [Route endpoint locations and Place IDs](https://developers.google.com/maps/documentation/routes/specify_location).
- [Large-vehicle routing limitations](https://developers.google.com/maps/documentation/routes/lvr).
- [Places policies](https://developers.google.com/maps/documentation/places/web-service/policies), [Routes policies](https://developers.google.com/maps/documentation/routes/policies), [service-specific terms](https://cloud.google.com/maps-platform/terms/maps-service-terms) and [general terms](https://cloud.google.com/maps-platform/terms).
- [Pricing](https://developers.google.com/maps/billing-and-pricing/pricing), [cost controls](https://developers.google.com/maps/billing-and-pricing/manage-costs) and [API security](https://developers.google.com/maps/api-security-best-practices).

Verify applicable provider terms again before implementation; low volume does not exempt the integration from restrictions or justify a promise of free usage. Keep actual commercial workbooks, customer data and credentials outside this public issue/repository; use sanitized representative examples for development.
