# VELTRA 2.0 — Repository-wide Copilot instructions

These instructions apply to all work in the VELTRA 2.0 repository. They are standing guardrails, **not authorization** to start a task or perform an otherwise prohibited action. The current owner's prompt defines the permitted stage and scope.

## 1. Communication and working style

- Communicate with the project owner **in Polish**. Write implementation plans, findings, approval requests, and final stage reports in Polish unless a task explicitly requires another language.
- Work in small, clearly identified stages. Do not start a subsequent stage automatically.
- Read the applicable approved architecture/RBAC/module specifications, relevant prior stage reports, and actual code **before** changing behavior.
- Treat the repository and current tests as the implementation baseline, but resolve conflicts against explicitly approved project decisions. Do not present a proposal or a synthetic preview as an approved production contract.
- Be precise and critical: report real limitations and blockers, not optimistic guesses. Do not claim PASS for checks that have not run.
- Avoid repeated permission interruptions: anticipate foreseeable safe reads, scoped edits, and test commands and request native approvals together where supported. **Never circumvent approvals or request blanket unsafe access.**

## 2. Source of truth and workspace

- Expected Linux workspace: `/home/veltra/projekty/veltra` (WSL Ubuntu).
- The corresponding Windows path is `\\\\wsl.localhost\\Ubuntu\\home\\veltra\\projekty\\veltra`.
- Verify the current repository root and environment; do not assume paths or commands are always available.
- The authoritative sources are approved VELTRA 2.0 documents, approved ADRs, existing contracts and code, and verified stage reports. Older `VELTRA_SOURCE_*` files describe the legacy KC Technologia system and are **reference/migration material**, not permission to reproduce legacy design decisions.
- Check `git status --short` and relevant diffs at the start. Existing uncommitted modifications may belong to earlier stages; preserve them.
- Never run `git reset --hard`, destructive `git clean`, overwrite unrelated files, or discard work merely to obtain a clean tree.

## 3. Architecture boundaries

- VELTRA 2.0 is a modular operations platform for food production, restaurants, central kitchens, inventory, raw materials, recipes, and operational workflows.
- Preserve established module boundaries, public contracts, organization/location context, database isolation, and historical integrity.
- ADR-001 establishes the direction of **separate VELTRA application/server and database installations per commercial client**. Do not silently replace this with a shared multi-client database architecture.
- Do not infer new fields, permission names, endpoint semantics, validation rules, or business processes from UX sketches. Confirm them in approved specifications and backend code.
- Keep the frontend presentation aligned with existing backend validation and authorization; server checks remain authoritative.
- Preserve history, revisions, approved snapshots, auditability, and concurrency controls. Never silently overwrite approved or newer data.
- Favor narrow changes and existing abstractions over parallel frameworks and speculative generalized infrastructure.

## 4. Security and authorization (non-negotiable)

- Reuse the established effective RBAC authorization resolvers and verified operational context. Do not create a second permission engine or infer privileges from raw assignments, navigation visibility, or a successful read operation.
- Enforce identity, organization, active location, membership, grant validity/delegation/revocation, and primary-context restrictions wherever the existing contract requires them.
- A frontend capability flag controls only UI affordances; it **never** authorizes a backend write. Protected backend operations must re-check permissions.
- For RAW_MATERIALS, the approved read contract is `GET /api/v1/security/raw-materials/capabilities`, separating `permissions`, module `readiness`/`available`, and derived `actions`; verify the actual current implementation before extending it.
- Permission/capability checks must be side-effect free: never use SKU reservation, creation, or any state-changing endpoint as a permission probe.
- Handle session failure, access denial, module unavailability, malformed responses, stale requests, identity/location changes, and ambiguous writes fail-closed.
- Never log, disclose, export, or put credentials, session cookies, tokens, personal data, or confidential business records into reports, screenshots, or prompts.

## 5. RAW_MATERIALS readiness and write safety

- **`RAW_MATERIALS = NOT_READY` until the owner explicitly authorizes a readiness change.** Do not activate the module or work around its availability middleware to enable real catalog writes.
- Use isolated synthetic frontend previews when the module is unavailable. Clearly label simulated capabilities, records, and API responses as synthetic evidence.
- Backend SKU/IDs are server-managed. Never generate or allocate SKU client-side. Treat SKU reservations as consequential, potentially non-recoverable operations and follow verified idempotency contracts.
- Preserve lifecycle states, immutable identifiers, revision semantics, audit history, and optimistic concurrency rules implemented in the backend.
- Do not perform real create/edit/approve/archive/delete operations on installation or production data unless an explicit later stage authorizes them.

## 6. Validation, tests, and visual verification

- Before implementation, identify the exact affected code and regression surface. Add focused tests for positive paths, failure paths, security, stale context, and concurrency as appropriate.
- Prefer existing test commands and fixtures. Report exact executed commands, counts, assertions, warnings, failures, and skipped checks.
- Run the relevant frontend tests, build/typecheck, lint, `git diff --check`, and backend tests when the scope involves backend logic.
- Backend feature tests must use the **current working tree** and an isolated test database/runtime, not an outdated container image or any installation/production DB.
- If tooling is missing, investigate a safe ephemeral runner; never mutate operational containers just to obtain a PASS.
- Verify visual changes using the actual frontend component and deterministic synthetic responses. Check desktop/mobile layout, loading/error/denied states, and screenshot dimensions.
- Screenshots are UI evidence, **not proof of successful live backend writes**.
- Do not refactor unrelated files merely to silence pre-existing warnings. Document them accurately.

## 7. Git, infrastructure, and scope discipline

- **No `git commit`, `git push`, branch rewrite, deployment, migration against installation resources, or module activation without explicit authorization from the owner for that action.** The owner's permission to implement a stage does not imply permission to deploy or commit.
- Do not touch production/staging infrastructure, active application containers, databases, or live user data without an explicit environment-specific authorization.
- Maintain the existing working tree; avoid sweeping formatting changes and unrelated refactors.
- Stop on a security/contract contradiction rather than inventing a workaround. Document exact evidence, recommended minimal fix, and what remains blocked.
- Clean up only temporary services, images, networks, and processes **created by your own stage**; leave pre-existing containers alone.

## 8. Reports and Google Drive

- Every substantive stage should end with a concise Markdown report: `PASS`, `PARTIAL`, `BLOCKED`, or `FAIL`; objective; code/files changed; contracts; tests and exact results; screenshots; limitations; cleanup; Git and readiness status; next-stage prerequisites.
- Preferred report destination: `RAPORTY_COPILOTA` on Google Drive: https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t
- Use only an authenticated, authorized upload path. Verify each uploaded file and its destination before providing a link.
- Do not repeatedly retry the known failing WSL UNC browser-file-picker upload or use an unapproved public staging location. If upload fails, leave the report/artifacts in the repository, provide exact local paths, and state that Drive delivery is unverified.
- Do not confuse an existing Google Drive folder with successful upload of its expected contents.

## 9. VELTRA B2B is out of implementation scope

- VELTRA B2B is a **separate future ordering application and separate concept**, not a feature to start alongside the ongoing VELTRA 2.0 build.
- Owner decision: **no B2B implementation or technical build work until the core VELTRA system is completed and a new stage is expressly approved.**
- B2B concept documents and dark-mode UI references do not authorize changes to the VELTRA repository.

## 10. Session handoff procedure

At the end of a stage, or before continuing work in a fresh Copilot chat:

1. Save the stage report on disk and list exact artifact paths.
2. State the completed stage and evidence-backed result.
3. Record the current `git status --short`, important uncommitted changes, and any temporary resources still running.
4. List unresolved blockers/risks, known warnings, and security/readiness state.
5. Give a concise handoff for the next **authorized** stage; do not begin it yourself.

In a new chat, read these instructions, the latest stage report, relevant approved specifications, and the actual working tree first. Historical chat memory is not a substitute for those sources.

**Default final status until explicitly changed by owner: `RAW_MATERIALS = NOT_READY`; no deployment, commit, or push.**

## Mandatory Git Commit Policy — VELTRA 2.0

This policy applies to all GitHub Copilot development sessions and tasks within the VELTRA 2.0 repository.

### 1. Before making changes

- Always inspect `git status` and the current branch.
- Identify existing staged and unstaged changes.
- Preserve all unrelated changes made by the user or previous sessions.
- Never assume that an uncommitted change belongs to the current task.

### 2. Mandatory commits

- Create a Git commit after every significant, logically complete and successfully validated change.
- Prefer small, atomic commits over large commits containing unrelated modifications.
- Run all relevant available tests and validation checks before committing.
- Do not automatically commit failing or unverified changes.
- If required tests cannot run, report the limitation and request approval before committing.
- Do not create commits for trivial intermediate edits unless they form a meaningful checkpoint.

### 3. Commit scope and naming

- Stage only files directly related to the completed task.
- Review the staged diff before committing.
- Never use blanket staging commands such as `git add .` or `git add -A` when unrelated changes may be present.
- Follow the Conventional Commits format.

Examples:

`feat(raw): implement material category management`

`fix(raw): correct usable quantity cost calculation`

`fix(infra): isolate Docker test environments`

`test(raw): add pricing regression tests`

`docs: update deployment instructions`

### 4. Security and data protection

Never commit:

- `.env` files containing secrets
- API keys, passwords or authentication tokens
- Private keys or certificates
- Database dumps or backups
- Runtime data or Docker volumes
- Temporary credentials or sensitive logs

Inspect changes for accidentally exposed secrets before committing.

Git commits do not replace PostgreSQL backups or infrastructure recovery procedures.

### 5. Prohibited Git operations

Without explicit owner authorization, never:

- Force-push
- Rewrite Git history
- Delete branches or tags
- Perform destructive resets
- Discard uncommitted user changes
- Amend existing commits
- Automatically push commits to remote repositories

Local commits are authorized under this policy. Remote pushes require separate authorization.

### 6. Deployment requirements

- Every deployment must reference an exact Git commit hash.
- Never deploy uncommitted or unverified changes unless explicitly authorized.
- A successful commit does not automatically authorize deployment.
- Database migrations, infrastructure cleanup and destructive operations still require their own authorization and safety checks.
- After a major module is fully tested and explicitly approved by the owner, propose a version tag.

### 7. Completion reports

After every significant task, report:

- Summary of implemented changes
- Files changed
- Tests executed and results
- Commit hash and message
- Remaining issues or limitations
- Whether any deployment was performed

If no commit was created, explain why.

### 8. Priority

This policy is mandatory for VELTRA 2.0 development.

It must not override existing security requirements, environment isolation rules, RBAC restrictions or explicit task-specific prohibitions.

If a specific task explicitly forbids commits, follow that restriction and report the pending changes instead.
