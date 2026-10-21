# Observations Module — Plan A: Foundation (schema, models, policies, seed, ticket hardening)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the database tables, Eloquent models, authorization policies, idempotent setup seeder, test harness, and the ticket-closing hardening in place so plans B–F can build on fixed names.

**Architecture:** Additive, guarded migrations on the live Laravel 12 schema; one model per table under `App\Models`; a `ObservationScope` support class that every list query and policy shares; the hardening lives inside `TicketService::changeStatus` so every close path is covered.

**Tech Stack:** Laravel 12.62, PHP 8.2, MySQL/MariaDB, PHPUnit 11.5 (first test suite in this repo).

**Spec:** `docs/superpowers/specs/2026-10-22-observations-risk-module-design.md` (§4–§7, §14, §16). Read it first; this plan does not repeat the rationale.

## Global Constraints

- Every migration guards with `Schema::hasTable` / `Schema::hasColumn`; no `down()` that drops live data (use `dropIfExists` only for brand-new tables).
- Seeders use `updateOrCreate`; nothing deletes or truncates. Never run full `db:seed`.
- `Schema::defaultStringLength(191)` applies; keep string columns ≤191.
- Two-language strings via `dt('عربي','English')` / `t('key','fallback')` from `app/helpers.php`.
- Role slugs: `super_admin, branch_director, area_manager, store_manager, vm_manager, ops_manager, department_manager, department_employee, branch_manager, sales`. Department-manager-ness is `users.is_department_manager`.
- Department slugs that are reporting: `cctv, stock_control, finance, merchandise, hr, it, lp, maintenance, operation, vm`.
- Commit after every task with a conventional message; the repo is `C:\xampp\htdocs\LevoileBranches\BranchesDashboard` (git root). Work on branch `feat/observations-foundation`.

## Review Focus

1. A ticket with `status=waiting_approval` and no `after` evidence must be refused on close with a 422-style message, not silently closed (Task 7 test `test_close_requires_after_evidence`).
2. A `department_employee` of a non-reporting department calling any observation endpoint must get 403 from the policy, not an empty list (Task 6 test `test_non_reporting_employee_denied`).
3. Seeder run twice must leave exactly the same row counts (Task 5 test `test_seeder_is_idempotent`).
4. `tickets.source` backfill: a ticket with `visit_id` → `checklist`; `group_code` like `MR-%` → `maintenance`; others `null` (Task 4 test `test_ticket_source_backfill`).
5. Area scope must include branches via `areas.manager_id` even when `branch_user` is empty (Task 6 test `test_area_manager_scope_uses_area_table`).

---

### Task 1: Test harness

**Files:**
- Create: `phpunit.xml`, `tests/TestCase.php`, `tests/Support/Seeds.php`, `.env.testing`
- Modify: `composer.json` (autoload-dev `"Tests\\": "tests/"` if missing)

**Interfaces:**
- Produces: `Tests\Support\Seeds` with static helpers used by every later test:
  - `role(string $slug): Role`
  - `department(string $slug, array $attrs = []): Department`
  - `branch(string $name, array $attrs = []): Branch`
  - `user(string $roleSlug, array $attrs = []): User` (password `password`, active, email auto-generated)

- [ ] **Step 1: Create MySQL test database** `levoile_branches_test` (root, no password, XAMPP default):
  `C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS levoile_branches_test CHARACTER SET utf8mb4"`
- [ ] **Step 2: Write `phpunit.xml`** (standard Laravel 12 file) with `<env name="DB_DATABASE" value="levoile_branches_test"/>`, `APP_ENV=testing`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`. Write `.env.testing` with the same DB values. Add testsuite `Feature` → `tests/Feature`, `Unit` → `tests/Unit`.
- [ ] **Step 3: Write `tests/TestCase.php`** extending `Illuminate\Foundation\Testing\TestCase` using `RefreshDatabase`.
- [ ] **Step 4: Write `tests/Support/Seeds.php`** with the four helpers above (Role/Department/Branch via `updateOrCreate`; `user()` creates the role if missing).
- [ ] **Step 5: Write a smoke test** `tests/Feature/SmokeTest.php::test_migrations_run_and_login_page_loads` that `GET /login` returns 200.
- [ ] **Step 6: Run** `php artisan test` → Expected: 1 passed.
- [ ] **Step 7: Commit** `test: add phpunit harness and seed helpers`

---

### Task 2: Risk levels, areas, departments.is_reporting

**Files:**
- Create: `database/migrations/2026_10_22_000001_create_risk_levels_and_areas.php`, `app/Models/RiskLevel.php`, `app/Models/Area.php`
- Modify: `app/Models/Branch.php` (add `area()`), `app/Models/Department.php` (fillable `is_reporting`, cast bool), `app/Models/User.php` (add `managedAreas(): HasMany`)
- Test: `tests/Feature/Observations/SchemaRiskAreaTest.php`

**Interfaces:**
- Produces: `RiskLevel` constants `SLUGS = ['critical','high','medium','low','none']`; `RiskLevel::bySlug(string): ?self`; `RiskLevel::oneAbove(): ?self` (returns the next more severe level, null for `critical`); `Area::branches(): HasMany`, `Area::manager(): BelongsTo`; `Branch::area(): BelongsTo`.

- [ ] **Step 1: Write failing test** `test_tables_and_columns_exist` asserting `Schema::hasTable('risk_levels')`, `hasTable('areas')`, `hasColumn('branches','area_id')`, `hasColumn('departments','is_reporting')`; and `test_one_above_returns_next_severity` (`medium`→`high`, `critical`→`null`) after creating the five levels with `sort_order` 1..5.
- [ ] **Step 2: Run** `php artisan test --filter SchemaRiskAreaTest` → FAIL (table missing).
- [ ] **Step 3: Write the migration** per spec §6: `risk_levels` (slug unique, name_ar, name_en, color(20), sort_order uint, default_sla_hours uint nullable, requires_field_verification bool, ticket_priority string(20) nullable, active bool); `areas` (name, name_ar nullable, slug unique, manager_id FK users nullOnDelete, sort_order, active, timestamps); `branches.area_id` FK areas nullOnDelete; `departments.is_reporting` bool default false. All guarded.
- [ ] **Step 4: Write the models and relations.** `RiskLevel::oneAbove()` = the level with `sort_order = $this->sort_order - 1`.
- [ ] **Step 5: Run** `php artisan migrate` on dev DB, then the test → PASS.
- [ ] **Step 6: Commit** `feat(obs): risk levels, areas, departments.is_reporting`

---

### Task 3: Categories and catalog items

**Files:**
- Create: `database/migrations/2026_10_22_000002_create_observation_categories_and_catalog.php`, `app/Models/ObservationCategory.php`, `app/Models/ObservationCatalogItem.php`
- Test: `tests/Feature/Observations/SchemaCatalogTest.php`

**Interfaces:**
- Produces: `ObservationCategory` (`department()`, `riskLevel()`, `responsibleDepartment()`, `items(): HasMany`, constants `KINDS = ['issue','activity','assessment','positive']`); `ObservationCatalogItem` (`category()`, `riskLevel()`, `responsibleDepartment()`, casts `keywords`, `extra_fields`, `escalation_rules`, `cc_department_ids` → array; method `effectiveSlaHours(): ?int` = `sla_hours ?? category.default_sla_hours ?? riskLevel.default_sla_hours`).

- [ ] **Step 1: Write failing tests** `test_unique_slug_per_department` (same slug in two departments allowed, duplicate in one throws `QueryException`) and `test_effective_sla_falls_back_in_order` (item 10 → 10; item null + category 20 → 20; both null + level 48 → 48).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Write migration** per spec §6 (`observation_categories` unique (department_id, slug); `observation_catalog_items` code unique, json columns, `usage_count` uint default 0) and the two models.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): categories and catalog tables`

---

### Task 4: Observations core tables and ticket link

**Files:**
- Create: `database/migrations/2026_10_22_000003_create_observations_tables.php`, `database/migrations/2026_10_22_000004_add_source_and_observation_to_tickets.php`, `app/Models/ObservationVisit.php`, `app/Models/Observation.php`, `app/Models/ObservationPhoto.php`, `app/Models/ObservationVerification.php`, `app/Models/AiClassification.php`
- Modify: `app/Models/Ticket.php` (fillable `source`, `observation_id`; `observation(): BelongsTo`; const `SOURCES = ['checklist','maintenance','observation']`)
- Test: `tests/Feature/Observations/SchemaObservationsTest.php`

**Interfaces:**
- Produces:
  - `ObservationVisit`: consts `TYPES=['field','desk']`, `STATUSES=['draft','submitted']`, `SOURCES=['mobile','web','import']`; `static nextCode(): string` (`OV-00001` style, same approach as `Visit::nextCode()`); relations `department()`, `branch()`, `user()`, `observations()`.
  - `Observation`: consts `KINDS`, `STATUSES=['open','in_progress','waiting_verification','verified_closed','reopened','closed_no_action','positive']`, `CLASSIFICATION=['catalog','manual','ai_pending_review','ai_reviewed']`; `static nextReference(): string` (`OB-00001`); relations `visit()`, `branch()`, `reportingDepartment()`, `responsibleDepartment()`, `category()`, `catalogItem()`, `riskLevel()`, `ticket()`, `tickets(): HasMany` (all tickets incl. reopen), `photos()`, `verifications()`, `classification(): HasOne`; casts `extra_values`, `cc_department_ids` array, `event_date` date.
  - `Ticket::observation()`.

- [ ] **Step 1: Write failing tests** `test_observation_reference_increments` (`OB-00001`, `OB-00002`), `test_ticket_source_backfill` (insert three tickets before running the backfill migration logic via `Artisan::call('migrate')` on fresh DB is not possible; instead create tickets then call the migration class's `up()` method a second time — it must be idempotent — and assert sources).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Write migration 000003** exactly per spec §6 tables `observation_visits`, `observations` (with the four indexes), `observation_photos`, `observation_verifications`, `ai_classifications`.
- [ ] **Step 4: Write migration 000004**: add `tickets.source` string(20) nullable and `tickets.observation_id` FK nullOnDelete; then backfill with two `DB::table('tickets')->whereNull('source')` updates (`visit_id not null` → checklist; `group_code like 'MR-%'` → maintenance). Guard both column additions; the backfill is safe to re-run.
- [ ] **Step 5: Write the five models** with the interfaces above.
- [ ] **Step 6: Run** `php artisan migrate` then tests → PASS.
- [ ] **Step 7: Commit** `feat(obs): observations tables, ticket source and link`

---

### Task 5: ObservationSetupSeeder (idempotent)

**Files:**
- Create: `database/seeders/ObservationSetupSeeder.php`, `database/data/observation_catalog.json`
- Modify: `DEPLOY_LIVE.md` (add the one allowed command `php artisan db:seed --class=ObservationSetupSeeder --force`)
- Test: `tests/Feature/Observations/SetupSeederTest.php`

**Interfaces:**
- Produces: the five risk levels (values spec §7: critical 24h/field, high 48h/field, medium 72h, low 168h, none null; colors `#b3261e,#d9480f,#c58a00,#2f7d4f,#7a7a7a`; ticket_priority = same slug except none → null); `departments.is_reporting=true` for the ten slugs; one `Area` per distinct non-empty `branches.area` (slug = `Str::slug`), branches linked; categories and catalog items from `observation_catalog.json`.

- [ ] **Step 1: Write `observation_catalog.json`** from spec §14: an array of `{department: slug, category: {slug, name_ar, name_en, default_kind, risk, responsible, cc:[], sla_hours?}, items:[{code, label_ar, label_en, keywords[], kind, risk, responsible, cc:[], extra_fields:[], escalation_rules?}]}`. Every department in §14 gets an `other` category (`أخرى`, medium, responsible null). Include at least the items named in §14 (CCTV 14, Stock Control 9 + training activity, Finance 5, Merchandise 12, Maintenance 8 activity items + `urgent` high item, HR 1, IT 1, LP 1). Late-opening item: `extra_fields=[{key:"minutes_late",type:"number",label_ar:"دقائق التأخير",label_en:"Minutes late",required:true},{key:"employee",type:"text",label_ar:"الموظف",label_en:"Employee",required:false}]`, `escalation_rules=[{"field":"minutes_late","gte":30,"risk":"high"}]`.
- [ ] **Step 2: Write failing test** `test_seeder_creates_setup` (5 risk levels; `cctv` department `is_reporting`; an Area exists for each distinct branch area; ≥ 25 categories; ≥ 60 items) and `test_seeder_is_idempotent` (run twice, counts equal).
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement the seeder** with `updateOrCreate` keyed on `slug`/`code`; skip a department missing from DB with a console warning (never create departments).
- [ ] **Step 5: Run tests** → PASS. Run `php artisan db:seed --class=ObservationSetupSeeder` on dev DB.
- [ ] **Step 6: Commit** `feat(obs): idempotent setup seeder with initial catalog`

---

### Task 6: Scope and policies

**Files:**
- Create: `app/Support/ObservationScope.php`, `app/Policies/ObservationPolicy.php`, `app/Policies/ObservationVisitPolicy.php`, `app/Policies/ObservationCatalogPolicy.php` (covers categories, items, areas, risk levels via explicit methods)
- Modify: `app/Providers/AppServiceProvider.php` (register with `Gate::policy`), `app/Models/User.php` (add `isReportingUser(): bool`, `defaultReportingDepartmentId(): ?int`, `scopedBranchIds(): ?array`)
- Test: `tests/Feature/Observations/PolicyTest.php`

**Interfaces:**
- Produces:
  - `ObservationScope::apply(Builder $q, User $u, string $branchColumn = 'branch_id', string $reportingColumn = 'reporting_department_id'): Builder` — super_admin/ops_manager/branch_director/vm_manager: unchanged; area_manager: `branch_id in` branches of areas they manage ∪ `branch_user`; store_manager/branch_manager/sales: `branch_id = users.branch_id` or `branches.manager_id`; department manager: `reporting_department_id = dept` OR ticket's `department_id = dept`; department_employee: `created_by = user` OR `reporting_department_id = dept` when `is_reporting`.
  - `User::isReportingUser()`: true for super_admin, ops_manager, area_manager, branch_director, vm_manager, and any user whose department `is_reporting`.
  - `User::defaultReportingDepartmentId()`: own department if `is_reporting`; else `vm` for vm_manager; else `operation`.
  - Policies: `ObservationVisitPolicy@create(User)`, `@view`, `@update` (owner & draft), `@submit` (owner & draft); `ObservationPolicy@viewAny`, `@view`, `@verify` (reporting dept member or area manager of branch or ops/super), `@reroute` (ops/super), `@closeNoAction` (ops/super); `ObservationCatalogPolicy@manageAreas` (super), `@manageRiskLevels` (super), `@manageCategory(User, ?Department)` (super or manager of that department), `@review(User, Observation)` (super or manager of reporting dept).

- [ ] **Step 1: Write failing tests**: `test_non_reporting_employee_denied` (cleaning employee → `create` false, `viewAny` false), `test_area_manager_scope_uses_area_table` (area with manager, two branches, no branch_user → scope returns both branch ids), `test_store_manager_sees_only_own_branch`, `test_department_manager_can_manage_only_own_categories`, `test_default_reporting_department` (area_manager → operation id; cctv employee → cctv id; vm_manager → vm id).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** scope, user helpers, policies, registration.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): scope helper and policies`

---

### Task 7: Ticket closing hardening and silent creation

**Files:**
- Modify: `app/Services/TicketService.php` (`createManual(array $attrs, ?int $actorId = null, array $options = [])`, `changeStatus`), `app/Http/Controllers/Api/TicketController.php` (`approve`, `reject`, `sendForApproval`, `deptSetStatus` → go through `canTransition` and `changeStatus`), `app/Http/Controllers/Web/TicketController.php@transition`
- Create: `app/Exceptions/TicketTransitionDenied.php` (extends `RuntimeException`, carries HTTP status 403 or 422 and an Arabic+English message)
- Test: `tests/Feature/Tickets/CloseHardeningTest.php`

**Interfaces:**
- Produces: `TicketService::createManual($attrs, $actorId, ['silent' => true])` skips `notifyDepartmentManager` and `autoAssign`; `TicketService::canClose(Ticket, User): bool` (rules spec §5: created_by, branch store manager via `branches.manager_id` or `users.branch_id` with store_manager/branch_manager role, area manager of the branch's area or `branch_user`, ops_manager, super_admin, manager of `tickets.department_id`); `changeStatus(..., 'closed')` throws `TicketTransitionDenied` when actor not allowed (403) or no `ticket_updates` row with `evidence_kind='after'` (422). All four controller paths catch it and return `response()->json(['message'=>…], $status)` / `back()->withErrors`.
- Also: `changeStatus` fires `event(new \App\Events\TicketStatusChanged($ticket, $from, $to))` (create the event class, no listeners here; Plan B listens).

- [ ] **Step 1: Write failing tests**: `test_close_requires_after_evidence` (422), `test_close_denied_for_unrelated_user` (403), `test_store_manager_of_branch_can_close` (closed, `closed_at` set), `test_department_manager_can_close_own_department_ticket`, `test_silent_create_skips_notifications_and_assignment` (no `notifications` rows, `assigned_to` null), `test_approve_endpoint_respects_transition` (`POST /api/tickets/{id}/approve` on `in_progress` ticket → 422).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** per Interfaces. Keep every other behaviour of `changeStatus` unchanged.
- [ ] **Step 4: Run** full suite `php artisan test` → PASS.
- [ ] **Step 5: Commit** `fix(tickets): enforce close authorization, after-photo requirement, and transitions on all paths`

---

### Task 8: Hand-off

- [ ] **Step 1:** Run `php artisan migrate:status` and confirm the four new migrations ran; run `php artisan test` → all green.
- [ ] **Step 2:** Append to `docs/superpowers/plans/README-observations.md` a table "Names fixed by Plan A" listing model class names, constants and the `ObservationScope::apply` signature (copy from the Interfaces blocks above) so B–F read one page.
- [ ] **Step 3: Commit** `docs(obs): foundation hand-off notes`
