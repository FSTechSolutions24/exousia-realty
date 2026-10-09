# Exousia Realty repository guide

## Product rules

- Treat all CRM data as tenant-owned unless a table is explicitly platform-global.
- Resolve the company through authenticated active membership. Never accept a raw company ID as authorization.
- Every tenant query, validation rule, file path, export, queued job, and audit event must include company scope.
- Agents see assigned/created leads by default; owners/admins have full company access. Extend permissions centrally through `CompanyMembership::can()`.
- Store money as integer EGP minor units (or documented exact decimals for later financial ledgers), never floating point.
- Normalize Egyptian phones with `EgyptianPhoneNormalizer`; preserve the original value.
- Preserve history for assignments, stages, availability, money, and merges. Avoid destructive overwrites.
- Demo content must be clearly fictional.

## Development conventions

- Backend: thin API controllers, validated input, service classes for domain rules, auditable writes, `/api/v1` routes.
- Frontend: Vue 3 Composition API with TypeScript, Pinia for cross-page state, accessible semantic controls, responsive LTR/RTL CSS.
- Add a tenant-isolation test for every tenant-owned module and both success and unauthorized cases for permission changes.
- Run `php artisan test`, `npm run typecheck`, and `npm run build` before declaring a slice complete.
- Update `docs/IMPLEMENTATION_PLAN.md` honestly; do not mark placeholders or adapters as implemented.
- Never commit `.env`, provider credentials, production personal data, or generated private uploads.
