# Exousia Realty implementation plan

Updated: 2026-10-17

## Current status

| Milestone | Status | Verified scope |
| --- | --- | --- |
| Phase 0 Ã¢â‚¬â€ foundation | In progress | Laravel 12/Sanctum session auth, password reset and email verification, company memberships and workspace administration with email invitations/acceptance, tenant resolver, roles, audit log, Vue 3/TS/Vite UI shell, EN/AR direction, Docker/CI/docs |
| Phase 1 â€” lead CRM | In progress | Lead CRUD API (create/read/update/list), reversible lead archive/restore with tenant scope, role permission, timeline and audit history, scoped search/filter, Egyptian phone normalization, duplicate warning, tenant-scoped task buckets for overdue/today/upcoming follow-ups with counts and visible priority, activity timeline, real dashboard metrics, period-based lead/deal reports with prior-period comparisons and scoped CSV export, demo seed data |
| Phase 2 Ã¢â‚¬â€ inventory/matching | In progress | Tenant-scoped property listings, precise EGP piastre prices, private photos, role permissions, CRUD/search/filter UI, activity/audit history, fictional demo data, and tenant/permission/media tests; deterministic tenant-scoped lead-to-listing recommendations and an audited property viewing feedback loop with buyer ratings and listing snapshots are implemented |
| Phase 3 â€” sales/commissions | In progress | Tenant-scoped deals link leads to optional listings and track negotiation status, agreed EGP value, payment method, amounts received, outstanding balance, payment reference/date/terms, activity history, and audit events; management can close a deal only after attaching a signed contract PDF and recording payment details. A printable closing statement includes the transaction and payment schedule. Commission ledger supports fixed or percentage amounts per deal/unit, employee self-visibility, management reports, and an audited pending → approved → paid workflow. Management prints employee payment orders and uploads private signed PDF copies before recording payment. Private deal documents are also implemented |
| Phase 4 â€” SaaS commercialization | In progress | Monthly/yearly EGP plan catalog, one-time 14-day free trial, company subscription history, tenant workspace access gate, team seat limits, owner/admin billing controls, payment history, and Paymob hosted checkout with verified callbacks are implemented. Platform-owner-only Paymob setup stores credentials encrypted; live gateway verification remains outstanding |
| Phase 5 â€” integrations/hardening | In progress | Paymob API and callback integration is implemented; production credentials and end-to-end provider verification are outstanding. Meta and WhatsApp integrations remain planned |
| Phase 6 â€” platform operations | In progress | Separate platform-owner console, cross-company operational and subscription reporting, company search/filters, Paymob payment ledger and encrypted gateway setup, suspension/reactivation, manual subscription grants, and platform action audit history are implemented. Browser-level review and production admin provisioning remain outstanding |

Additional workspace capabilities: a tenant-scoped real estate developer directory supports search, contact details, activation, and inventory links; company owners/admins can upload a private company logo used in the workspace sidebar and printed deal statements.

## Architecture

```mermaid
flowchart LR
  Browser[Vue 3 SPA\nEN / AR + RTL] -->|Sanctum cookie + CSRF| API[Laravel /api/v1]
  API --> Auth[Authentication]
  API --> Tenant[ResolveTenant middleware]
  Tenant --> Membership[Active membership + role]
  Membership --> CRM[Lead / Task services]
  CRM --> DB[(MySQL 8)]
  CRM --> Audit[(Audit logs)]
  API -. future jobs .-> Redis[(Redis queue/cache)]
  API -. local or private .-> Files[(S3/Wasabi adapter)]
```

The application is a modular monolith. `ResolveTenant` selects only a company for which the user has an active membership; controllers then scope reads, writes, relationship validation, metrics, and duplicate checks to that company. Cross-company platform reporting and controls use separate routes behind the `is_platform_admin` authorization middleware, with all company-changing actions recorded in the platform audit log.

## Completed decisions

- Upgrade the installed Laravel 8 skeleton to the current PHP 8.2-compatible Laravel 12 line before building further modules.
- Same-origin Sanctum session authentication with CSRF protection rather than browser-stored API tokens.
- Database IDs are never treated as authorization. Company membership plus role permissions are required.
- Egyptian mobile numbers are canonicalized to `+20â€¦`; the user-entered display value is retained.
- Duplicate numbers warn rather than block because multiple legitimate enquiries can share a contact.
- Later navigation is visibly marked as planned rather than backed by hardcoded fake data.

## Assumptions

- Initial currency is EGP and timezone is Africa/Cairo.
- A new user creates one brokerage and becomes its owner.
- Agents see leads they created or are assigned; management roles see the whole company.
- Manual calls, WhatsApp links, and notes are Phase 1; official messaging APIs require credentials and contract tests later.
- Current seed prices, people, companies, and listings are fictional development data.

## Immediate next tasks

1. Finish lead workflow improvements: bulk archive and bulk assignment are implemented for selected leads on the current page, with per-lead audit/history and tenant checks. Next add saved filters, XLSX import support, and full kanban drag/drop. CSV template, preview/import, row validation, and tenant-scoped exact-file idempotency are implemented; archive remains reversible, with permanent deletion intentionally not exposed.
2. Add granular policy classes for tenant resources. Password reset, general email verification, workspace invitations/acceptance, membership administration, and lead assignment history are implemented.
3. Add a PHP formatter and static analysis after the Laravel 12 compatibility pass.
4. Add Playwright tests when the browser runtime is available.
5. Commission/deal follow-ups: add payment-provider/bank reconciliation and optional payment receipt attachments; recorded deal payment details and signed PDFs are workspace records, not bank-verified transactions.
6. Public landing pages: the primary marketing page is at `/`; it now includes a responsive, animated 3D property matching section with illustrative fictional inventory and a buyer search. Comparison designs are available at `/home2` and `/home3`. The third design foregrounds brokerage listings, client matching, and team workflow through fictional illustrated property inventory. All link to registration/sign-in. The authenticated dashboard is available at `/dashboard`.
7. SaaS billing: configure Paymob secret/public/HMAC keys and payment integration IDs in the server environment, then complete sandbox callbacks and a small live payment/refund verification before accepting production subscriptions. Confirm local plan prices and team seat limits against the launch offer.
8. Platform administration: verify the responsive owner console in a browser, then create its dedicated account with `php artisan platform-admin:create "Platform Owner" owner@example.com`. Keep this privileged account separate from ordinary company-admin membership and provision it through secure operations access.



## Release gates

- All PHP tests, TypeScript checks, and frontend production build pass.
- A clean database can migrate and seed.
- Tenant A cannot read/write Tenant B data through IDs, filters, metrics, files, exports, or jobs.
- Production uses HTTPS, secure cookies, queue workers, backups, monitored cron, non-default credentials, and debug disabled.


## Verification log â€” 2026-10-09

- Developer partner directory and company branding (2026-10-17): searchable, paginated tenant-owned developers with contact management and activation; inventory can link only to an active developer in its workspace and can be searched by developer. Owners/admins upload and remove private logos; active workspace members can view them in the sidebar and printed deal documents. Developer and branding test coverage is included in the current suite.
- Paymob platform-owner setup (2026-10-17): gateway setup is available only in the super-admin console. Secrets are encrypted at rest and returned only to the protected, private no-store platform-owner setup endpoint to prefill its form; company billing exposes checkout only. Both Unified Checkout and the classic iframe flow are supported, including the photo-rent `.env` key names. Company checkout uses the saved account phone when available and no longer asks for a separate number. Platform access, encrypted storage, and both checkout paths are covered by tests. Current suite: 78 tests and 548 assertions; typecheck and production build pass. The existing CKEditor chunk-size warning remains.

- Reversible lead archive/restore: 22 PHP tests and 63 assertions passed, including role denial and cross-company restore isolation; TypeScript checks passed.
- Workspace team page and membership administration: owners/admins can add existing accounts, update roles, and deactivate/reactivate members; other roles can view the roster. Last active owner protection, tenant isolation, and audit history were tested.
- Lead assignment: owners/admins and managers can reassign from the visible Owner column; unauthorized roles cannot change assignments through the API. Assignment history is recorded in the lead timeline and audit log.
- Team invitations: the form collects a name, existing accounts are added directly, and new accounts receive a seven-day email invitation with a single-use acceptance link. Acceptance creates and verifies the account, activates the tenant membership, and sets the workspace. Tests cover invitation delivery/acceptance and audit records. `php artisan test`: 29 tests and 103 assertions pass; `npm.cmd run typecheck` and `nnpm.cmd run build` pass.
- Account recovery and verification: password reset links are single-use, reset and verification request responses do not disclose account existence, verification links are signed and expire after one hour, and unverified users cannot reach tenant APIs. Registration sends a verification email. `php artisan test`: 33 tests and 127 assertions pass; frontend typecheck and production build pass.
- Inventory vertical slice: tenant-scoped listing CRUD and filters, EGP piastre storage, status/price/photo history, audit events, private photo uploads and authorized reads, fictional seed listings, and a CKEditor 5 property description editor using the free GPL distribution. Listing locations are selected one at a time from the workspace location master and validated for tenant ownership and active status. The GPL distribution requires GPL compliance; use a commercial CKEditor license if this project cannot comply. `php artisan migrate:fresh --seed` on in-memory SQLite passes. `php artisan test`: 39 tests and 170 assertions pass; `npm.cmd run typecheck` and `nnpm.cmd run build` pass.
- Deals vertical slice: tenant-scoped sales pipeline linking a lead to an optional listing, with negotiation/reservation/contract/close status, expected close date, integer EGP agreed values, activity and audit history, role-based visibility, and fictional seed deals. Commission accounting and contract storage remain planned. `php artisan test`: 43 tests and 198 assertions pass; `npm.cmd run typecheck`, `nnpm.cmd run build`, and in-memory migrate/seed pass. Deal API tests cover role scope, tenant isolation, money precision, and history.
- Reports vertical slice: selectable month/quarter/year periods, tenant/member-scoped lead stage and source conversion, deal stage and agreed-value summaries, role permissions, and responsive UI. Lead conversion and deal wins use current pipeline/deal statuses; agreed deal amounts are not represented as collected cash. `php artisan test`: 46 tests and 218 assertions pass; TypeScript check, production build, and in-memory migration/seed pass. Report tests cover tenant isolation and permissions.

- `php artisan test`: 13 tests and 20 assertions passed, including cross-company lead/task isolation and agent visibility.
- `php artisan migrate:fresh --seed` with in-memory SQLite: passed.
- `npm run build`: Vue type-check and Vite 8 production build passed.
- `npm audit`: zero vulnerabilities.
- `composer audit`: zero security advisories after upgrading Laravel 8 to Laravel 12.69.
- Docker Compose syntax could not be executed locally because the Docker CLI is unavailable; files were reviewed but runtime verification remains outstanding.




- Lead/property matching, prior-period report comparisons and CSV export, and deal commissions/private documents: tenant-scoped API and UI slices added. php artisan test: 53 tests and 288 assertions pass; PHP syntax checks and 
npm.cmd run build pass. CSV, permission boundaries, matching filters, commission state transitions, private media access, and tenant isolation are covered.
- Lead acquisition source: confirmed the existing field is saved on leads and read by Reports. The lead form now explains it, and the list/detail screens show it; campaign attribution remains manual until integrations are added.
- Lead CSV import: downloadable template, preview with row validation and duplicate warnings, tenant-scoped all-or-nothing creation, and exact-file idempotency added. Import tests cover permissions, invalid batches, duplicate contacts, and company isolation. `php artisan test`: 53 tests and 288 assertions pass; `npm.cmd run build` passes.
- Sales performance reporting: won deals snapshot the assigned lead owner and close time; existing won deals are backfilled where a tenant membership can be resolved. Reports now show workspace employee leaders by units and recorded agreed value, export the same rows to CSV, and restrict agent reports to their own sales. Values are explicitly labeled as agreed deal values, not collected cash. Tests cover sorting, missing prices, close attribution, and tenant/member scope.
- Sales performance verification: `php artisan test` passes with 54 tests and 303 assertions; `npm.cmd run typecheck` and `npm.cmd run build` pass. The build retains the existing CKEditor chunk size warning.
- Follow-up task triage: Tasks now have tenant-scoped Overdue, Due today, Upcoming, and All open views with matching counts; agents only see counts and tasks assigned to themselves. Priority is visible in the task list. `php artisan test`: 68 tests and 459 assertions pass; `npm.cmd run typecheck` and the Vite production build pass. The build retains the existing CKEditor chunk size warning.
- Property viewing feedback loop: agents can schedule an available matched property against a lead, then record completed, cancelled, or no-show outcomes, a 1–5 buyer-interest rating, and feedback notes. Listing title, reference, location, and EGP price are snapshotted so history stays useful after inventory edits. Scheduling and outcomes appear in the lead timeline and audit log; tenant and permission boundaries are feature-tested. `php artisan test`: 71 tests and 479 assertions pass; `npm.cmd run typecheck` and the Vite production build pass. The CKEditor chunk-size warning remains.
- Bulk lead actions: current-page multi-select supports tenant-scoped assignment and reversible archive with per-lead history/audit events. Tests cover authorization and foreign-company IDs.
- Bulk lead action verification: `php artisan test` passes with 56 tests and 318 assertions; `npm.cmd run typecheck` and `npm.cmd run build` pass. The CKEditor bundle size warning remains.
- Commission calculation and reporting: commission entries now snapshot the linked property unit and the agreed-value base, calculate percentage amounts in integer EGP piastres or accept fixed amounts, and retain rate/base details for audit. Employees see only their own entries and report totals; management roles see workspace totals and employee breakdowns. Percentage calculation requires a linked unit, contracted or won status, and agreed sale value.
- Commission slice verification: `php artisan test` passes with 56 tests and 337 assertions; `npm.cmd run typecheck`, `npm.cmd run build`, and PHP syntax checks pass. Reports include itemized commissions by unit. The build retains the CKEditor chunk size warning.
- Commission payment management: a tenant-scoped Commissions page supports employee self-service, finance/admin approval, printable employee acknowledgment/payment orders, private signed-PDF uploads, versioned document history, and audited payment status. The API prevents marking a commission paid until an approved commission has a current signed PDF; older uploaded images remain historical and cannot satisfy the payment proof requirement. Managers can review workspace entries, finance/admins manage them, and agents see only their own commissions. Tenant isolation, forbidden uploads, signed-document access, replacement history, and the required paid transition are covered.
- Deal closing controls: the workspace stores received amount, derived balance, payment method/reference/date, and payment terms in integer EGP minor units. Only deal managers can move a deal to won, and only after a private signed contract PDF is attached and a valid agreed price/payment record is present. The printable closing statement presents the buyer, unit, price, receipts, outstanding balance, terms, and signature blocks. Deal API tests cover the contract prerequisite, payment details, derived balance, and agent denial.
- Commission/deal PDF verification: `php artisan test`: 56 tests and 383 assertions pass; `npm.cmd run typecheck` and `npm.cmd run build` pass. Signed commission documents and executed deal contracts accept PDFs; newly created commissions are covered through the standalone commission register endpoint; the build retains the CKEditor chunk size warning.




