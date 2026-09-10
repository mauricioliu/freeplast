# Freeplast catalog and quotation requests

Freeplast presents a wholesale product catalog and receives requests from prospective customers. The first release stops at request intake; preparing and issuing priced commercial documents remains a separate future capability.

## Language

**Catalog**:
The reviewed set of Freeplast products currently offered for quotation requests. Its customer-facing Spanish name is **Catálogo**.
_Avoid_: Store inventory, shop

**Catalog Source**:
The authoritative, editable representation of the Catalog used in daily operation. The existing public website and imported JSON are bootstrap provenance and historical reference, not a second editable authority after migration.
_Avoid_: Scrape, live-site mirror

**Product Category**:
A customer-facing Catalog classification taken from Freeplast's 2026 catalog: `Agrícola` or `Otros`.
_Avoid_: Tag, product family

**Active Product**:
A Product currently offered through the public Catalog and eligible for a Quote Request.
_Avoid_: In-stock product

**Archived Product**:
A Product intentionally withdrawn from new Quote Requests while retained for historical references.
_Avoid_: Deleted product, out-of-stock product

**Featured Product**:
An Active Product explicitly selected for presentation on Home; it remains part of the same Catalog as every other Product.
_Avoid_: Separate collection, promotion

**Quote Basket**:
A prospective customer's temporary selection of Products and requested quantities before submitting a Quote Request. Its customer-facing Spanish name is **Productos a Cotizar**; it is neither a purchase nor a stock reservation.
_Avoid_: Cart, order, cotización, Mi selección

**Quote Request**:
A customer's submitted request asking Freeplast to prepare pricing for selected Products and quantities, called **Solicitud de cotización** in Spanish. It contains no customer-facing prices and is neither a Quotation nor an Order; all public quotation entry points produce this same record.
_Avoid_: Quote, inquiry, order, checkout

**Request Reference**:
The permanent customer-facing identifier for a Quote Request, formatted `FP-YYYY-NNNNNN`.
_Avoid_: Database ID, order number, quotation number

**Request Status**:
The commercial progress of a Quote Request: `new`, `contacted`, `quoted`, `won`, `lost`, or `cancelled`. `quoted` means Freeplast sent a Quotation, even when that document was prepared outside the first-release system.
_Avoid_: Order status, fulfillment status

**Quotation**:
A priced commercial document prepared by Freeplast in response to a Quote Request, called **Cotización** in Spanish. A request may eventually lead to one or more separately versioned Quotations, but creating and delivering them is outside the first release.
_Avoid_: Quote Request, order, invoice

**Quotation Draft**:
The single private initial draft Freeplast creates when a Quote Request is received durably, called **Borrador de cotización** in Spanish. It snapshots the request's native record — products, options, quantities, identity, destination and Submitted Details — and names what the record does not carry yet (prices, purchase history, dispatch estimate) as pending, never as zero. Later cuts enrich it into an internal, editable proposal for the owner's review, prefilled with available customer information, requested quantities and Price List prices; those prices remain unchanged by later Price List updates unless explicitly refreshed. It is not a customer-issued Quotation, issues nothing, requires the owner's final decision, and is readable only by the owner.
_Avoid_: Draft order, quote, Quote Request, documento, issued Quotation, confirmed sale

**Draft Working State**:
The owner's saved manual completion of a Quotation Draft — working quantities, net CLP prices, working destination and dispatch amount — kept in its own durable storage, separate from the immutable receipt snapshot, with the revision it supersedes so stale or concurrent saves are detected and refused instead of silently overwriting newer work. Its amounts are the owner's manual decisions scoped to that one draft; saving never approves, sends or notifies anything.
_Avoid_: Rewritten receipt, draft order, quotation, autosave

**Quotation Version**:
The fixed customer-facing prices and conditions of a Quotation approved and issued by the owner. Later changes require a new version rather than alteration of what was already sent.
_Avoid_: Live Price List, editable sent quote

**Quotation Validity**:
The period set by the owner during which the prices offered in a Quotation Version remain valid for its stated destination, quantities and conditions.
_Avoid_: Delivery deadline, permanent price guarantee

**Customer**:
The purchasing company identified primarily by its normalized company RUT when associating Purchase History with a Quote Request. Similar names alone do not establish that two records represent the same Customer.
_Avoid_: Contact email, similar company name

**Price List**:
The maintained source of current net product prices in Chilean pesos used to prefill a Quotation Draft, referred to by Freeplast as the **Mantenedor de precios**. Its suggested prices are distinct from historical sale prices and do not replace the owner's final pricing decision.
_Avoid_: Product Catalog, final Quotation, last sale price

**Price Adjustment**:
An explicit change the owner makes to a suggested product price while reviewing a Quotation Draft. Volume discounts are initially decided by the owner, not applied automatically.
_Avoid_: Automatic volume discount, Price List update

**Purchase History**:
The record of a customer's actual past purchases from the Sales Register, used by the owner when reviewing a Quotation Draft. Past Quote Requests and Quotations alone do not establish that a purchase occurred; absence of a matched purchase record does not prove the customer is new.
_Avoid_: Request history, quotation history

**Sales Register**:
The spreadsheet in which Freeplast records its completed sales and which supplies the Purchase History available for quotation review.
_Avoid_: Quote Requests, Price List

**Import Receipt**:
The bounded, consultable record of one applied Sales Register import — acting owner, time, source file name, batch token and per-outcome counts — from which the freshness and provenance of the available Purchase History is read beside every Quotation Draft. It is not permission to retain the uploaded workbook, and it is not a commercial document.
_Avoid_: Upload log, file backup, invoice

**Delivery Address**:
The complete destination supplied by the customer when dispatch is requested, including street, number, commune and region. It is distinct from the company's fiscal information; supplying text does not imply the site has geocoded or verified the destination.
_Avoid_: Billing address, company address

**Address Provenance**:
The recorded origin of the Delivery Address: confirmed with the official address assistant (keeping its place identification and match scope — exact place or broad road/commune-level match) or typed manually. A selection identifies a place; it neither certifies deliverability nor precise access, and the browser-reported identification is a claim recorded for private review, never verified evidence. Editing the address text or requesting no dispatch invalidates the association.
_Avoid_: Geocoded address, verified address, coordinates

**Dispatch Distance**:
The road distance from the Freeplast Warehouse to the Delivery Address, used by sales to estimate a dispatch price without waiting for the Carrier's charge. It is a pricing reference, not a guarantee of the journey the Carrier will travel.
_Avoid_: Straight-line distance, shipping price

**Estimated Dispatch Price**:
The amount Freeplast estimates for dispatch using Dispatch Distance to respond to the customer before the Carrier provides its charge. Freeplast accepts the risk of absorbing a difference between its estimate and the Carrier's charge.
_Avoid_: Carrier Charge, guaranteed transport cost

**Dispatch Estimate Breakdown**:
The internal inputs and calculation explaining the Estimated Dispatch Price, available to the owner when reviewing the proposed amount. The Customer receives a separate dispatch amount, not this breakdown, and neither establishes the Carrier Charge.
_Avoid_: Carrier invoice, unexplained shipping total

**Quoted Dispatch Price**:
The dispatch amount approved by the owner and offered to the Customer for the destination and quantities in a Quotation Version during its Quotation Validity. Freeplast absorbs differences from the Carrier Charge; changes to destination or quantities require review and a new Quotation Version.
_Avoid_: Carrier Charge, provisional customer surcharge

**Carrier Charge**:
The amount the external Carrier charges Freeplast for dispatch, based on distance. It is distinct from the Estimated Dispatch Price Freeplast offers the customer.
_Avoid_: Customer shipping price, Estimated Dispatch Price

**Carrier**:
The external transport provider that performs Freeplast dispatches using small freight vehicles.
_Avoid_: Freeplast fleet

**Warehouse**:
The Freeplast dispatch origin at Camino El Arrayán 52, San Francisco de Mostazal.
_Avoid_: Office, billing address

**Submitted Details**:
The customer and delivery information exactly as received with a Quote Request.
_Avoid_: Current contact details

**Current Contact Details**:
The corrected customer and delivery information sales currently uses when handling a Quote Request; Submitted Details remain available separately.
_Avoid_: Original submission

**Sales Note**:
A timestamped internal observation appended by sales while handling a Quote Request. It is never shown to the customer and does not alter Submitted Details.
_Avoid_: Customer message, status

**Staging Site**:
The isolated, non-indexed WordPress site used to build and review the new Freeplast experience before a separately approved production release.
_Avoid_: New production site, replacement site

## Implementation decision

[ADR-0001](docs/adr/0001-woocommerce-quote-only.md) records the approved mapping to WooCommerce. Its Products are the editable Catalog Source, its Cart holds Productos a Cotizar, and its Orders administration holds Quote Requests, not commercial purchases. The initial Woo release uses request intake and native order notes; it does not reproduce the former six-state commercial workflow. Historical statuses and submitted records remain preserved.

[ADR-0004](docs/adr/0004-quote-draft-durable-relationship.md) records the Quotation Draft's durable relationship (issue #50): one unique options row born at the checkout receipt, read by the owner only, announced through the existing owner email.

[ADR-0005](docs/adr/0005-draft-working-state-optimistic-concurrency.md) records the Draft Working State decision (issue #51): the owner's manual completion of a Quotation Draft lives in its own durable row with optimistic revision control, separate from the immutable receipt snapshot.

[ADR-0006](docs/adr/0006-sales-register-import-contract.md) records the Sales Register import contract (issue #54): an explicit source-id sale identity (never the RUT alone or date+amount), preview/confirm/cancel with exactly-once receipts, matching by normalized company RUT, and a purchase history computed at read time beside each draft — the definitive column set and update-vs-append semantics remain blocked on the real sample.

[ADR-0007](docs/adr/0007-dispatch-address-assistance.md) records the dispatch-address assistance decision (issue #59): the official Places widget beside the native textarea behind an absent-by-default configuration seam, provenance kept as a reviewable claim, and manual entry always valid without Google.

[ADR-0008](docs/adr/0008-dispatch-distance-consultation.md) records the dispatch-distance consultation decision (issue #60): one bounded Routes `computeRoutes` call per explicit owner action from the private draft screen behind an absent-by-default server-credential seam, the permitted destination identification served only while it matches the working text, honest states for every failure or ambiguity, and nothing stored — the result is the current consultation only, and the manual dispatch price path always survives.
