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
business permissions by themselves. Stage 1D adds six scoped roles, hierarchical
account/role administration, historical grants, approval workflows and minimal
append-only security events under the independent `/api/v1/security` boundary.

Provision the initial identity with an interactive terminal:
`docker compose exec backend php artisan veltra:provision-initial-identity`.
An active installation organization must already exist. Passwords are hidden,
never command arguments. No default account, role or membership is created.
Subsequent identity provisioning is rejected. Next run the interactive
`docker compose exec backend php artisan veltra:bootstrap-authorization` command:
it establishes an initial context and pending administrator role, then sends an
activation email. The recipient must verify email and choose a new password
before logging in. Before activation, bootstrap safely supports rate-limited
resends; afterwards its one-way latch closes it permanently.

Local SMTP uses Mailpit at http://localhost:8025; no token is written to logs or
queued. Production requires real SMTP/TLS and HTTPS configuration, not this
development Compose. Reset initiation is restricted to authorized supervisors;
the recipient alone sets their password. Versioned identities invalidate old
sessions after password/email changes or deactivation.

`BusinessAuthorization` is deny-by-default and checks verified identity,
membership context, scoped roles and current kitchen-management relationships,
never supply relationships. `ModuleAvailability` and `ReportCatalog` are closed
integration contracts; no modules, business reports or exports are implemented.
PostgreSQL serialization/deferred constraints protect usable last-administrator
access and immutable security history. The legacy development API remains local,
explicitly enabled and token-gated; sessions do not replace its controls.

See the [project README](../README.md) for Docker setup and test commands.
