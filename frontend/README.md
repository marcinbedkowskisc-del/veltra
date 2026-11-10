# VELTRA 2.0 frontend

React + TypeScript + Vite. The application shell retains the existing session,
CSRF, location selection and `/api/health` behavior. The RAW catalog is
available at `/raw-materials` and reads the existing RAW API; backend readiness
and authorization responses remain authoritative. No fixture data is used by
the application.

The catalog uses server-side search, filters, sorting and pagination. Financial
values are rendered only when the API includes the authorized fields. The
selected-record CSV action uses the existing idempotent export workflow.

Run frontend checks from this directory:

```sh
npm test
npm run lint
npm run build
```

Component and API-contract tests use Vitest, jsdom and React Testing Library.

The Vite development proxy forwards `/api` requests to Laravel without CORS
setup.

See the [project README](../README.md) for Docker setup and test commands.
