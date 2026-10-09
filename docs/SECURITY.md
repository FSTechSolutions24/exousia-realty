# Security model

- Same-origin Sanctum sessions, CSRF validation, session regeneration on login, and invalidation on logout.
- API authentication rate limits for login and registration.
- Active membership is checked before a tenant context is established.
- Lead and task queries, dashboard aggregations, foreign-ID validation, duplicate detection, and audit records are company scoped.
- Agent visibility is restricted to assigned or created leads; task writes are limited by role/ownership.
- Passwords use Laravel hashing and mass assignment is allow-listed.
- Audit records include the actor and request metadata. Broad lead audit events intentionally omit free-form notes.
- `.env`, uploads, storage keys, build hot files, and dependencies remain ignored.

Before production: upgrade the framework, conduct dependency and authorization reviews, add secure private-media controllers with short-lived signed URLs, field masking/export approval, consent and retention workflows, MFA for privileged users, structured security logging, secret rotation, backup restore drills, and a legal review under applicable Egyptian data-protection obligations. This repository does not claim legal compliance.
