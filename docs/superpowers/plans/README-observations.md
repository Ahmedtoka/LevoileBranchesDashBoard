# Observations Module — plan index and shared contract

Spec: `docs/superpowers/specs/2026-10-22-observations-risk-module-design.md`
Flow simulator: `docs/superpowers/specs/observations-flow-simulator.html`

| Plan | File | Depends on | Branch |
| --- | --- | --- | --- |
| A Foundation | `2026-10-22-obs-A-foundation.md` | — | `feat/observations-foundation` |
| B Service, API, AI | `2026-10-22-obs-B-api-and-agent.md` | A | `feat/observations-api` |
| C Admin web | `2026-10-22-obs-C-admin-web.md` | A (B for review-queue ticket actions) | `feat/observations-admin-web` |
| D Ops web | `2026-10-22-obs-D-ops-web.md` | A (B for the laptop visit page) | `feat/observations-ops-web` |
| E Flutter | `2026-10-22-obs-E-flutter.md` | B contract (mock until B merges) | `feat/observations` (mobile repo) |
| F Import | `2026-10-22-obs-F-import.md` | A, B | `feat/observations-import` |

Order: A first and merge to `master`. Then B, C, D, E in parallel (each rebases on master). F after B.

## Rules for every session

- Read the spec, then your plan, then this file. Do not change names fixed here; if you must, update this file in your branch and say so in the hand-off.
- Additive guarded migrations only; idempotent seeders only; never `db:seed` without `--class`.
- Tests: `php artisan test` (MySQL `levoile_branches_test`). Keep it green before every commit.
- Commit per task. Do not push to live. Do not touch `EnvLive`.

## Names fixed by Plan A (filled in by the Plan A session at hand-off)

_Plan A appends here: model classes, constants, `ObservationScope::apply` signature, `TicketService::createManual` options, `TicketTransitionDenied`, `TicketStatusChanged` event._

## API contract as built (filled in by the Plan B session at hand-off)

_Plan B appends the JSON shapes from its Task 4 here._
