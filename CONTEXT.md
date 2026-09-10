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
The single private initial draft Freeplast creates when a Quote Request is received durably, called **Borrador de cotización** in Spanish. It snapshots the request's native record — products, options, quantities, identity, destination and Submitted Details — and names what the record does not carry yet (prices, purchase history, dispatch estimate) as pending, never as zero. It is not a Quotation, issues nothing, and is readable only by the owner.
_Avoid_: Draft order, quote, prefill, documento

**Delivery Address**:
The complete destination supplied by the customer when dispatch is requested, including street, number, commune and region. It is distinct from the company's fiscal information; supplying text does not imply the site has geocoded or verified the destination.
_Avoid_: Billing address, company address

**Dispatch Distance**:
The road distance from the Freeplast Warehouse to the Delivery Address, used by sales when evaluating dispatch.
_Avoid_: Straight-line distance, shipping price

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
