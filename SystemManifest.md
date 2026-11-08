# ColorFix — System Manifest

**UPDATED:** 2026-11-07 5:37 PM PST  
**STATUS:** Foundational Architecture Contract — Version 1  
**AUTHORITY:** Owner-approved architectural decisions  
**SCOPE:** Entire ColorFix application

## 1. Purpose and governing principle

This manifest is the architectural contract for ColorFix. It defines the intended system design, not merely the arrangement of existing code. Every coding agent must read it **before** inspecting, creating, changing, or retiring application code.

**One responsibility. One owning department. One authoritative implementation.**

Existing code that conflicts with this contract is transitional; it is not permission to introduce further violations. Preserve active behavior while correcting those violations through approved, tested changes.

## 2. Departmental organization

Backend departmental umbrellas live directly under `app/` and use **UPPERCASE** directory names. A department owns its domain logic, rules, and data operations. Department implementations can use `Endpoints/`, `Managers/`, `Services/`, `Repos/`, and other internally appropriate structures. Use only the layers needed; do not generate empty or redundant wrappers.

Current umbrellas identified in the repository:

- `ANA`
- `COLORS`
- `DOCUMENTS`
- `ERR`
- `GALLERY`
- `PALETTES`
- `PHOTOS`
- `PLAYLISTS`
- `POD`
- `PROJECTS`
- `PUB`
- `REX`
- `SAS`
- `SYSTEM`

`ARTWORK` is a **planned** umbrella; do not treat it as implemented until verified in the repository.

Each umbrella must have an `app/DEPARTMENT/DeptManifest.md` documenting its actual responsibilities, dependencies, and public interfaces. Do not infer department responsibilities solely from directory names. Lowercase or mixed-case directories under `app/` are not departmental umbrellas; they may contain shared infrastructure or transitional code. Do not add new departmental business logic there.

`app/SYSTEM/` is a department, distinct from this repository-wide `SystemManifest.md`.

## 3. Department ownership and boundaries

Every capability must have one authoritative departmental owner. The owner implements domain rules, validation, state changes, and lifecycle decisions. Other departments reuse the owner's published capabilities; they do not implement competing versions.

Established examples:

- **PHOTOS:** Photo Library records, image identity and lifecycle, image metadata including thumbnail crop, replacement, merging, dependency checks, and safe disposal.
- **PALETTES:** Saved palettes, palette members, and palette-domain rules.
- **PROJECTS:** Projects and project-specific image relationships.
- **GALLERY:** Gallery eligibility, result composition, filtering, and choice of presentation item type.
- **PUB:** Publishing, including active Pinterest/YouTube media production and its render recipes. “CHEF” is a nickname for part of PUB, **not** a separate department.
- **ARTWORK (planned):** Artwork-specific metadata and art studies; artwork image storage/cropping remains PHOTOS-owned.

These examples are boundaries, **not** invented service contracts. The relevant `DeptManifest.md` and real implementation determine exact supported capabilities.

A new department or change in domain ownership requires an explicit architectural decision. Do not create parallel stores or services merely because another feature needs the same information.

## 4. API architecture: dumb doorbells

Public files under `api/` are **thin entry points**. They perform only minimal bootstrap, authentication/authorization integration, routing, and delegation to the owning department's Endpoint. They must not independently contain domain SQL, business rules, domain validation, file lifecycle decisions, or multi-step workflows.

Departmental Endpoints handle HTTP request/response details and delegate domain work to departmental managers, services, and repositories. Public API URLs and request/response contracts remain stable unless a migration is explicitly authorized.

**An API is a doorbell, not a department.**

## 5. Interdepartmental communication

Departments are allowed and expected to cooperate through **published, documented interfaces**.

- Prefer direct internal PHP method calls over making HTTP calls to sibling APIs in the same application.
- A department must not modify another department's owned records directly or bypass its validation, side effects, or lifecycle policy.
- Read-only cross-domain joins are acceptable for efficient searches, reporting, and result composition when they do not duplicate ownership or business rules.
- Avoid circular dependencies and broad generic abstractions with no concrete need.
- If a needed capability is missing, extend its owning department and publish the interface; do not build an unauthorized local substitute.

For cross-department transactions, the coordinator owns the transaction. Participating operations reuse the supplied database connection/transaction context and must not commit or roll back transactions they do not own. Preserve atomicity and failure consistency.

## 6. Frontend architecture

React code may live in the established frontend directory structure rather than under backend umbrellas. Reuse existing components, request contracts, and composition patterns. UI presentation belongs to the appropriate frontend feature; backend domain rules must not be separately reimplemented in React.

Example: GALLERY chooses `picture-swatch` versus `art-swatch`; PHOTOS owns the image and thumbnail crop; ARTWORK supplies artwork-specific metadata when an Art Study needs it. Do not introduce a separate Gallery search pipeline to support a new presentation type when the existing result pipeline suffices.

## 7. Data ownership

Every persistent data model and authoritative write operation has an identified department owner.

- **Reads:** Cross-domain SQL joins are allowed when appropriate and read-only.
- **Writes:** Use the owning department's supported operation, not ad hoc SQL in a different department.
- **Schema:** New tables/columns must have a justified purpose, defined owner, and reviewed effects on callers and constraints.
- **Deletion:** Never assume a record or asset has no other consumers. Check references and preserve shared files.

Photo Library owns `photo_library.crop_x`, `crop_y`, and `crop_size`. `photos_artwork` is for artwork-specific metadata, not the Gallery crop. Existing schema, migrations, and code must be checked before assuming any field is deployed.

## 8. Department manifests

Each `DeptManifest.md` must be a concise, accurate **contract for coding agents**, containing:

1. **Purpose and ownership:** domain responsibilities, owned records, and invariants.
2. **Explicit non-ownership:** what must be delegated elsewhere.
3. **Published capabilities:** implemented entry points, class/method names, input/output contracts, errors, and relevant side effects.
4. **Dependencies:** other departments, shared infrastructure, and third-party systems.
5. **State:** implemented versus planned functionality; deprecated features and known transitional violations.
6. **Verification:** relevant tests and significant compatibility requirements.

Do not invent interfaces to fill out a manifest. Manifests must reflect verified code. Planned interfaces must be clearly labeled **PLANNED**, never presented as callable. Update affected manifests whenever interfaces, ownership, invariants, or dependencies change.

## 9. Avoid duplicate systems and unnecessary complexity

Before implementing any feature, search for the current authoritative capability and its callers. Prefer the smallest coherent extension of the owning department.

Do not introduce competing repositories, duplicate persistence logic, parallel search engines, unnecessary APIs, speculative general-purpose frameworks, or permanent compatibility wrappers without demonstrated need and approval.

## 10. Legacy systems and retirement

Distinguish **active capability**, **transitional implementation**, and **retired functionality**. Existing noncompliant code is not a template for new development.

Intended retired systems include legacy masks, Applied Palettes, legacy base-photo/photo-variant rendering, and Palette Sets. Do not recreate them. Retirement requires dependency inventory, focused code removal, regression verification, and separate approval before destructive production database or physical-file deletion.

**Preserve PUB's current Pinterest/YouTube render recipes and media-creation workflow; they are not the retired rendering system.**

Do not classify code as unused solely because no frontend import was found: check external clients, CLI jobs, maintenance operations, and scheduled tasks where relevant.

## 11. Mandatory coding-agent workflow

### Before implementation

1. Read **root `SystemManifest.md` first**.
2. Identify the owning department and affected collaborators.
3. Read their `DeptManifest.md` files, if present; when absent, inspect code and report the missing contract without inventing one.
4. Inspect actual code, interfaces, database dependencies, tests, and callers.
5. Reuse existing capabilities and identify conflicts with this contract.

### During implementation

1. Keep changes scoped to the requested job.
2. Honor ownership and published interfaces; keep API files as dumb doorbells.
3. Protect existing routes, behavior, user data, and dependent departments.
4. Add or update targeted regression tests.
5. Do not silently change architecture to accommodate a shortcut. Escalate decisions that alter ownership or foundational rules.

### After implementation

1. Run relevant tests and state what was and was not verified.
2. Update affected `DeptManifest.md` files when their contracts or boundaries changed.
3. Propose any needed `SystemManifest.md` amendment for **owner approval**; do not independently relax its rules.
4. Report changed files, interfaces, tests, remaining exceptions, and deployment implications.

## 12. Manifest timestamps and change control

Every manifest must begin with an **UPDATED** timestamp showing the **actual modification time** in the `America/Los_Angeles` time zone, labeled PST or PDT as appropriate. Change that timestamp only when the document itself changes. Update it whenever an approved edit is made. Never fabricate a time or silently replace approved architectural rules.

The owner approves system-level architectural changes. Coding agents may identify inconsistencies and recommend amendments, but **must not unilaterally redefine department boundaries, waive constraints, or rewrite this constitution to suit their implementation**.

For existing exceptions, record the affected code, reason for the exception, migration/dependency plan, and status. An exception does not become a precedent.

## 13. Safe change policy

Maintain working functionality while improving architecture. Prefer incremental, testable changes. Check cross-department consumers before changing published contracts. Do not perform destructive production SQL, asset deletion, or filesystem cleanup without explicit approval. Preserve publicly used URLs where possible. Distinguish local implementation, deployment, and production verification in reports.

---

**SystemManifest.md governs architectural rules. Department manifests publish ownership and interfaces. The implementation must honor both, while remaining the factual source for what is currently deployed.**
