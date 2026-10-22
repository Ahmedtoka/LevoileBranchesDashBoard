# Observations Module — Plan C: Admin web screens

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Blade screens for areas, risk levels, categories, catalog items, and the classification review queue, plus the sidebar section.

**Architecture:** Follow the existing modal-CRUD pattern of `resources/views/dashboard/departments/index.blade.php` and `users/index.blade.php` (Bootstrap 5.3 RTL, `table.js-table`, `dt()`/`t()` strings). Controllers under `App\Http\Controllers\Web\Observations\`. Authorization through Plan A's `ObservationCatalogPolicy`.

**Tech Stack:** Laravel 12 Blade, Bootstrap 5.3.3 CDN, Bootstrap Icons, vanilla JS. No build step.

**Spec:** design doc §9.1, §9.6, §10 (web table rows `/admin/*` and `/observations/review`). **Depends on Plan A.** Review-queue actions that touch tickets call `ObservationService` from Plan B when merged; until then Task 5 Step 3 notes the stub. Branch: `feat/observations-admin-web`.

## Global Constraints

- Every new string through `dt('…','…')` inline or `t('obs.key','fallback')` with rows added to `TranslationSeeder` group `obs` (idempotent `updateOrCreate`).
- Routes inside the existing `auth` group in `routes/web.php`; each controller method calls `$this->authorize(...)`.
- Sidebar edits only in `resources/views/layouts/app.blade.php`; new section "الملاحظات / Observations" shown when `auth()->user()->isReportingUser()`; "إعدادات الملاحظات" sub-links shown to `super_admin` and department managers of reporting departments.
- Feature tests use Plan A's `Tests\Support\Seeds` and `actingAs` web auth.

## Review Focus

1. Deleting a category with items or observations must be blocked with a message, not cascade (Task 3 `test_category_delete_blocked_when_used`).
2. Changing a branch's area must move it out of the previous area (Task 1 `test_branch_moves_between_areas`).
3. A department manager must get 403 on another department's catalog edit form (Task 4 `test_other_department_manager_forbidden`).
4. Risk level SLA edits must reject non-positive hours and reject editing `slug` (Task 2 `test_slug_is_immutable`).
5. Review "merge into item" must relink the observation and increment `usage_count` once (Task 5 `test_merge_updates_observation_and_usage`).

---

### Task 1: Areas CRUD + branch assignment + manager sync

**Files:**
- Create: `app/Http/Controllers/Web/Observations/AreaController.php` (`index, store, update, destroy, assignBranches`), `resources/views/dashboard/observations/areas/index.blade.php`, `app/Http/Requests/Observations/AreaRequest.php`
- Modify: `routes/web.php` (`/admin/areas` resource-style routes + `POST /admin/areas/{area}/branches`), `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/Observations/Web/AreasTest.php`

**Interfaces:** `assignBranches(Area $area, Request)` accepts `branch_ids[]` and sets `branches.area_id` (branches omitted that were in this area → `null`); when `manager_id` set, replaces that user's `branch_user` rows with the area's branches (spec decision 13).

- [ ] **Step 1: Write failing tests** `test_super_admin_creates_area`, `test_branch_moves_between_areas`, `test_manager_sync_updates_branch_user`, `test_non_admin_forbidden`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (page: table of areas with manager select, branch count; modal with multi-select of branches grouped by current area). **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs-web): areas admin`

### Task 2: Risk levels editor

**Files:** `app/Http/Controllers/Web/Observations/RiskLevelController.php` (`index, update`), `resources/views/dashboard/observations/risk-levels/index.blade.php`, `RiskLevelRequest.php`; routes `GET /admin/risk-levels`, `PUT /admin/risk-levels/{riskLevel}`; test `RiskLevelsTest.php`.

**Interfaces:** editable: `name_ar, name_en, color, default_sla_hours (nullable int ≥1), requires_field_verification, active`. Not editable: `slug, sort_order, ticket_priority`.

- [ ] Tests `test_update_sla_and_flag`, `test_slug_is_immutable` (request with slug ignored), `test_rejects_zero_hours`. FAIL → implement (one inline-edit row per level with color input) → PASS → Commit `feat(obs-web): risk levels editor`.

### Task 3: Categories CRUD

**Files:** `CategoryController.php` (`index, store, update, destroy, reorder`), view `categories/index.blade.php`, `CategoryRequest.php`; routes under `/admin/observation-categories`; test `CategoriesTest.php`.

**Interfaces:** index filtered by `?department_id=` (department managers locked to their own); `reorder` accepts `ids[]` in order → `sort_order`. `destroy` refuses (422 + flash) when `items()->exists()` or `Observation::where('category_id')->exists()`.

- [ ] Tests `test_manager_sees_only_own_department`, `test_create_with_defaults`, `test_reorder`, `test_category_delete_blocked_when_used`. FAIL → implement → PASS → Commit `feat(obs-web): categories admin`.

### Task 4: Catalog items CRUD

**Files:** `CatalogItemController.php` (`index, store, update, destroy, toggle`), view `catalog/index.blade.php` + partial `catalog/_form.blade.php`, `CatalogItemRequest.php`; routes under `/admin/observation-catalog`; test `CatalogTest.php`.

**Interfaces:** form fields per spec §6 incl. a JSON-free UI for `extra_fields` (repeatable rows: key, label_ar, label_en, type select `number|text|employee|select`, options comma list, required) and `escalation_rules` (rows: field, op `gte|lte|eq`, value, risk select); `cc_department_ids` multi-select; `destroy` only when `usage_count = 0` and no observations reference it, else `toggle` active.

- [ ] Tests `test_create_item_with_extra_fields_and_rule`, `test_other_department_manager_forbidden`, `test_delete_blocked_when_used_toggle_allowed`. FAIL → implement → PASS → Commit `feat(obs-web): catalog admin`.

### Task 5: Classification review queue

**Files:** `ReviewController.php` (`index, approve, update, merge, promote, bulkMerge`), view `review/index.blade.php`, requests `ReviewUpdateRequest`, `ReviewMergeRequest`, `ReviewPromoteRequest`; routes under `/observations/review`; test `ReviewTest.php`.

**Interfaces:**
- `index`: observations `classification_status=ai_pending_review` under policy `review`; columns: text, agent suggestion (from `classification.suggested`), employee final, decision buttons; a "similar" grouping by `suggested.catalog_item_id` for `bulkMerge`.
- `approve(Observation)`: `classification_status=ai_reviewed`, `classification_reviewed_by/at`, `admin_final = employee_final`.
- `update(Observation)`: fields `category_id, risk_level_id, responsible_department_id, cc_department_ids[]`; writes them, `admin_final`, and if `ticket` exists and not closed updates `tickets.priority` (from level) and `department_id` through `TicketService` (`assign` reset: `assigned_to=null`, log row action `priority`/`status_change` note `إعادة توجيه من مراجعة التصنيف`).
- `merge(Observation)`: `catalog_item_id`, inherits item's category/risk/responsible unless admin overrides, `classification_status=catalog`, `usage_count++`.
- `promote(Observation)`: creates a catalog item from the form (pre-filled from the text) then behaves like `merge`.
- `bulkMerge`: `observation_ids[]` + `catalog_item_id`.

- [ ] Tests `test_queue_lists_only_pending_in_scope`, `test_approve_sets_admin_final`, `test_update_reroutes_open_ticket`, `test_merge_updates_observation_and_usage`, `test_promote_creates_item_and_links`. FAIL → implement → PASS → Commit `feat(obs-web): classification review queue`.

### Task 6: Sidebar, translations, hand-off

- [ ] Add sidebar section and `TranslationSeeder` rows (group `obs`); run `php artisan db:seed --class=TranslationSeeder` (idempotent); run full `php artisan test`; commit `feat(obs-web): navigation and strings`.
