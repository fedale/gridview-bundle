# Proposal: a slim data-source contract (deferred)

Status: **implemented** in 1.3 — see [`DATA-SOURCE-CONTRACT-plan.md`](DATA-SOURCE-CONTRACT-plan.md).
Recorded on 2026-10-16 after comparing the gridview and calendar bundles; the trigger came with
`metadata-driven` (2026-10-18).

Deviations from this proposal, with their reasons:

- `fetch(GridQuery): GridResult` became two methods, `count(GridQuery)` and `fetch(GridQuery)`:
  the pagination clamps the requested page against the total before the offset can be computed,
  so the total must be known first.
- The existing providers were not ported onto the new contract: `GridDataSourceInterface` sits
  beside `DataProviderInterface`, wrapped by `DataSourceDataProvider`. `EntityDataProvider` and
  `JsonDataProvider` are unchanged; only the Doctrine filter appliers were rebuilt on the shared
  criteria builders.

## Context

The two bundles get their data in opposite ways, and that is intentional:

- **Calendar bundle** — the application *pushes* data. It implements
  `EventSourceInterface` (plus resource/intent/finder contracts), registers it with
  `#[AsCalendarSource]`, and returns `CalendarEvent` DTOs for a `CalendarQuery`.
  The bundle knows nothing about the domain (recurrences, permissions, resources,
  conflict rules all live in the adapter).
- **Gridview bundle** — the bundle *pulls* data. `EntityDataProvider` (Doctrine) and
  `JsonDataProvider` (HTTP API) query the source themselves and apply filters, sort,
  pagination, global search and export. The application mostly supplies configuration.

Unifying the two models is **not** desirable: a calendar driven by configuration would
reinvent its adapters in YAML, and a gridview requiring an adapter would lose its
"configuration only" strength.

## The problem worth fixing

`DataProviderInterface` mixes two concerns:

- configuration, partly Doctrine-specific: `prepareModels`, `setDefaultParams`,
  `setFormName`, `setAlias`, `setSearchFields`, `setIgnoredAttributes`;
- data access: `getData`, `getAllData`, `getSort`, `getPagination`, `applyGlobalSearch`.

A grid over a source that is neither Doctrine nor a JSON API (a domain service, a file,
computed data) would have to implement methods that are meaningless for it.

## Proposed direction

1. Introduce a minimal contract modeled on the calendar one: a query object in, DTOs out,
   e.g. `fetch(GridQuery $query): GridResult` — `GridQuery` carries filters, sort, page
   and search term; `GridResult` carries rows and total count (plus an unpaginated
   variant or flag for export).
2. Make `EntityDataProvider` and `JsonDataProvider` ready-made implementations of that
   contract, keeping their current configuration-driven behavior.
3. Keep the common path "configuration only"; the special path becomes "write an
   adapter", the same mental model as the calendar bundle.

## Trigger

Do this refactor when a grid is actually needed over a custom (non-Doctrine, non-JSON)
source, and design `GridQuery`/`GridResult` around that concrete case rather than
for symmetry alone.
