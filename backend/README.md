# VELTRA 2.0 API

Laravel REST API, part of a single-tenant modular monolith. Stage 1A infrastructure
health remains public. Stage 1B adds the installation organization, locations,
management history and independent supply eligibility. Stage 1C adds Laravel
Sanctum stateful SPA sessions, installation-wide user identities, historical
location memberships and a server-validated operating context.

The application service is `App\Domain\Installation\InstallationService`.
PostgreSQL enforces relational integrity, non-overlapping management periods,
history preservation and operational activation. Stage 1B management endpoints
still require the explicit local-development-only boundary, independently of any
session. This is not production authorization. `/api/v1/auth/*` uses first-party
cookie sessions and CSRF protection instead. Accounts and memberships grant no
business permissions; no RBAC or account-management API has been implemented.

Provision the initial identity with an interactive terminal:
`docker compose exec backend php artisan veltra:provision-initial-identity`.
An active installation organization must already exist. Passwords are hidden,
never command arguments. No default account, role or membership is created.
Subsequent provisioning is rejected. Membership assignment/revocation currently
exists only in the internal identity service, pending future authorization work.

See the [project README](../README.md) for Docker setup and test commands.
