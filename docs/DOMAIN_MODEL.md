# Domain model

## Implemented entities

- `users`: identity; optionally remembers the selected company.
- `companies`: tenant boundary, locale, currency, timezone, and brand color.
- `company_memberships`: explicit user/company relationship, status, base role, permission additions.
- `pipeline_stages`: company-specific lead stages with translated name and terminal flags.
- `leads`: enquiry and requirements, scoped to one company, with preserved and normalized phone values.
- `lead_imports`: tenant-scoped import receipts keyed by the uploaded CSV content hash so retrying the same file does not create the same batch twice.
- `lead_activities`: immutable-style chronological interactions and changes.
- `follow_up_tasks`: due work assigned to a company member and optionally attached to a lead.
- `preferred_locations`: tenant-scoped master locations used by lead forms and property listings.
- `property_listings`: tenant-owned sale/rent inventory, with one optional master-location reference (plus a retained name snapshot), availability, reference, details, and integer EGP piastre prices.
- `property_listing_activities`: chronological listing status, price, detail, and photo history.
- `property_photos`: tenant/listing-scoped metadata for images stored privately and served through an authorized API endpoint.
- `deals`: tenant-owned sales transactions linked to a lead and optionally a listing; agreed EGP amounts are integer piastres.
- `deal_commissions`: manually entered tenant-scoped commission ledger entries with explicit pending, approved, paid, or void state; amounts are never inferred from deal values.
- `deal_documents`: tenant/deal-scoped metadata for private contract and supporting files, delivered through an authorized download route.
- Lead-to-listing recommendations: computed from currently available listings inside the active company; intent, property type, bedroom minimum, and EGP budget are hard filters, with preferred locations contributing to a deterministic score.
- `deal_activities`: chronological deal status and detail changes.
- `audit_logs`: actor, tenant, event, target, value delta, IP, and user agent.

## Invariants

1. Every CRM row has a company foreign key.
2. Referenced stages, members, and leads must belong to the active company.
3. A property listing can select only one active location from its own company's location master.
4. Duplicate phone matching never crosses a company.
5. Terminal lead stages do not yet mutate deal or property state; those domains will remain separate.
6. Audit payloads avoid recording lead notes and secrets in broad create events.
7. Inventory queries and photos are scoped to the active company; photo paths are private and never exposed as public disk URLs.
8. Property prices are stored as integer EGP minor units. API/UI amounts use decimal EGP strings and convert without floating-point writes.
9. A deal's lead and optional property must belong to its company; agents can access only deals for leads they created or are assigned.
10. Deal value is an agreed transaction value, not a paid amount or commission ledger entry.
11. A commission is recorded only as an explicit ledger entry; marking it paid records an explicit payment timestamp.
12. Deal documents are stored privately and must be downloaded through a deal- and tenant-authorized route.
13. CSV lead imports validate the complete file before writing, retain same-phone enquiries as warning-only duplicates, and are idempotent per company and exact file content.

Subscriptions and external integrations remain planned rather than implemented. Deal records, commission entries, private deal documents, and deterministic property recommendations are implemented.
