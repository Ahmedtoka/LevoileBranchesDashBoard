# Observations Module — Plan D: Operations web screens (list, detail, web visit, before/after, dashboard)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The screens people use daily on the web: the observations register, the detail page, the full visit entry from a laptop (field or desk), the "كنا وبقينا" page, and the departments dashboard.

**Architecture:** Read screens are plain Blade + `ObservationScope`. The visit-entry page is a small vanilla-JS app that talks to the **same API controllers as the mobile app**, mounted a second time under the web `auth` group at `/web-api/observations/...` (so no Sanctum SPA setup is needed and there is one business logic). Dashboard numbers come from one query class `ObservationMetrics`.

**Tech Stack:** Laravel 12 Blade, Bootstrap 5.3.3 RTL, Chart.js 4.4.1 (CDN), `App\Support\DateRange` + `partials/filterbar`.

**Spec:** design doc §9.3, §10, §12. **Depends on Plan A; the visit page depends on Plan B's API controllers** (start with Tasks 1, 2, 4, 5; do Task 3 after B merges). Branch: `feat/observations-ops-web`.

## Global Constraints

- Same string and auth rules as Plan C. Every list runs through `ObservationScope::apply`.
- Overdue = `Ticket::isOverdue()`. Dashboard definitions are spec §12 verbatim.
- CSV export reuses the same filtered query (no second query logic).
- The visit page must keep working without the AI (shows "الفحص غير متاح" and lets the user pick a category).

## Review Focus

1. Filters must survive pagination and CSV export (Task 1 `test_filters_apply_to_export`).
2. Detail page must render for an observation with no ticket (activity) and no photos without errors (Task 2 `test_show_activity_without_ticket`).
3. Desk visit "all branches" must force `kind=activity` and never create tickets (Task 3 `test_desk_all_branches_forces_activity`).
4. Dashboard "visits" counts only `submitted` + `field` (Task 5 `test_visits_metric_ignores_drafts_and_desk`).
5. Area manager dashboard must show only their area's branches (Task 5 `test_area_manager_dashboard_scoped`).

---

### Task 1: Observations register `/observations`

**Files:** `app/Http/Controllers/Web/Observations/ObservationController.php` (`index, export`), `resources/views/dashboard/observations/index.blade.php`, `app/Support/ObservationFilters.php` (`fromRequest(Request): self`, `apply(Builder): Builder`, `toQueryString(): array`); routes `GET /observations`, `GET /observations/export.csv`; test `tests/Feature/Observations/Web/RegisterTest.php`.

**Interfaces:** filters: `range` (DateRange on `event_date`), `reporting_department_id, responsible_department_id, area_id, branch_id, category_id, risk, status, kind, classification_status (checkbox "خارج الكتالوج" = catalog_item_id null), q`. Columns per spec §10. Row click → detail. Status pills use colors from `risk_levels.color`.

- [ ] Tests `test_index_scoped_and_filtered`, `test_filters_apply_to_export` (CSV has same row count as filtered page total). FAIL → implement → PASS → Commit `feat(obs-web): observations register`.

### Task 2: Detail `/observations/{observation}`

**Files:** controller `show, reroute, raiseRisk, closeNoAction`; view `show.blade.php`; requests `RerouteRequest`, `RaiseRiskRequest`, `CloseNoActionRequest`; test `DetailTest.php`.

**Interfaces:** shows text, extra values, before/after photos, ticket + its `ticket_updates` timeline (reuse the markup of `dashboard/tickets/show.blade.php` partial-ised into `partials/ticket-timeline.blade.php`), verifications, classification history (suggested → employee → admin). Actions by policy: `reroute` (ops/super: new responsible department → via Plan B `ObservationService::reroute` if present, else direct ticket department update + log), `raiseRisk` (reason required), `closeNoAction` (reason required; ticket, if open, moved to `closed` with note by ops through `changeStatus`, which requires after evidence — so for `closeNoAction` call `TicketService::changeStatus(..., 'rejected')`? No: use a new `TicketService::cancel(Ticket, User, string $note)` that sets `status=closed`, `closed_at`, log note `أُغلقت بدون إجراء`, bypassing the after-photo rule only for this explicit action; add it in this task with a test).

- [ ] Tests `test_show_activity_without_ticket`, `test_close_no_action_requires_reason_and_cancels_ticket`, `test_raise_risk_updates_ticket_priority`. FAIL → implement → PASS → Commit `feat(obs-web): observation detail and admin actions`.

### Task 3: Web visit entry `/observations/new` (laptop)

**Files:** controller `create` (renders page with `meta` JSON inlined), view `new.blade.php`, `public/js/observations-visit.js`; routes: `GET /observations/new`, and in `routes/web.php` a group `Route::prefix('web-api')->middleware('auth')` that re-registers Plan B's `ObservationVisitController` and `ObservationController` methods under the same relative paths as spec §11; test `WebVisitTest.php`.

**Interfaces (page behaviour):**
- Mode toggle field/desk; department select (from `meta.reporting_departments`, default first); branch select (field: single required; desk: multi or "كل الفروع").
- Local draft state mirrors server draft: on first "add" → `POST /web-api/observation-visits` (one per selected branch in desk-multi mode), then `POST .../observations` upsert on every change (debounced 800 ms), photo `<input type=file>` → `POST .../photos`.
- Catalog picker (search by label/keywords, renders `extra_fields`), free-text textarea (one line per point) + "فحص" → `POST .../ai-check`; results panel with per-row edits; "تأكيد الكل".
- "إرسال الزيارة" → `POST .../submit` per visit; shows created tickets.
- Desk + all branches: UI forces kind `activity` and hides risk/responsible controls; server already enforces it.

- [ ] Tests (HTTP level): `test_new_page_renders_with_meta`, `test_web_api_routes_use_session_auth`, `test_desk_all_branches_forces_activity`. FAIL → implement → PASS → Commit `feat(obs-web): laptop visit entry`.

### Task 4: Before & after `/observations/before-after`

**Files:** controller `beforeAfter`; view `before-after.blade.php`; test `BeforeAfterTest.php`.

**Interfaces:** branch select (scoped); table: reference, item, before photo, "كنا" (description), "بقينا" (ticket last note / verification notes), after photo, status, verification date; filter status in `open,in_progress,waiting_verification,verified_closed,reopened`.

- [ ] Test `test_before_after_lists_branch_observations_with_photos`. FAIL → implement → PASS → Commit `feat(obs-web): before and after page`.

### Task 5: Dashboard `/observations/dashboard`

**Files:** `app/Support/ObservationMetrics.php`, controller `dashboard`, view `dashboard.blade.php`; test `tests/Unit/Observations/ObservationMetricsTest.php`, `tests/Feature/Observations/Web/DashboardTest.php`.

**Interfaces:** `ObservationMetrics::for(User $u, DateRange $r, array $filters = []): array` returning keys `visits, branches_visited, observations_per_visit, open_issues, on_time_pct, overdue_now, risk_by_department[{department, counts{slug:n}}], repeated_branches[{branch, item, count, last_at}], responsible_performance[{department, avg_hours, closed}], outside_catalog_pct_by_department[], week_compare{current{...}, previous{...}}`. Each definition exactly spec §12. Every tile links to `/observations?…` with the same filters.

- [ ] Tests `test_visits_metric_ignores_drafts_and_desk`, `test_on_time_pct_uses_closed_tickets_only`, `test_area_manager_dashboard_scoped`. FAIL → implement (6 KPI tiles, departments table, Chart.js stacked bar risk-by-department, repeated table, overdue list) → PASS → Commit `feat(obs-web): departments dashboard`.

### Task 6: Hand-off
- [ ] Add sidebar links (coordinate with Plan C's section: if C merged, add rows; else create the section the same way). Full `php artisan test` green. Commit `feat(obs-web): navigation`.
