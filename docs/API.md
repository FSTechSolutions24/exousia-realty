# API v1

All endpoints return JSON except private image reads, which return the authorized image. Browser authentication uses Laravel Sanctum's same-origin session cookie. Mutations require a CSRF cookie (`GET /sanctum/csrf-cookie`). Authenticated tenant endpoints resolve `X-Company-ID` only when the current user has an active membership; otherwise they fall back to the user's valid selected company.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/api/v1/auth/register` | Create user, company, owner membership, and default stages |
| POST | `/api/v1/auth/login` | Start authenticated session |
| POST | `/api/v1/auth/logout` | Invalidate session |
| GET | `/api/v1/bootstrap` | User, company, memberships, role, and stage configuration |
| POST | `/api/v1/companies/{id}/switch` | Select an authorized company |
| GET | `/api/v1/dashboard` | Real scoped metrics, pipeline counts, tasks, recent leads |
| GET | `/api/v1/reports?period=month` | Period-scoped lead source, pipeline conversion, and deal outcome/value summaries (`view_reports` / `view_own_reports`); accepts month, quarter, or year |
| GET/POST | `/api/v1/leads` | Filtered paginated list / create |
| GET/PUT | `/api/v1/leads/{id}` | Scoped detail / update |
| POST | `/api/v1/leads/{id}/activities` | Add manual note or communication log |
| GET/POST | `/api/v1/tasks` | Scoped task list / create |
| PATCH | `/api/v1/tasks/{id}/complete` | Toggle completion |
| GET/POST | `/api/v1/deals` | List visible deals / create one from a lead (`view_deals` or `view_own_deals`; create permission required) |
| GET/PUT | `/api/v1/deals/{id}` | Read a deal and activity history / update deal status and details |
| GET/POST | `/api/v1/inventory` | Filtered paginated property catalogue / create listing (`view_inventory` / `manage_inventory`) |
| GET/PUT | `/api/v1/inventory/{id}` | Read listing and history / update listing |
| GET | `/api/v1/preferred-locations?active=1` | List this workspace's active locations for single-location selection |
| POST | `/api/v1/inventory/{id}/photos` | Upload a private image (JPEG, PNG, WebP, AVIF; up to 10 MB each, 20 per listing) |
| GET/DELETE | `/api/v1/inventory/{id}/photos/{photoId}` | Authorized private image read / remove from a listing |

Validation failures use Laravel's `{ message, errors }` envelope. Pagination uses Laravel's standard `{ data, current_page, last_page, ... }` response. A normalized phone collision returns `duplicate_warning` while still creating the separate enquiry.

Inventory money is submitted and returned as decimal EGP strings. The database stores integer piastres in `price_minor_units`; status, price, detail, and photo changes are recorded in listing activities and the audit log.

Property create/update requests use one tenant-owned `preferred_location_id`; new selections must be active. The response includes that ID and the current master location name. Older location text is retained as a snapshot if its master entry was removed.

Deal requests reference a company-owned `lead_id` and optionally a company-owned `property_listing_id`. `agreed_price_egp` is submitted as a decimal EGP string and stored as integer piastres. Deal value tracks the transaction and is not a commission or payment record.

Reports support `month` (month to date), `quarter` (quarter to date), and `year` (year to date). Lead and deal metrics are limited to records created in the selected period. Agents receive reports only for leads they created or are assigned; authorized management and finance roles receive company-wide reports. Won deal value is agreed deal value, not collected cash.
