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

| G Integration & handover | `2026-10-22-obs-G-handover.md` | all of the above | run on `master` |
| H Manager approval gate + department reply | `2026-10-25-obs-H-manager-approval.md` | B, C (D for the demo seeder) | `feat/observations-approval` (web) + `feat/observations` (mobile) |

Order: A first and merge to `master`. Then B, C, D, E in parallel (each rebases on master). F after B. G last.

## Demo users (owner requirement, 2026-10-22)

The owner wants to open the project and tap a user to log in, on the laptop and on the phone, without typing. Plan D seeds them and adds the web quick-login; Plan E adds the mobile quick-login; both read the same list from `GET /api/demo-users`, which only answers when `config('app.demo_login')` is true (`DEMO_LOGIN=true` in `.env`, never on live). Password for all: `password`. Domain `@levoile.test` (the existing `admin@levoile.test` super admin is reused).

| email | name (ar) | role | department / scope |
| --- | --- | --- | --- |
| `admin@levoile.test` | المدير العام | super_admin | — |
| `ops@levoile.test` | مدير العمليات | ops_manager | — |
| `area.cairo@levoile.test` | مدير منطقة القاهرة | area_manager | manager of the area that contains "Sheikh Zayed - Saraya Mall" and "Nasr City - City Stars Mall" (DemoUsersSeeder moves both branches into one area `demo-cairo` if they are not already in a shared area) |
| `store.zayed@levoile.test` | مدير فرع زايد | store_manager | `branch_id` = Saraya Mall, `branches.manager_id` set |
| `cctv.manager@levoile.test` | مدير إدارة الكاميرات | department_manager, `is_department_manager=1` | cctv |
| `cctv.emp@levoile.test` | موظف كاميرات | department_employee | cctv (reporting) |
| `stock.emp@levoile.test` | موظف مراقبة مخزون | department_employee | stock_control (reporting) |
| `operation.manager@levoile.test` | مدير التشغيل (المسؤول عن الحل) | department_manager, `is_department_manager=1` | operation |
| `operation.emp@levoile.test` | موظف تشغيل (ينفذ التيكت) | department_employee | operation, `branch_user` = Saraya Mall + City Stars |
| `maintenance.tech@levoile.test` | فني صيانة | department_employee | maintenance, `branch_user` = Saraya Mall + City Stars |

`GET /api/demo-users` → `{data:[{email, name, role, hint}]}`; `hint` is one Arabic line of what to try with that user (e.g. "سجّل زيارة CCTV على فرع زايد").

## Rules for every session

- Read the spec, then your plan, then this file. Do not change names fixed here; if you must, update this file in your branch and say so in the hand-off.
- Additive guarded migrations only; idempotent seeders only; never `db:seed` without `--class`.
- Tests: `php artisan test` (MySQL `levoile_branches_test`). Keep it green before every commit.
- Commit per task. Do not push to live. Do not touch `EnvLive`.

## Names fixed by Plan A (filled in by the Plan A session at hand-off)

> Written by the Plan A session at hand-off (branch `feat/observations-foundation`, tasks 1-7, 35 tests green). Everything below is read from the committed source; if code and this page ever disagree, the code wins. Migrations are guarded (`Schema::hasColumn` / `hasTable`) and their `down()` never drops columns on existing tables.

### Tables (migrations `database/migrations/2026_10_22_*`)

| Table / column | Migration | Notes |
|---|---|---|
| `risk_levels` | `2026_10_22_000001_create_risk_levels_and_areas` | slug, name_ar, name_en, color, sort_order, default_sla_hours, requires_field_verification, ticket_priority, active |
| `areas` | `2026_10_22_000001_create_risk_levels_and_areas` | name, name_ar, slug, manager_id (users), sort_order, active |
| `branches.area_id` | `2026_10_22_000001_create_risk_levels_and_areas` | FK to `areas`; the legacy text column `branches.area` is kept (see Hazards) |
| `departments.is_reporting` | `2026_10_22_000001_create_risk_levels_and_areas` | boolean flag: department may record observations |
| `observation_categories` | `2026_10_22_000002_create_observation_categories_and_catalog` | per-department category with defaults (kind, risk, responsible, cc, SLA) |
| `observation_catalog_items` | `2026_10_22_000002_create_observation_categories_and_catalog` | unique `code`; keywords / extra_fields / escalation_rules / cc_department_ids are JSON |
| `observation_visits` | `2026_10_22_000003_create_observations_tables` | unique `code` (`OV-00001`) |
| `observations` | `2026_10_22_000003_create_observations_tables` | unique `reference` (`OB-00001`) |
| `observation_photos` | `2026_10_22_000003_create_observations_tables` | |
| `observation_verifications` | `2026_10_22_000003_create_observations_tables` | |
| `ai_classifications` | `2026_10_22_000003_create_observations_tables` | |
| `tickets.source` | `2026_10_22_000004_add_source_and_observation_to_tickets` | nullable string(20); the migration back-fills `checklist` (visit_id not null) and `maintenance` (group_code `MR-%`) |
| `tickets.observation_id` | `2026_10_22_000004_add_source_and_observation_to_tickets` | nullable FK to `observations`, `nullOnDelete` |

Enum-like columns are plain `string` columns (not MySQL ENUM); the allowed values live in the model constants below. `observation_visits.source` and `observations.kind` have NO default: always pass them.

### Models and constants (`App\Models\...`)

| Class | Constants | Relations | Notable methods / casts |
|---|---|---|---|
| `RiskLevel` | `SLUGS = ['critical','high','medium','low','none']` | none | casts: `requires_field_verification`, `active` boolean. `static bySlug(string): ?self`; `oneAbove(): ?self` (the level with `sort_order - 1`, i.e. the next severity; null for critical) |
| `Area` | none | `branches(): HasMany` (`area_id`), `manager(): BelongsTo` User (`manager_id`) | cast `active` boolean |
| `ObservationCategory` | `KINDS = ['issue','activity','assessment','positive']` | `department()`, `riskLevel()` (`default_risk_level_id`), `responsibleDepartment()` (`default_responsible_department_id`), `items(): HasMany` (`category_id`) | casts: `default_cc_department_ids` array, `active` boolean |
| `ObservationCatalogItem` | none | `category()` (`category_id`), `riskLevel()` (`risk_level_id`), `responsibleDepartment()` (`responsible_department_id`) | casts: `keywords`, `extra_fields`, `escalation_rules`, `cc_department_ids` array; `active` boolean. `effectiveSlaHours(): ?int` = item `sla_hours` ?? category `default_sla_hours` ?? risk level `default_sla_hours` |
| `ObservationVisit` | `TYPES = ['field','desk']`, `STATUSES = ['draft','submitted']`, `SOURCES = ['mobile','web','import']` | `department()`, `branch()`, `user()`, `observations(): HasMany` (`observation_visit_id`) | casts: `visit_date` date; `started_at`, `submitted_at` datetime; `checkin_simulated` boolean; `checkin_latitude/longitude` float. `static nextCode(): string` (`OV-00001`), auto-assigned in the `creating` event when `code` is empty |
| `Observation` | `KINDS = ['issue','activity','assessment','positive']`; `STATUSES = ['open','in_progress','waiting_verification','verified_closed','reopened','closed_no_action','positive']`; `CLASSIFICATION = ['catalog','manual','ai_pending_review','ai_reviewed']` | `visit()` (`observation_visit_id`), `branch()`, `reportingDepartment()` (`reporting_department_id`), `responsibleDepartment()` (`responsible_department_id`), `category()` (`category_id`), `catalogItem()` (`catalog_item_id`), `riskLevel()` (`risk_level_id`), `ticket(): BelongsTo` (`ticket_id`, the current ticket), `tickets(): HasMany` (`tickets.observation_id`, ALL tickets incl. reopens), `photos()`, `verifications()`, `classification(): HasOne` AiClassification, `creator()` (`created_by`) | casts: `extra_values`, `cc_department_ids` array; `event_date` date; `classification_reviewed_at` datetime; `repeat_count` integer. `static nextReference(): string` (`OB-00001`), auto-assigned in `creating` when `reference` is empty. Fillable also has `risk_override_reason, product_or_area, rating, classification_status, classification_reviewed_by, repeat_count, escalated_from_risk_level_id, import_source, import_row` |
| `ObservationPhoto` | `KINDS = ['before','after','other']`, `SOURCES = ['camera','gallery']` | `observation()`, `uploader()` (`uploaded_by`) | fillable `observation_id, path, kind, source, uploaded_by` |
| `ObservationVerification` | `RESULTS = ['resolved','not_resolved','partially']` | `observation()`, `verifier()` (`verified_by`), `visit()` (`observation_visit_id`) | cast `verified_at` datetime |
| `AiClassification` | `STATUSES = ['ok','failed','skipped']` | `visit()`, `observation()` | casts: `suggested`, `employee_final`, `admin_final` array. `suggested, model, input_tokens, output_tokens, latency_ms` are nullable (failed/skipped rows). Fillable: `observation_visit_id, observation_id, request_hash, input_text, suggested, employee_final, admin_final, model, input_tokens, output_tokens, latency_ms, status, error` |
| `Ticket` (existing) | `SOURCES = ['checklist','maintenance','observation']` | `observation(): BelongsTo` (`observation_id`) | `static canTransition(string $from, string $to): bool` (status cycle) |
| `Branch` (existing) | none | `area(): BelongsTo` Area (`area_id`) | see Hazards: `$branch->area` is the legacy text column |
| `Department` (existing) | none | none | `is_reporting` in fillable, cast boolean |
| `User` (existing) | none | `managedAreas(): HasMany` Area (`manager_id`) | `isReportingUser(): bool` (super_admin, ops_manager, area_manager, branch_director, vm_manager, or member of an `is_reporting` department); `defaultReportingDepartmentId(): ?int` (own department if reporting, else `vm` for vm_manager, else `operation`; null if the slug is missing); `scopedBranchIds(): ?array` (area_manager: branches of managed areas + `branch_user`; store_manager/branch_manager: managed branches + own `branch_id`; null for global and department roles; `[]` for any other role, including `sales`) |

### Scope: `App\Support\ObservationScope`

```php
public static function apply(Builder $q, User $u, string $branchColumn = 'branch_id', string $reportingColumn = 'reporting_department_id'): Builder
```

Constants: `UNRESTRICTED_ROLES = ['super_admin','ops_manager','branch_director','vm_manager']`, `BRANCH_ROLES = ['store_manager','branch_manager']` (`sales` is not a module role: spec section 5 grants it nothing).
Helper statics: `managedReportingDepartmentId(User): ?int` (department manager, by `is_department_manager` flag or role `department_manager`, of a reporting department: that id, else null) and `employeeReportingDepartmentId(User): ?int` (role `department_employee` of a reporting department: that id, else null).

Role precedence (first match wins): global roles -> branch roles (area_manager, store_manager, branch_manager) -> department manager -> department employee -> nothing.
- Global roles: query unchanged (everything).
- `area_manager` and `BRANCH_ROLES`: `whereIn(branch column, $u->scopedBranchIds() ?? [])`.
- Department manager of a reporting department: `reporting_department_id = dept` OR the model has ANY ticket (`tickets` relation, `tickets.observation_id`) with `department_id = dept`.
- Department employee of a reporting department: own rows (`created_by`, or `user_id` when the model is `ObservationVisit`) OR `reporting_department_id = dept`.
- Anyone else, including managers/employees of a NON-reporting department: `whereRaw('0 = 1')` (no rows, even when a ticket is routed to their department; spec section 5).
- Columns are qualified through the builder. For `ObservationVisit` queries pass the reporting column as `'department_id'`: `ObservationScope::apply(ObservationVisit::query(), $u, 'branch_id', 'department_id')`.

### Policies (`App\Policies\...`)

Registered in `AppServiceProvider::boot()`:

```php
Gate::policy(ObservationVisit::class, ObservationVisitPolicy::class);
Gate::policy(Observation::class, ObservationPolicy::class);
foreach ([ObservationCategory::class, ObservationCatalogItem::class, Area::class, RiskLevel::class] as $model) {
    Gate::policy($model, ObservationCatalogPolicy::class);
}
```

`ObservationPolicy`
- `viewAny(User)`: `isReportingUser()` OR has a `BRANCH_ROLES` role. Call: `$user->can('viewAny', Observation::class)`.
- `view(User, Observation)`: row passes `ObservationScope::apply(...)` (one query per call).
- `verify(User, Observation)`: super_admin/ops_manager; or the user's `department_id` equals the observation's `reporting_department_id`; or an area_manager whose `scopedBranchIds()` contains the observation's branch.
- `reroute(User, Observation)`: super_admin or ops_manager.
- `closeNoAction(User, Observation)`: super_admin or ops_manager.
- Call forms: `$user->can('view'|'verify'|'reroute'|'closeNoAction', $observation)`.

`ObservationVisitPolicy`
- `create(User)`: `isReportingUser()`. Call: `$user->can('create', ObservationVisit::class)`.
- `view(User, ObservationVisit)`: owner (`user_id`) OR passes `ObservationScope::apply(..., 'branch_id', 'department_id')`.
- `update(User, ObservationVisit)` and `submit(...)`: owner AND `status === 'draft'`.
- Call: `$user->can('view'|'update'|'submit', $visit)`.

`ObservationCatalogPolicy` (one policy for categories, catalog items, areas and risk levels; it has NO standard `view/update/...` abilities)
- `manageAreas(User)`: super_admin. Call: `can('manageAreas', Area::class)`.
- `manageRiskLevels(User)`: super_admin. Call: `can('manageRiskLevels', RiskLevel::class)`.
- `manageCategory(User, ?Department $department = null)`: super_admin; or manager of that reporting department. Call: `can('manageCategory', [ObservationCategory::class, $department])`.
- `review(User, Observation)`: super_admin; or manager of the observation's reporting department. Call: `can('review', [ObservationCategory::class, $observation])`.

### Tickets (`App\Services\TicketService`, `App\Exceptions\TicketTransitionDenied`, `App\Events\TicketStatusChanged`)

- `createManual(array $attrs, ?int $actorId = null, array $options = []): Ticket`. Option `['silent' => true]` skips `notifyDepartmentManager` and `autoAssign`; the `created` log row is still written. Status defaults to `open`, reference to `referenceForDept(department_id)`. Put `source => 'observation'` and `observation_id` in `$attrs`.
- `canClose(Ticket, User): bool`: true for the ticket creator (`created_by`); super_admin / ops_manager; the branch's `manager_id`; store_manager / branch_manager whose `branch_id` equals the ticket's branch; an area_manager who manages the branch's area (`branch->area()->value('manager_id')`) or has the branch in `branch_user`; or a department manager (`is_department_manager`) whose `department_id` equals the ticket's `department_id`.
- `changeStatus(Ticket $ticket, string $status, ?User $actor = null, ?string $note = null): Ticket`. For `$status === 'closed'` it checks BEFORE any write: (1) if `$actor !== null` and `! canClose(...)` it throws `TicketTransitionDenied::notAllowed()` (HTTP 403); (2) if the ticket has no `ticket_updates` row with `evidence_kind = 'after'` it throws `TicketTransitionDenied::afterEvidenceRequired()` (HTTP 422). Actor `null` = system call: it skips the authorization check (1) but NOT the evidence check (2). It fires `event(new TicketStatusChanged($ticket, $from, $status))` at the very end, after notifications, for every status change.
- `App\Exceptions\TicketTransitionDenied extends \RuntimeException`: `public readonly int $status` (403 or 422); message via `dt(ar, en)`. Factories: `notAllowed()` (403), `afterEvidenceRequired()` (422), `invalidTransition()` (422, 'انتقال غير مسموح في دورة الحالة.'). Callers catch it and return `$e->getMessage()` with `$e->status` (JSON) or flash the message (web).
- `App\Events\TicketStatusChanged`: `__construct(public Ticket $ticket, public ?string $from, public string $to)`; uses `Dispatchable, SerializesModels`. Fired by `changeStatus()` (every status), `assign()` (`$to = 'assigned'`, also reached via `autoAssign`) and `declineByTechnician()` (`$to = 'rejected'`), each time at the end, after the method's notifications.
- Status-cycle enforcement: `Ticket::canTransition($from, $to)` is now checked by API `TicketController@sendForApproval`, `@approve`, `@reject` and `@deptSetStatus` (in addition to the existing `@updateStatus` and the web `TicketController` update). A jump outside the cycle returns 422 'انتقال غير مسموح في دورة الحالة.'.

### Seeder

`php artisan db:seed --class=ObservationSetupSeeder --force` (run only with `--class=`; it is not in `DatabaseSeeder`). It seeds:
1. the 5 risk levels (critical 24h, high 48h, medium 72h, low 168h, none null; `requires_field_verification` true for critical/high);
2. `departments.is_reporting = true` for `cctv, stock_control, finance, merchandise, hr, it, lp, maintenance, operation, vm` (a missing department slug is skipped with a warning);
3. `areas` from the distinct non-empty legacy `branches.area` values (slug = `Str::slug`), setting `branches.area_id` only where it is still NULL;
4. the catalog from `database/data/observation_catalog.json`: 36 categories and 62 items. Empty `cc_department_ids` are stored as NULL.

Idempotency: `updateOrCreate`, so re-running re-applies seeded labels/risk/SLA/responsible departments and keeps `active`, `usage_count`, admin-created rows and admin branch->area moves. Run on live once; re-run only to re-apply the catalog intentionally. The dev DB needed `php artisan db:seed --class=DepartmentSeeder` first (finance/hr/lp/vm/warehouse were missing).

### Tests

- Run: `php artisan test` (PHPUnit against MySQL `levoile_branches_test`, config in the committed `.env.testing`: root, no password). Current count: 35 tests, 153 assertions, all passing. If `dt()` is undefined locally, run `composer dump-autoload` once.
- Suites: `tests/Feature/SmokeTest.php`, `tests/Feature/Observations/{SchemaRiskArea,SchemaCatalog,SchemaObservations,SetupSeeder,Policy}Test.php`, `tests/Feature/Tickets/CloseHardeningTest.php`.
- Helpers `Tests\Support\Seeds` (all static):
  - `Seeds::role(string $slug): Role` (updateOrCreate by slug)
  - `Seeds::department(string $slug, array $attrs = []): Department` (updateOrCreate by slug; default `name` is the headline of the slug, `ticket_prefix` the first 3 letters uppercased; pass `['is_reporting' => true]` etc.)
  - `Seeds::branch(string $name, array $attrs = []): Branch` (updateOrCreate by `branch_name`)
  - `Seeds::user(string $roleSlug, array $attrs = []): User` (creates the role if needed; random unique email, password `password`, `active` true; override anything via `$attrs`, e.g. `department_id`, `branch_id`, `is_department_manager`)

### Deviations from the plan text

- `Observation::nextReference()` / `ObservationVisit::nextCode()` derive the next number from the highest existing `OB-%` / `OV-%` suffix (not `max(id)+1`) and are auto-assigned in the model `creating` event when empty. Formats `OB-00001`, `OV-00001`.
- Enum-like columns are plain `string` columns (not MySQL ENUM). `observation_visits.source` and `observations.kind` have NO default: always pass them.
- `ai_classifications.suggested`, `model`, `input_tokens`, `output_tokens`, `latency_ms` are nullable (failed/skipped rows).
- Empty `cc_department_ids` are stored as NULL by the seeder.
- Migrations' `down()` never drop columns on existing tables (only FK constraints); only brand-new tables are dropped.
- `.env.testing` is committed (local XAMPP test DB `levoile_branches_test`, root/no password). `php artisan test` needs `composer dump-autoload` once locally if `dt()` is undefined.

### Hazards for Plans B-F

- **`$branch->area` is the legacy text.** It returns the TEXT column `branches.area`, not the `Area` model (Eloquent attributes win over relations). Use `$branch->area()->first()`, `$branch->area()->value('manager_id')`, `whereHas('area', ...)`, or `area_id`. `Branch::area(): BelongsTo` is still the relation name.
- **`User::scopedBranchIds()` is not a visibility filter.** It returns NULL for both "unrestricted" global roles AND department roles (which are scoped by department, not branch). Never use it alone; always go through `ObservationScope::apply()`.
- **`ObservationCatalogPolicy` has no standard abilities.** Call `can('manageAreas', Area::class)`, `can('manageRiskLevels', RiskLevel::class)`, `can('manageCategory', [ObservationCategory::class, $department])`, `can('review', [ObservationCategory::class, $observation])`.
- **No per-row `@can('view')` in lists.** `ObservationPolicy@view` runs one query per call; lists must use `ObservationScope::apply()`.
- **Role precedence** in `ObservationScope::apply`: global roles -> branch roles (area_manager, store_manager, branch_manager) -> department manager -> department employee -> nothing. A non-reporting department's manager/employee sees NO module rows even when a ticket is routed to them (spec section 5).
- **Department scopes.** Department-manager scope includes observations having ANY ticket (`tickets.observation_id`) for their department; department-employee scope = own (`created_by`) OR same reporting department (plan text; spec section 5 says "own" only; flagged to the owner).
- **Closing tickets.** `TicketService::changeStatus(..., 'closed')` throws `App\Exceptions\TicketTransitionDenied` (public readonly int `$status`: 403 not allowed / 422 no `ticket_updates.evidence_kind='after'`). Actor `null` = system call: skips the authorization check, NOT the evidence check. `App\Events\TicketStatusChanged($ticket, ?string $from, string $to)` fires at the END of `changeStatus`, `assign` and `declineByTechnician` (after notifications).
- **`createManual` silent mode.** `createManual(array $attrs, ?int $actorId = null, array $options = [])`: `['silent' => true]` skips `notifyDepartmentManager` and `autoAssign` (the `created` log row is still written). Plan F must set imported closed tickets' status via attributes, not `changeStatus`, or add an `after` evidence row first.
- **API paths now enforce the status cycle.** `sendForApproval`, `approve`, `reject`, `deptSetStatus` now enforce `Ticket::canTransition` (422 'انتقال غير مسموح في دورة الحالة.'). CONSEQUENCE for Plan E/G: the Flutter department-manager screen (`department_requests_screen.dart`) relied on jumps outside the cycle (Start: open->in_progress; Done: ->closed without after photo; Reject from non-waiting statuses) and will now get 422 until updated.
- **Locale of denial messages.** Denial messages use `dt()`; the API has no SetLocale middleware, so they follow `APP_LOCALE`.
- **Seeder re-runs.** `ObservationSetupSeeder` is `updateOrCreate`: re-running re-applies seeded labels/risk/SLA/responsible (keeps `active`, `usage_count`, admin-created rows, admin branch->area moves). Run on live once; re-run only to re-apply the catalog intentionally. Dev DB needed `--class=DepartmentSeeder` first (finance/hr/lp/vm/warehouse were missing).
- **RESTRICT foreign keys to `users`/`departments`.** `observation_visits.user_id`, `observations.created_by`, `observation_photos.uploaded_by`, `observation_verifications.verified_by`, `observation_visits.department_id`, `observations.reporting_department_id` are RESTRICT (audit rows keep their author). `Web\UserController@destroy` and `DepartmentController@destroy` have no guard for observations, so deleting a recorder user or a reporting department will throw once observations exist; `observation_categories.department_id` cascades (deleting a department deletes its catalog). Plan D/G: guard both destroy actions ("deactivate instead").
- **NULL-branch rows are invisible to branch/area scopes.** `whereIn(branch_id, …)` excludes NULL, so desk visits/observations with `branch_id = null` ("all branches") are visible only to global and department roles.
- **`active` flags are not applied by the scope.** `User::scopedBranchIds()` ignores `areas.active` and `branches.active`; Plan C must define what deactivating an area means.
- **`TicketStatusChanged` coverage.** Fires from `changeStatus()` (every status), `assign()` (`→ assigned`) and `declineByTechnician()` (`→ rejected`); always after the method's own notifications.
- **Reference race.** `nextReference()`/`nextCode()` are max+1 without a lock; concurrent creates can hit the unique index (`QueryException`) — Plan B/E should retry the insert once.
- **`sales` role.** Not a module role (spec §5 grants it nothing): `viewAny` false, scope empty. Re-adding is a one-line change to `ObservationScope::BRANCH_ROLES` + `User::scopedBranchIds()` if the owner wants sales staff to see their branch's observations.
- **Deploy sequencing.** See DEPLOY_LIVE.md: the Flutter department-manager screen gets 422 on Start/Done/Reject until Plan E's APK ships; owner decision pending (hold deploy vs manager-only jumps in `deptSetStatus`).

## API contract as built (filled in by the Plan B session at hand-off)

> Written by the Plan B session at hand-off (branch `feat/observations-api`, tasks 1–7). Tests: `php artisan test` → 103 tests, 1154 assertions, all green. Listener registration, auto-discovery and deploy notes below. If code and this page disagree, the code wins.

### Deploy checklist (Plan B)

- Run `php artisan migrate` (adds `observations.last_overdue_notified_at`, guarded).
- Set `ANTHROPIC_API_KEY` (optional — without it the Null classifier answers `skipped`).
- Run `php artisan optimize` (or `event:clear` + `event:cache`) so the auto-discovered listener is registered.
- Make sure the scheduler cron runs (`php artisan schedule:run` every minute) for `observations:escalate` at 08:00.
- Add the web host to `SANCTUM_STATEFUL_DOMAINS` for the laptop page.

### API contract

All routes are under `/api`, inside `auth:sanctum`. The Flutter app sends `Authorization: Bearer <token>`. The web page uses stateful Sanctum: the session cookie plus the CSRF header. Clients must send `Accept: application/json`.

Common errors on every route:

| Code | Body | When |
|---|---|---|
| 401 | `{"message": "Unauthenticated."}` | no auth |
| 403 | `{"message": "غير مصرح."}` | policy denied (the service's own AuthorizationException on verify carries its message) |
| 404 | `{"message": "..."}` | unknown id (route-model binding) |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` | Laravel's default validation error. The service's `ValidationException`s use the same shape (e.g. `items.0.responsible_department_id`) |

Dates: `visit_date` and `event_date` are `YYYY-MM-DD`. Every other timestamp is ISO-8601 (`2026-10-22T10:00:00+02:00`). `url` is always `asset('storage/'.path)`.

#### Shared shapes

**Visit item**
```json
{
  "id": 1, "code": "OV-00001", "type": "field", "status": "draft", "source": "mobile",
  "department": {"id": 3, "name": "CCTV"},
  "branch": {"id": 7, "name": "Saraya Mall"},
  "visit_date": "2026-10-22",
  "started_at": "2026-10-22T10:00:00+02:00",
  "checkin_latitude": 30.05, "checkin_longitude": 31.2, "checkin_simulated": false,
  "observations_count": 2, "issues_count": 1,
  "submitted_at": null
}
```
- `type` is `field` or `desk`.
- `status` is `draft` or `submitted`.
- `source` is `mobile`, `web` or `import`.
- `branch` is `null` for a desk visit covering all branches.
- `issues_count` counts the observations with `kind = issue`.

**Observation item**
```json
{
  "id": 10, "reference": "OB-00010", "observation_visit_id": 1,
  "kind": "issue", "status": "open", "classification_status": "ai_pending_review",
  "category_id": 4, "catalog_item_id": null,
  "title": "باب الطوارئ مقفول", "description": "باب الطوارئ مقفول بجنزير",
  "product_or_area": null, "extra_values": null, "rating": null,
  "risk": {"slug": "high", "name_ar": "عالية", "color": "#f97316"},
  "risk_level": {"slug": "high", "name_ar": "عالية", "color": "#f97316"},
  "risk_override_reason": null,
  "responsible_department": {"id": 5, "name": "Operation"},
  "cc_departments": [{"id": 6, "name": "Stock Control"}],
  "branch": {"id": 7, "name": "Saraya Mall"},
  "reporting_department": {"id": 3, "name": "CCTV"},
  "event_date": "2026-10-22",
  "ticket": {"id": 55, "reference": "OPE-0012", "title": "باب الطوارئ مقفول", "status": "open",
             "priority": "high", "due_at": "2026-10-24T10:00:00+02:00", "is_overdue": false},
  "photos": [{"id": 3, "kind": "before", "path": "evidence/observations/1/x.jpg", "url": "http://host/storage/evidence/observations/1/x.jpg"}],
  "repeat_count": 0,
  "created_by": {"id": 9, "name": "موظف كاميرات"},
  "created_at": "2026-10-22T10:05:00+02:00"
}
```
- `risk` and `risk_level` are the same object. Both are `null` when the observation has no risk level.
- `ticket` is `null` until the visit is submitted, and stays `null` for rows that need no ticket. `ticket.is_overdue` is `Ticket::isOverdue()`.
- `photos[].kind` is `before`, `after` or `other`.
- `kind` is `issue`, `activity`, `assessment` or `positive`.
- `status` is one of `Observation::STATUSES`.
- `classification_status` is `catalog`, `manual`, `ai_pending_review` or `ai_reviewed`.
- `rating` is `good`, `average`, `poor` or `null`.

**Pagination meta**
```json
{"current_page": 1, "last_page": 3, "per_page": 30, "total": 75}
```

**Verification item**
```json
{"id": 4, "result": "resolved", "notes": "تمام", "verified_by": {"id": 9, "name": "موظف كاميرات"}, "verified_at": "2026-10-23T09:00:00+02:00"}
```

#### `GET /api/observations/meta?department_id=`
- **Auth:** `can('create', ObservationVisit::class)`, i.e. `isReportingUser()`. Otherwise 403.
- **Query:** `department_id` (optional). It is used only when it is in `reporting_departments`. Otherwise `department_id` falls back to `default_department_id` (`User::defaultReportingDepartmentId()`).
- **`reporting_departments`:** for super_admin, ops_manager, area_manager, branch_director and vm_manager this is every `is_reporting` department, ordered by name. Otherwise it is the user's own department if it is reporting.
- **Response 200** (flat, not wrapped in `data`):
```json
{
  "reporting_departments": [{"id": 3, "slug": "cctv", "name": "CCTV"}],
  "default_department_id": 3,
  "department_id": 3,
  "risk_levels": [{"id": 1, "slug": "critical", "name_ar": "حرجة", "name_en": "Critical", "color": "#dc2626", "requires_field_verification": true}],
  "categories": [{"id": 4, "slug": "emergency", "name_ar": "طوارئ", "name_en": "Emergency", "default_kind": "issue",
                  "risk_level_id": 2, "responsible_department_id": 5,
                  "default_risk_level_id": 2, "default_responsible_department_id": 5}],
  "catalog_items": [{"id": 12, "category_id": 4, "code": "CCTV-EXIT-LOCKED", "label_ar": "باب طوارئ مقفول", "label_en": "Emergency exit locked",
                     "keywords": ["باب", "طوارئ"], "kind": "issue", "risk_level_id": 2, "responsible_department_id": 5,
                     "extra_fields": [{"key": "count", "label_ar": "العدد", "label_en": "Count", "type": "number", "options": null, "required": false}]}],
  "responsible_departments": [{"id": 5, "slug": "operation", "name": "Operation"}],
  "areas": [{"id": 1, "name": "Cairo", "branch_ids": [7, 8]}],
  "branches": [{"id": 7, "name": "Saraya Mall", "area_id": 1, "latitude": 30.0, "longitude": 31.0}]
}
```
- `risk_levels` are the active ones, ordered by `sort_order`.
- `categories` are the active categories of `department_id`, ordered by `sort_order`. `risk_level_id` and `responsible_department_id` are aliases of the two `default_*` keys.
- `catalog_items` are the active items in active categories of `department_id`, ordered by category `sort_order` then item `sort_order`. `keywords` and `extra_fields` are returned as stored (`[]` when null). An item's own `risk_level_id` and `responsible_department_id` can be `null`; the category default then applies.
- `responsible_departments` is every department, ordered by name.
- `areas` are the active areas. `branch_ids` are the branches whose `area_id` points to the area.
- `branches` are the active branches, ordered by name. `name` is `branch_name`.

#### `GET /api/observation-visits`
- **Auth:** `can('create', ObservationVisit::class)`.
- **Rows returned:** the caller's own visits (`user_id`) OR the visits that pass `ObservationScope::apply(…, 'branch_id', 'department_id')`.
- **Query:**
  - `status`: `draft` or `submitted`. Any other value is ignored.
  - `mine=1`: own visits only.
  - `branch_id`, `department_id`.
  - `page`.
- **Order and paging:** `id` descending, 30 per page.
- **Response 200:** `{"data": [<visit item>], "meta": <pagination meta>}`

#### `POST /api/observation-visits`
- **Auth:** `can('create', ObservationVisit::class)`.
- **Body:**
  - `department_id`: nullable | integer | exists:departments,id. It must be one of the user's `reporting_departments` (the same list as `meta`), else 422 `department_id` "لا يمكنك التسجيل باسم هذه الإدارة.". When it is omitted the default is `User::defaultReportingDepartmentId()`.
  - `branch_id`: nullable | integer | exists:branches,id | required_if:type,field
  - `type`: required | in:field,desk
  - `visit_date`: required | date
  - `latitude`, `longitude`: nullable | numeric (stored as `checkin_latitude` / `checkin_longitude`)
  - `simulated`: nullable | boolean (stored as `checkin_simulated`)
  - `source`: nullable | in:mobile,web. Default: `mobile` with a bearer token, else `web`.
  - `general_notes`: nullable | string
- **Response 201:** `{"message": "تم بدء الزيارة.", "visit": <visit item>}`
- **422:** for example a field visit without `branch_id` gives `errors.branch_id` = "الفرع مطلوب للزيارة الميدانية.". A `department_id` the user may not report for gives `errors.department_id`.

#### `GET /api/observation-visits/{visit}`
- **Auth:** `can('view', $visit)`: owner, or the visit passes the scope.
- **Response 200:** `{"visit": <visit item>, "observations": [<observation item>, ...]}`. Observations are ordered by id.

#### `POST /api/observation-visits/{visit}/photos` (multipart)
- **Auth:** `can('update', $visit)`: owner and visit still `draft`.
- **Body:**
  - `photo`: required | image | max:8192 KB (8 MB)
  - `kind`: nullable | in:before,after,other. Default `before`. It is only echoed back (see "Hazards for Plans C–G").
- **Storage:** disk `public`, path `evidence/observations/{visitId}/<hash>.<ext>`.
- **Response 200:** `{"path": "evidence/observations/1/abc.jpg", "url": "http://host/storage/evidence/observations/1/abc.jpg", "kind": "before"}`
- **What to do with `path`:** send it back in `items[].photos[]` of the upsert, or in `photo_path` of verify.

#### `POST /api/observation-visits/{visit}/ai-check`
- **Auth:** `can('update', $visit)`.
- **Body:**
  - `points`: required | array | min:1 | max:50
  - `points.*.index`: required | integer | min:0 | distinct
  - `points.*.text`: required | string | max:2000
- **What it does:**
  - Calls `ClassifierContract::classify($visit->department_id, $visit->branch_id, [[index, text], ...])`.
  - `ObservationService::recordClassifications()` saves ONE `ai_classifications` row per point:
    - `observation_visit_id`, `input_text`.
    - `request_hash = sha1(department_id.'|'.text)`.
    - `suggested`: the matching result, or null.
    - `model`, `status` (the batch status) and `error`.
    - `latency_ms` on every row.
    - `input_tokens` / `output_tokens`: the batch totals on the first row only, null on the others.
- **Response 200 in all three cases** (a classifier failure never fails the request):
```json
{
  "status": "ok",
  "message": null,
  "results": [{"index": 0, "catalog_item_id": 12, "category_id": 4, "kind": "issue", "risk_level": "high",
               "responsible_department_id": 5, "confidence": 0.86, "reason": "سطر واحد", "ai_classification_id": 31}],
  "classification_ids": [31, 32]
}
```
- `status` is `ok`, `failed` or `skipped`. With `failed` or `skipped`, `message` is `"الفحص غير متاح، اختر الفئة يدوياً"` and `results` is `[]`. The client treats any status other than `ok` as "classify manually".
- `classification_ids` has one id per point, in input order. `results` only contains the points the classifier answered.
- Display rule (spec §8): when `confidence ≥ 0.75` and `catalog_item_id` is set, show "قصدك: {item}". Otherwise mark it "مقترح".

#### `POST /api/observation-visits/{visit}/observations` (upsert a draft's observations)
- **Auth:** `can('update', $visit)`.
- **Body:** `{"items": [ {...}, ... ]}`
  - `items`: required | array | min:1
  - `items.*.id`: nullable | integer. Set it to update that row of this visit. A row of another visit gives 422 `items.{i}.id`.
  - `items.*.catalog_item_id`: nullable | integer | exists:observation_catalog_items,id
  - `items.*.category_id`: nullable | integer | exists:observation_categories,id. When both this and `catalog_item_id` are sent and they disagree: 422 `items.{i}.category_id` "الفئة لا تطابق بند الكتالوج".
  - `items.*.kind`: required | in:issue,activity,assessment,positive. Ignored when the catalog item or category sets a kind (precedence: catalog item → category → item).
  - `items.*.risk_level_slug`: nullable | in:critical,high,medium,low,none
  - `items.*.risk_override_reason`: nullable | string | max:1000. The service requires it when the chosen risk differs from the policy risk.
  - `items.*.responsible_department_id`: nullable | integer | exists:departments,id. Ignored when the catalog item or category sets one (precedence: catalog item → category → item). It only matters for free-text rows.
  - `items.*.title`: nullable | string | max:191
  - `items.*.description`: required_without:items.*.catalog_item_id | nullable | string | max:5000. A catalog quick-pick needs no text: the description defaults to the catalog item's `label_ar`.
  - `items.*.product_or_area`: nullable | string | max:191
  - `items.*.extra_values`: nullable | array (object keyed by the item's `extra_fields[].key`)
  - `items.*.rating`: nullable | in:good,average,poor
  - `items.*.photos`: nullable | array of string (max 255 each), the paths returned by the photos endpoint. Paths the row already has are skipped. New ones are stored as `before`.
  - `items.*.photo_source`: nullable | in:camera,gallery. Default `gallery`. Saved as `observation_photos.source` for the new photos of that item.
  - `items.*.cc_department_ids`: nullable | array of integer | each exists:departments,id. Ignored when the catalog item or category has CC departments (precedence: catalog item → category → item). Empty is stored as NULL. Returned as `cc_departments: [{id, name}]`.
  - `items.*.ai_classification_id`: nullable | integer | exists:ai_classifications,id. It must belong to this visit, else 422 `items.{i}.ai_classification_id`. In the same transaction as the upsert, the service links the row (`observation_id`), sets `classification_status = ai_pending_review` (unless a catalog item is chosen), and stores `employee_final = {catalog_item_id, category_id, kind, risk_level (slug), responsible_department_id}` read from the SAVED observation.
- **All or nothing.** Any failing item rejects the whole batch.
- **Response 200:** `{"data": [<observation item>, ...]}`, in input order.

#### `POST /api/observation-visits/{visit}/submit`
- **Auth:** `can('submit', $visit)`: owner and `draft`. A visit that was already submitted gives 403.
- **Body:** none. `SubmitObservationVisitRequest` has no rules; it exists so every POST has a FormRequest.
- **What it does:**
  - Repeat escalation.
  - One ticket for each `issue` and for each `poor` assessment.
  - Copies the before photos to the ticket.
  - Sends the notifications.
- **Response 200:**
```json
{
  "message": "تم إرسال الزيارة.",
  "visit": {"...": "<visit item, status submitted>"},
  "created_tickets": [{"id": 55, "observation_id": 10, "ticket_id": 55, "reference": "OPE-0012",
                       "title": "باب الطوارئ مقفول", "priority": "high", "department": {"id": 5, "name": "Operation"}}]
}
```
- `created_tickets[].id` equals `ticket_id`.
- **422:** one error per ticket-bound item, for example `{"errors": {"items.0.responsible_department_id": ["الإدارة المسؤولة مطلوبة لفتح طلب."], "items.1.branch_id": ["الفرع مطلوب لفتح طلب."]}}`. Here `i` is the row's position in the visit's observations ordered by id.

#### `GET /api/observations`
- **Auth:** `can('viewAny', Observation::class)`: a reporting user or a store/branch manager.
- **Rows returned:** `ObservationScope::apply(Observation::query(), $u)`.
- **Query** (all optional):
  - `branch_id`
  - `status`
  - `risk` (risk slug)
  - `department_id` (= the reporting department)
  - `responsible_department_id`
  - `from` / `to` (on `event_date`, inclusive)
  - `kind`
  - `classification_status`
  - `catalog_item_id`
  - `q` (LIKE on `reference`, `title`, `description`)
  - `page`
- **Order and paging:** `id` descending, 30 per page.
- **Response 200:** `{"data": [<observation item>], "meta": <pagination meta>}`

#### `GET /api/observations/{observation}`
- **Auth:** `can('view', $observation)`.
- **Response 200:**
```json
{
  "observation": {"...": "<observation item>"},
  "photos": [{"id": 3, "kind": "before", "path": "...", "url": "..."}],
  "ticket": {"id": 55, "reference": "OPE-0012", "title": "...", "status": "closed", "priority": "high", "due_at": "...", "is_overdue": false},
  "verifications": [{"id": 4, "result": "resolved", "notes": "تمام", "verified_by": {"id": 9, "name": "..."}, "verified_at": "..."}],
  "classification": {"id": 31, "status": "ok", "suggested": {"...": "§8 shape"}, "employee_final": {"...": "..."}, "admin_final": null}
}
```
- `photos` and `ticket` are repeated from the observation item on purpose.
- `ticket` and `classification` can be `null`.
- `classification` is the LATEST `ai_classifications` row linked to the observation (`hasOne(...)->latestOfMany()`).

#### `DELETE /api/observations/{observation}`
- **Auth:** `can('update', $observation->visit)`: owner and the visit still a draft. Otherwise 403.
- **What it does:** deletes the observation and its photo rows.
- **Response 200:** `{"message": "تم حذف الملاحظة."}`

#### `POST /api/observations/{observation}/verify`
- **Auth:** `can('verify', $observation)`. That is super_admin or ops_manager; or a user whose `department_id` is the observation's reporting department; or an area_manager of the observation's branch.
- **Body:**
  - `result`: required | in:resolved,not_resolved,partially
  - `notes`: nullable | string | max:2000. The service makes it required for `not_resolved` (422 `notes` "اكتب سبب عدم الحل.").
  - `photo_path`: nullable | string | max:255. Stored as an `after` photo.
  - `observation_visit_id`: nullable | integer | exists:observation_visits,id
- **Status changes** (the observation must be `waiting_verification`, else 422 `status`):
  - `resolved` → `verified_closed`
  - `partially` → stays `waiting_verification`
  - `not_resolved` → `reopened`, with a new ticket
- **Response 200:**
```json
{"message": "تم تسجيل التحقق.", "verification": {"...": "<verification item>"}, "observation": {"...": "<observation item, after the change>"}}
```

#### `GET /api/branches/{branch}/observations/pending-verification`
- **Auth:** `can('viewAny', Observation::class)`.
- **Rows returned:** the service applies the scope. The rows are `waiting_verification` at this branch, ordered by id.
- **Response 200:** `{"data": [<observation item>]}`. The list is empty when the caller's scope does not include the branch.

#### `POST /api/login` and `GET /api/me` (additive keys in `user`)
```json
{"user": {"...": "existing keys", "is_reporting_user": true, "reporting_departments": [{"id": 3, "slug": "cctv", "name": "CCTV"}]}}
```
- `reporting_departments` is the same list as in `meta`. It is `[]` when `is_reporting_user` is false.
- The Flutter app shows the Observations tab when `is_reporting_user` is true.

### Names fixed by Plan B (services, classifier, listener, command)

| Class | Public surface | Notes |
|---|---|---|
| `App\Services\Observations\ObservationService` | `createVisit(User, array $data): ObservationVisit`; `upsertObservations(ObservationVisit, array $items, User): Collection`; `deleteDraftObservation(Observation): void`; `submitVisit(ObservationVisit, User, array $options = []): array{visit, created_tickets}` (options `silent`, `due_from` Carbon); `ticketAttributesFor(Observation, User, ?Carbon $dueFrom = null): array`; `syncFromTicket(Ticket, ?string $from, string $to): void`; `verify(Observation, User, string $result, ?string $notes = null, ?string $photoPath = null, ?int $visitId = null): ObservationVerification`; `pendingVerification(Branch, User): Collection`; `reroute(Observation, int $responsibleDepartmentId, User, ?string $reason = null): Observation`; `raiseRisk(Observation, RiskLevel $to, User, string $reason): Observation`; `reportingDepartmentsFor(User): Collection`; `recordClassifications(ObservationVisit, array $points, ClassificationBatch): array` | Constructor `(?RiskResolver, ?TicketService)` — `app(ObservationService::class)` works. Throws `Illuminate\Validation\ValidationException` (422) for rule violations, `AuthorizationException` from `verify`. `reroute`/`raiseRisk` do NOT self-authorize: callers must check `can('reroute', $o)` (Plan C/D). NEVER call `createVisit`/`upsertObservations`/`submitVisit`/`verify` inside a caller `DB::transaction`: they own their transaction and retry once on a duplicate reference (MySQL 1062), which only works outside a caller transaction. |
| `App\Services\Observations\RiskResolver` | `resolve(array $item, ?ObservationCatalogItem, ?ObservationCategory): array{risk_level_id, responsible_department_id, cc_department_ids, sla_hours, kind}` | Pure (no writes). Precedence for risk, responsible, cc and kind: catalog item → category default → item value. Catalog `escalation_rules` (`{field, gte|lte|eq, risk}`) evaluated against numeric `extra_values`. |
| `App\Services\Observations\Ai\ClassifierContract` | `classify(int $departmentId, ?int $branchId, array $points /* [[index,text],...] */): ClassificationBatch` | Bound in `AppServiceProvider::register()`: `AnthropicClassifier` when `config('services.anthropic.key')` is filled, else `NullClassifier` (`status=skipped`). Never throws. |
| `ClassificationBatch` / `ClassificationResult` | readonly DTOs; `ClassificationResult::toArray()` → `{index, catalog_item_id, category_id, kind, risk_level, responsible_department_id, confidence, reason}` | `inputTokens` is the total incl. cache read/creation tokens. |
| `App\Services\Observations\Ai\PromptBuilder` | `system(int $departmentId): string` (cached block, byte-stable), `schema(): array` (object root `{results: [...]}`), `sanitise(array $raw, int $departmentId): ClassificationResult[]` | Confidence min/max are NOT in the JSON schema (structured outputs rejects them); range is in the description and `sanitise` clamps 0..1. |
| `App\Listeners\SyncObservationFromTicket` | `handle(TicketStatusChanged)` | Registered by Laravel 12 **auto-discovery** (NOT `Event::listen`, deviation from the plan text). Deploy: run `php artisan optimize` / `event:cache` rebuild after deploy, or a stale event cache leaves the listener unregistered. Swallows exceptions (`Log::error`) so ticket flows never break. |
| `App\Console\Commands\ObservationsEscalate` | `observations:escalate {--dry-run}`; scheduled `dailyAt('08:00')` in `routes/console.php` | Notifies area manager + active ops managers `observation_overdue` once per 24 h via `observations.last_overdue_notified_at` (migration `2026_10_22_000005_add_overdue_flag_to_observations`). `Observation::overdueOpen()` scope reusable for "متأخر" lists. |
| Config | `config/services.php` → `anthropic.key|model|timeout`; env `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL=claude-opus-5-5`, `ANTHROPIC_TIMEOUT=30` | Empty env strings are not defaulted (`ANTHROPIC_TIMEOUT=` → 0 = no timeout): leave the lines out rather than empty. |
| `bootstrap/app.php` | `$middleware->statefulApi()` added | Spec §9.3: the web page calls the API with the session. The web page MUST send the XSRF token (`X-XSRF-TOKEN` header from the `XSRF-TOKEN` cookie) and the host must be in `SANCTUM_STATEFUL_DOMAINS`. Bearer clients unaffected. |
| `App\Models\Observation` | `classification()` is now `hasOne(...)->latestOfMany()`; `scopeOverdueOpen()`; `last_overdue_notified_at` fillable/cast | Deviations from Plan A text. |

### Notification types added by Plan B
`observation_new` (branch manager; area manager for high/critical), `observation_cc` (cc departments' managers), `observation_escalated` (area manager, repeat escalation at submit), `observation_reopened` (previous assignee on reopen), `observation_overdue` (daily command). The responsible department manager still gets the existing `ticket_new` from `createManual`.

### Business rules as built (spec §7/§9 interpretations)
- Draft observations: `status` = `open` (issue/assessment), `closed_no_action` (activity), `positive` (positive). At submit: good/average assessments → `closed_no_action`; `poor` assessment → `issue`.
- Submit: one ticket per `issue` via `createManual` (`source=observation`, `observation_id`, priority = risk `ticket_priority` or `medium`, `sla_hours` = catalog ?? category ?? risk SLA, `due_at = now()+sla`, `category` = catalog code or category slug). Before photos copied as ticket evidence `before`. `usage_count` +1 per catalog observation. Per-item 422 (`items.{i}.responsible_department_id` / `items.{i}.branch_id`) before any write; `{i}` = position in the visit's observations ordered by id.
- Repeat escalation: `repeat_count` = other observations in the same branch with the same catalog item (or same category when free) in the last 30 days (same-visit rows included); `>= 2` → one level up (`escalated_from_risk_level_id`), at most ONCE per observation. SLA after escalation keeps an explicit item/category SLA (owner decision pending).
- Ticket → observation: `assigned|on_the_way|in_progress` → `in_progress`; `closed` → `waiting_verification` when the risk `requires_field_verification`, else `verified_closed` + automatic verification (`verified_by` = closing actor). Only the CURRENT ticket (`observation.ticket_id`) moves the observation.
- Verify: only from `waiting_verification`; `resolved` → `verified_closed`; `partially` → unchanged; `not_resolved` (notes required) → `reopened`, `repeat_count+1`, new ticket titled `إعادة فتح: …` with `reopen_count+1`, previous assignee notified. Observation stays `reopened` until the new ticket closes.
- Visit `department_id` must be one of `reportingDepartmentsFor(user)`: global roles (super_admin, ops_manager, area_manager, branch_director, vm_manager) → any `is_reporting` department; department users → their own reporting department only.

### Hazards for Plans C–G (found by Plan B)
- `orWhere(fn ($q) => ObservationScope::apply($q, ...))` collapses for global roles (the scope adds no clause and Laravel drops the empty group → only the other branch of the OR applies). Use `orWhereIn('id', ObservationScope::apply(Model::query()->select('id'), ...))` instead (see `ObservationVisitController@index`).
- `Observation::classification()` returns the latest `ai_classifications` row; several rows can share an `observation_id` after repeated ai-checks.
- Photo upload `kind` is only echoed; the upsert stores item photos as `before`. Verification photos: upload through the observation's own visit (`POST /observation-visits/{visit}/photos` with `kind=after`) then pass `photo_path` to `verify` — the visit policy `update` requires the uploader to be the visit owner AND the visit to be a draft, so a verifier who did not record the visit currently has NO upload route (Plan D/E must add one, e.g. `POST /observations/{id}/photos`).
- `meta.responsible_departments` lists inactive departments too; `meta.areas[].branch_ids` includes inactive branches while `meta.branches` lists active only.
- `upsertObservations`/`deleteDraftObservation` do not lock the visit; a concurrent submit can race with an upsert (submit itself locks).
- `AnthropicClassifier` has never been called against the live API in this environment (no key). SDK 1.x sends the key as `Authorization: Bearer`. Run the tinker smoke test from the plan once `ANTHROPIC_API_KEY` is set.
- Empty drafts (zero observations) submit successfully with no tickets.
- `due_from` option of `submitVisit` must be `\Carbon\Carbon` (not `CarbonImmutable`).
- Repeat escalation at reopen sends no `observation_escalated` notification (only at submit).
- `reroute` writes the ticket status directly (no `TicketStatusChanged`) and treats any non-closed ticket as reroutable.

### Deviations from the plan text (Plan B)
- Listener registered by auto-discovery, not `Event::listen`.
- `PromptBuilder::schema()` root is an object `{results: [...]}`; no `minimum`/`maximum` on `confidence`.
- `meta` has additive keys `branches[{id,name,area_id,latitude,longitude}]`, `department_id`, `default_department_id`; observation item carries `risk` AND `risk_level` (same object), `observation_visit_id`, `category_id`, `catalog_item_id`, `rating`, `risk_override_reason`; visit item carries `started_at`, `checkin_*`, `source`, `issues_count`; `created_tickets[]` enriched to `{id, observation_id, ticket_id, reference, title, priority, department{id,name}}`.
- `createVisit` data keys are `checkin_latitude`/`checkin_longitude`/`simulated` (the API maps `latitude`/`longitude`/`simulated`).
- `items.*.description` is `required_without:items.*.catalog_item_id`.
- `submit` has a (rule-less) `SubmitObservationVisitRequest`; `DELETE` has no FormRequest.
- `ai-check` always returns HTTP 200 with `status` ok|failed|skipped and `message` (Arabic) for non-ok; `ai_classifications` token counts are stored on the first row of the batch only.

## Admin web as built (filled in by the Plan C session at hand-off)

> Branch `feat/observations-admin-web`, tasks 1–6. Tests: 126 passed (1436 assertions) with `php artisan test`. Controllers live in `App\Http\Controllers\Web\Observations\`, FormRequests in `App\Http\Requests\Observations\`, views under `resources/views/dashboard/observations/{areas,risk-levels,categories,catalog,review}/`.

### Routes (all in `routes/observations-admin.php`, required once from the `auth` group of `routes/web.php`)

| Method | Path | Name | Policy |
|---|---|---|---|
| GET / POST | `/admin/areas` | `obs.areas.index` / `.store` | `manageAreas` |
| PUT / DELETE | `/admin/areas/{area}` | `obs.areas.update` / `.destroy` | `manageAreas` |
| POST | `/admin/areas/{area}/branches` (`branch_ids[]` = the full list) | `obs.areas.branches` | `manageAreas` |
| GET | `/admin/risk-levels` | `obs.risk.index` | `manageRiskLevels` |
| PUT | `/admin/risk-levels/{riskLevel}` | `obs.risk.update` | `manageRiskLevels` |
| GET / POST | `/admin/observation-categories` (`?department_id=`) | `obs.categories.index` / `.store` | `manageCategory` |
| POST | `/admin/observation-categories/reorder` (`ids[]`) | `obs.categories.reorder` | `manageCategory` per row |
| PUT / DELETE | `/admin/observation-categories/{category}` | `obs.categories.update` / `.destroy` | `manageCategory` |
| GET / POST | `/admin/observation-catalog` (`?department_id=&category_id=&q=`) | `obs.catalog.index` / `.store` | `manageCategory` |
| PUT / DELETE | `/admin/observation-catalog/{item}` | `obs.catalog.update` / `.destroy` | `manageCategory` |
| POST | `/admin/observation-catalog/{item}/toggle` | `obs.catalog.toggle` | `manageCategory` |
| GET | `/observations/review` (`?department_id=`) | `obs.review.index` | super_admin or manager of a reporting department |
| PUT | `/observations/review/{observation}` | `obs.review.update` | `review` |
| POST | `/observations/review/{observation}/approve` / `merge` / `promote` | `obs.review.approve` / `.merge` / `.promote` | `review` (+ `manageCategory` for promote) |
| POST | `/observations/review/bulk-merge` (`observation_ids[]`, `catalog_item_id`) | `obs.review.bulk` | `review` per row |

Sidebar: one `@include('dashboard.observations.partials.admin-nav')` in `layouts/app.blade.php` (before Purchasing). The partial shows "إعدادات الملاحظات" to super_admin and managers of reporting departments; areas and risk levels to super_admin only. Operational links (`/observations`, dashboard, before-after) are Plan D's.

### Behaviour decisions

- **Areas.** Saving an area or its branches re-syncs `branch_user` for every affected manager (previous and new manager, and managers of areas the branches left) to exactly the branches of the areas they manage (spec decision 13). A manager who no longer manages any area ends with no `branch_user` rows. Deleting an area is refused while it has branches. **Deactivating an area** only hides it from pickers (API `meta.areas` lists active ones); its branches keep `area_id` and the area manager's scope is unchanged.
- **Risk levels.** Only `name_ar, name_en, color, default_sla_hours (>= 1 or empty = no deadline), requires_field_verification, active` are written; `slug`, `sort_order`, `ticket_priority` are ignored even if posted.
- **Categories.** `slug` = `Str::slug(name_en, '_')`, unique per department, immutable after create. The department of an existing category never changes. Destroy is refused (flash) when the category has items or observations. Empty cc is stored as NULL.
- **Catalog items.** `code` is upper-cased, `^[A-Z0-9][A-Z0-9_\-]*$`, unique, editable. `extra_fields` rows are stored as `{key, label_ar, label_en, type, options|null, required}`; `escalation_rules` rows as `{field, gte|lte|eq: value, risk}` (RiskResolver's shape; integral values stored as int). Destroy is refused when `usage_count > 0` or an observation references the item; `toggle` flips `active`. A manager may not move an item into another department's category.
- **Review queue.** `App\Services\Observations\ClassificationReviewService` (`approve`, `update`, `merge`, `promote`, static `snapshot`). Every decision writes `admin_final` (same keys as `employee_final`) on `Observation::classification()` (the latest row), sets `classification_reviewed_by/at`, and `classification_status` = `ai_reviewed` (approve/update) or `catalog` (merge/promote). Responsible-department changes go through `ObservationService::reroute` (note prefixed `إعادة توجيه من مراجعة التصنيف`); raising the risk goes through `ObservationService::raiseRisk`; **lowering** the risk is done in the review service (priority + SLA + `priority` log row), since ObservationService only raises. Closed tickets are left alone. `kind` is never changed by the review. Merge increments `usage_count` only when the observation was not already linked to that item; a category/item of another department is a 422 on `category_id` / `catalog_item_id`. Promote creates the item (no extra fields / rules; add them from the catalog screen) then merges.

### Hazards for Plan D / G

- `TranslationSeeder` got a block of `obs.*` rows (group `obs`) appended at the end of `$rows`; Plan D adding rows at the same place will conflict trivially. Dynamic keys `obs.kind.{issue|activity|assessment|positive}` are seeded.
- The web pages assume `ObservationSetupSeeder` has run (risk levels / categories); the screens show an empty-state hint otherwise.
- Blade `@json(...)` breaks on any comma inside the expression; the views precompute arrays in `@php` blocks first.
- No browser smoke test was run in this session (feature tests assert 200 on every index page).

## Ops web as built (filled in by the Plan D session at hand-off)

> Branch `feat/observations-ops-web`, tasks 0, 0b, 1–6 plus a whole-branch fix wave; master (Plans C + F) merged in before the final review. Tests: `php artisan test` → 221 passed on the merged tree. Controller `App\Http\Controllers\Web\Observations\ObservationController` (`index, export, show, create, beforeAfter, dashboard, reroute, raiseRisk, closeNoAction`), filters `App\Support\ObservationFilters`, metrics `App\Support\ObservationMetrics`, labels `App\Support\ObservationLabels`, views under `resources/views/dashboard/observations/` (`index, show, new, before-after, dashboard`, partials `nav, status-pill, risk-badge`), JS `public/js/observations-visit.js` (vanilla, no build).

### Routes (in `routes/web.php`, `auth` group)

| Method | Path | Name | Gate |
|---|---|---|---|
| POST | `/login/quick` (`email`) | `login.quick` | guest; 404 unless `config('app.demo_login')`; email must be in `DemoUsersSeeder::users()` |
| GET | `/api/demo-users` (`routes/api.php`, no auth) | — | 404 unless `demo_login`; `{data:[{email,name,role,hint}]}` |
| POST | `/demo/generate`, `/demo/wipe` | `demo.generate` / `demo.wipe` | super_admin only (403); overview buttons behind Bootstrap modals |
| GET | `/observations` (`?range=today|3d|week|month|custom|all&from&to&reporting_department_id&responsible_department_id&area_id&branch_id&category_id&risk&status&kind&classification_status&outside_catalog=1&q`) | `observations.index` | `viewAny` + `ObservationScope` |
| GET | `/observations/export.csv` (same query) | `observations.export` | same query builder as index |
| GET | `/observations/new` (`?visit={id}` resumes an own draft) | `observations.create` | `create` on `ObservationVisit`; `?visit` needs `update` (owner + draft) |
| GET | `/observations/before-after` (`?branch_id&status`) | `observations.beforeAfter` | `viewAny`; branch must be in the user's scoped list |
| GET | `/observations/dashboard` (same filters as the register) | `observations.dashboard` | `viewAny` + scope |
| GET | `/observations/{observation}` | `observations.show` | `view` |
| POST | `/observations/{observation}/reroute` (`responsible_department_id`, `reason?`) | `observations.reroute` | `reroute` |
| POST | `/observations/{observation}/raise-risk` (`risk_level_slug`, `reason`) | `observations.raiseRisk` | `raiseRisk` (new ability, same roles as reroute) |
| POST | `/observations/{observation}/close-no-action` (`reason`) | `observations.closeNoAction` | `closeNoAction` |
| * | `/web-api/…` | `web-api.` | Plan B's 13 API routes mounted again under the web `auth` group (session + CSRF); the laptop page calls them with `Accept: application/json`, `X-CSRF-TOKEN`, same-origin credentials. `/api/*` is unchanged. |

Sidebar: `@include('dashboard.observations.partials.nav')` directly above Plan C's `admin-nav`. Section shown when `can('viewAny', Observation::class)`; "زيارة ملاحظات جديدة" when `can('create', ObservationVisit::class)`; the dashboard link is hidden for store/branch managers and department employees (spec §10). Strings `obs.nav.section|register|new_visit|before_after|dashboard` appended to the `obs` block of `TranslationSeeder`.

### Behaviour decisions (Plan D)

- **Register/CSV/before-after/dashboard list only observations of SUBMITTED visits** (draft rows are excluded in `ObservationFilters`'s base scope; `GET /api/observations` still returns drafts). `reroute`, `raiseRisk`, `closeNoAction` refuse draft rows (422 `status`).
- **Dates:** filters and metrics use `COALESCE(observations.event_date, observation_visits.visit_date)` so Plan F's undated rows fall on their visit's week; the register shows "تاريخ غير مؤكد" for them. `range=all` (no date bound) exists on the register and dashboard only; `App\Support\DateRange::$presets` is unchanged.
- **Dashboard (spec §12):** `visits` = submitted field visits; `observations_per_visit` divides by ALL submitted visits; `on_time_pct`/`avg_hours`/`closed` exclude tickets with a `cancelled` log row and treat a closed ticket without `due_at` as not on time; **`overdue_now` is a "now" state, never bounded by the period** (tile + list + both week-compare windows; its links use `range=all`); `open_issues` stays range-bound; `repeated_branches` top 20; additive `departments` key for the per-department table.
- **Close without action:** `ObservationService::closeNoAction(Observation, User, string $reason)` → `TicketService::cancel(Ticket, User, string $note)` (sets `closed`/`closed_at`, writes ONE `cancelled` log row, requires `canClose`, bypasses the after-photo rule, fires NO `TicketStatusChanged`) then sets `closed_no_action` + new columns `observations.close_reason, closed_by, closed_at` (migration `2026_10_24_000002`, guarded, additive). Cancelled tickets have `status=closed`, so legacy `KpiController` counts them as closed.
- **Photos from the client:** `UpsertObservationsRequest.photos.*` and `VerifyObservationRequest.photo_path` must match `^evidence/observations/(\d+)/[^/]+\.(jpe?g|png|webp)$` with that visit owned by the caller; uploads accept jpg/jpeg/png/webp only. Observation photo URLs are built only from relative paths.
- **Laptop page:** desk + "كل الفروع" offers only activity-kind catalog items/categories (server refuses tickets without a branch); drafts autosave (debounced, retry with back-off, `beforeunload` guard); desk-multi = one visit per branch, submit stops at the first failure and shows per-visit status; `?visit={id}` resumes an own draft; the "مسوداتي" card lists the caller's drafts.
- **Demo data:** `DemoUsersSeeder` (idempotent; passwords set only on create; never replaces a real area manager nor moves real branches — the demo area manager reaches Saraya + City Stars through `branch_user`, deviation from the table above), `DemoObservationsSeeder` (`import_source='demo'`, purges its own rows first, 7 visits / 19 observations / 12 tickets over the last 9 days covering every status + one `ai_pending_review`; **since Plan H** every historical visit is `submitted` then `approveVisit`-ed by the demo manager of its reporting department (`cctv.manager@levoile.test`; `admin@levoile.test` for departments without a demo manager) so the tickets exist, and ONE CCTV visit by `cctv.emp@levoile.test` (City Stars, yesterday, 3 free-text points) is left `submitted` with an `observation_visit_submitted` notification for the CCTV manager — the owner demos "فحص" then "اعتماد الزيارة" on it right after generating; `summary['pending']` counts it; runs `ObservationSetupSeeder` only when risk levels / catalog are missing; needs catalog codes `CCTV-OPEN-LATE, CCTV-CASH-COUNT-ALONE, CCTV-FIT-OVER-5, CCTV-FLOOR-NO-NAMETAG, CCTV-CAMERA-DOWN, STK-ORG-UNORGANIZED, STK-TRN-COUNT-SHEET, MRC-QLT-MISSING-BUTTON`; one real classifier call per generate when `ANTHROPIC_API_KEY` is set). **The observations demo (users + data) runs only when `config('app.demo_login')` is true**; the legacy checklist generate always runs. `wipe` additionally clears every observation table, `evidence/observations` files and all tickets (including Plan F's import). `ObservationService::applyRepeatEscalation` ignores demo rows when scoring a non-demo observation.

### Hazards for Plan G

- Run `composer install` (Plan F's phpspreadsheet) and `php artisan migrate` after pulling master; `php artisan config:clear` after editing `DEMO_LOGIN`.
- `public/js/observations-visit.js` is not versioned — hard refresh after deploy. Chart.js and Bootstrap come from CDNs.
- Dev DB contains browser smoke-run visits OV-00114..OV-00124 (+ tickets); the wipe button removes them (and the import).
- A web draft can be resumed only by its owner; mobile drafts appear in "مسوداتي" too (same table).
- Register tile links from the dashboard (`kind=issue`) count more rows than the tile for open/on-time; only `overdue` matches exactly (`range=all`).
## Manager approval as built (filled in by the Plan H backend session at hand-off)

> Branch `feat/observations-approval` (worktree `wt-approval`, cut from master c2959b7), tasks 1–6. Tests: `php artisan test` → 181 passed (2122 assertions). If code and this page disagree, the code wins.

### Deploy checklist (Plan H)

- `php artisan migrate`: `2026_10_25_000001_add_approval_to_observation_visits` adds `observation_visits.approved_by` (FK users, nullOnDelete) and `approved_at`, then **backfills**: every visit that is `submitted` with a `submitted_at` becomes `approved` (`approved_by = user_id`, `approved_at = submitted_at`). Rationale: before this migration tickets were created at submit, so every submitted visit (imported, demo, activity-only) is finished work; otherwise the imported October visits would flood the managers' approval queue. Deviation from the plan text ("at least one ticket"): the ticket condition was dropped on purpose.
- Config `config/observations.php` → `manager_approval` (env `OBS_MANAGER_APPROVAL`, default `true`). `false` = pre-Plan-H behaviour (tickets at submit, visit approved by the submitter).
- `php artisan optimize` after deploy (routes / config cache).

### Status lifecycle

`observation_visits.status`: `draft` → `submitted` (awaiting the reporting department's manager, **no tickets**) → `approved` (tickets created, `approved_by/at` set). `ObservationVisit::STATUSES = ['draft','submitted','approved']`; relation `approver()`. `status == 'approved'` ⇔ the tickets exist. There is no `rejected`.

**Callers that used `submitVisit()` to get tickets (Plan D demo seeder, any command) must now call both:**

```php
$svc->submitVisit($visit, $recorder, ['silent' => true]);                           // marks submitted, no tickets
$svc->approveVisit($visit->fresh(), $manager, ['silent' => true, 'due_from' => $d]); // creates the tickets -> approved
```

`approveVisit` refuses a `draft` (422 `visit` "الزيارة لم تُرسل بعد.") and is idempotent on an approved visit. `ImportWriter` already does this (imported visits end `approved`). With the flag off, `submitVisit` alone still creates the tickets and `approveVisit` afterwards is a no-op, so seeders written this way work under both settings. Policies are not checked inside the service.

### Service surface (`App\Services\Observations\ObservationService`, additive)

| Method | Behaviour |
|---|---|
| `submitVisit(ObservationVisit, User, array $options = []): array{visit, created_tickets}` | Flag on: locks, `status=submitted`, `submitted_at=now`, notifies every active `is_department_manager` of `visit.department_id` except the submitter (`notifications.type = observation_visit_submitted`, `link = /observations/visits/to-approve/{id}`, no ticket), returns `created_tickets: []`. Flag off: the old behaviour in one transaction (422 per item before any write, tickets, `approved` by the submitter). Idempotent for `submitted` / `approved` (returns the linked tickets). Options `silent`, `due_from`. |
| `approveVisit(ObservationVisit, User, array $options = []): array{visit, created_tickets}` | Locks, requires `submitted`, `assertTicketable` (422 `items.{i}.responsible_department_id` / `items.{i}.branch_id`, `i` = position in the visit's observations by id), then per row: catalog `usage_count`+1, good/average assessment → `closed_no_action`, each issue / poor assessment → ticket via `ticketAttributesFor` (same as before; `created_by` = the observation's author, the log row's actor = the approver), before photos copied, `observation_new` / `observation_escalated` / `observation_cc` notifications + `ticket_new` from `createManual`. Sets `approved`, `approved_by`, `approved_at`. Idempotent. Options `silent`, `due_from`. |
| `classifyVisit(ObservationVisit, User, ?ClassifierContract = null): array{status, message, observations}` | Visit not approved (else 422). Points = the visit's observations with `catalog_item_id IS NULL`, text = `description`, index = position. Calls the bound classifier once; one `ai_classifications` row per point **with `observation_id`** (batch status / model / error / latency on every row, token totals on the first). Batch `ok`: each answered row gets `responsible_department_id` (when given), `risk_level_id` (by slug), `category_id` (only a category of the visit's department) and `classification_status = ai_pending_review`; `catalog_item_id` and `kind` are never written. `failed` / `skipped`: observations untouched, `message = ObservationService::AI_UNAVAILABLE` ("الفحص غير متاح، اختر الفئة يدوياً"). No free points → `ok` without a classifier call. `observations` = the visit's rows (models, `ObservationItem::WITH` loaded). |
| `updateClassification(Observation, array $data, User): Observation` | Visit must not be `approved` (422 `visit`). Keys, each optional, `null` clears: `responsible_department_id`, `risk_level_slug` (unknown slug → 422), `category_id` (another department's → 422 `category_id`; moving away from the row's catalog item unlinks it and sets `manual`), `description` (a title derived from the old text follows it), `kind` (re-applies the draft status rules: positive → `positive`, activity → `closed_no_action`, else `open`). `classification_status` is otherwise left as is (the review queue of Plan C still learns from the manager's edits). |
| `ObservationService::AI_UNAVAILABLE` | public const, also used by `ai-check`. |

`App\Support\ObservationScope::approvableDepartmentIds(User): ?array` (null = every reporting department for super_admin / ops_manager, `[id]` for the manager of a reporting department, `[]` otherwise) and `approvesDepartment(User, ?int): bool`.

`App\Services\TicketService` (additive): `REPLY_STATES = ['working','solved']`; `canReply(Ticket, User): bool` (users whose `department_id` = the ticket's, plus super_admin / ops_manager); `reply(Ticket, User $actor, string $state, string $note, ?string $photoPath = null): Ticket`. `working`: if the ticket is `open` or unassigned → `assign()` (to the actor when nobody holds it), then `assigned → on_the_way → in_progress` through `changeStatus` (a log row per step; intermediate rows carry "رد الإدارة: جاري العمل عليها", the last one the note); already `in_progress` → one `comment` log row with the note. `solved`: same chain, then `photo_path` recorded as `after` evidence (skipped when that path is already on the ticket), then `waiting_approval` with the note (`resolved_at`, `ticket_approval` notification to the creator). Throws `AuthorizationException` (other department), `ValidationException` (`state`, `note` < 3 chars), `TicketTransitionDenied` 422 when the ticket is `waiting_approval` or `closed`. Transaction + row lock. `TicketStatusChanged` fires per step, so the observation follows (`in_progress`).

### Policies (additive)

`ObservationVisitPolicy`: `supervise(User, ObservationVisit)` = `ObservationScope::approvesDepartment($u, $visit->department_id)` (no status check); `approve` and `classify` = `supervise` AND `status === 'submitted'`. `ObservationPolicy@updateClassification(User, Observation)` = visit status not `approved` AND `approvesDepartment($u, $o->reporting_department_id)`. Call forms: `can('approve'|'classify'|'supervise', $visit)`, `can('updateClassification', $observation)`.

### API contract (additive to Plan B; all under `auth:sanctum`)

- **Visit item** gains `pending_points` (int: `issue` rows without `responsible_department_id`), `approved_at` (ISO-8601 or null), `approved_by` (`{id, name}` or null). `status` may be `approved`.
- **Observation item** gains `visit_status` (`draft` | `submitted` | `approved`) and `can_edit_classification` (bool for the current user = `can('updateClassification')`).
- `POST /api/observation-visits/{id}/submit` → unchanged auth (owner + draft). Response `{message, visit (status submitted), created_tickets: []}` with the flag on; message "تم إرسال الزيارة وهي بانتظار اعتماد المدير.". Flag off: as before, `visit.status` is `approved`.
- `GET /api/observation-visits?scope=to_approve` → submitted visits of the departments the user approves for (`approvableDepartmentIds`; ops / super see all). `status=` is ignored for this scope (always `submitted`), the other filters (`branch_id`, `department_id`, `page`) still apply. Auth: a reporting user OR an approver; a non-reporting department's manager gets 403; a department employee gets an empty list. Same `{data, meta}` shape.
- `POST /api/observation-visits/{id}/classify` (no body) → `can('classify')` (403 for non-managers and for drafts). Always HTTP 200: `{status: ok|failed|skipped, message: null|"الفحص غير متاح، اختر الفئة يدوياً", visit: <visit item>, observations: [<observation item>...]}` (every observation of the visit, ordered by id; `visit` is additive to the plan contract so the client can refresh `pending_points`).
- `PUT /api/observations/{id}` body `{responsible_department_id?, risk_level_slug?, category_id?, description?, kind?}` (`UpdateObservationRequest`: each nullable; `risk_level_slug` in `RiskLevel::SLUGS`, `kind` in `Observation::KINDS`, ids must exist) → `can('updateClassification')` (403 after approval). Response `{message, observation: <observation item>}`; 422 `category_id` for another department's category.
- `POST /api/observation-visits/{id}/approve` (no body) → `can('approve')`; an already approved visit answers 200 for a supervising manager (idempotent, same tickets), 403 for others; a draft → 403. Response `{message: "تم اعتماد الزيارة.", visit (status approved), created_tickets: [{id, observation_id, ticket_id, reference, title, priority, department{id,name}}]}` (same row shape the old submit returned). 422 `items.{i}.responsible_department_id` / `items.{i}.branch_id` and nothing written.
- `POST /api/tickets/{id}/reply` body `{state: working|solved, note (required, min 3, max 2000), photo_path? (max 255), photo? (image, web form only)}` (`ReplyTicketRequest`) → 403 `{"message":"غير مصرح."}` unless `canReply`; 422 from the FormRequest or `{"message": "انتقال غير مسموح في دورة الحالة."}` when the ticket is already `waiting_approval` / `closed`. Response `{message, ticket: <TicketController::item(full)>}` (`ticket.status` = `in_progress` or `waiting_approval`, `assigned_to` set). The mobile uploads the after photo first with `POST /api/tickets/{id}/evidence` (`kind=after`) — then `photo_path` may be omitted or repeated (deduplicated) — or passes any `path` returned by an upload endpoint.
- The fine-grained ticket endpoints (`status`, `send-for-approval`, `approve`, `reject`, `assign`, `departments/ticket/{id}/status`) are unchanged.

**Added at the D + H merge for the mobile app (`tests/Feature/Observations/MobileContractTest.php`):**

- Every ticket item of `Api\TicketController` (`show.ticket`, `mine`, `raised`, department boards, the `reply` / `status` responses) carries `source` (`visit` | `observation` | …) and `observation_id` (int or null); the app shows the reply buttons only when `source == 'observation'`.
- The visit item carries `user: {id, name}` (the recorder; eager-loaded `user:id,name`), next to `approved_by: {id, name} | null`.
- `POST /api/tickets/{id}/evidence` answers `{message, url, path}`; `path` is the relative public-disk path to pass as `photo_path` to `POST /tickets/{id}/reply`.
- Free-point upsert: an item with neither `catalog_item_id` nor `category_id` and `kind` issue (or omitted) is saved with `category_id` = the reporting department's category with slug `other` (null when the department has none). The category is a label only: its policy defaults (risk, responsible, cc, kind) are NOT applied, so the row keeps the employee's own values and still counts in `pending_points`. Positive / activity / assessment free points stay uncategorised.
- `GET /observations/meta → branches[]` has no `name_ar`: the `branches` table has a single `branch_name` column; the app falls back to `name`.

### Admin web (`routes/observations-approval.php`, required once from the `auth` group of `routes/web.php`)

| Method | Path | Name | Policy |
|---|---|---|---|
| GET | `/observations/visits/to-approve` (`?department_id=` for ops / super) | `obs.approval.index` | `approvableDepartmentIds !== []` else 403 |
| GET | `/observations/visits/to-approve/{visit}` | `obs.approval.show` | `supervise` (approved visits stay readable, read-only, tickets linked) |
| POST | `/observations/visits/to-approve/{visit}/classify` | `obs.approval.classify` | `classify` |
| PUT | `/observations/visits/to-approve/{visit}/observations/{observation}` (`UpdateObservationRequest`) | `obs.approval.observation` | `updateClassification` (+ 404 when the point is not the visit's) |
| POST | `/observations/visits/to-approve/{visit}/approve` | `obs.approval.approve` | `approve`; a 422 from the service is flashed as `$errors` + `status` |
| POST | `/tickets/{ticket}/reply` (`ReplyTicketRequest`, multipart `photo` stored under `evidence/tickets/{id}/`) | `tickets.reply` | `TicketService::canReply` (403) |

Controllers: `App\Http\Controllers\Web\Observations\ApprovalController`, `Web\TicketController@reply`. Views: `resources/views/dashboard/observations/approval/{index,show}.blade.php`, strings with `dt()` (no `TranslationSeeder` rows, so no conflict with Plan D). Sidebar: the `admin-nav` partial shows a "اعتماد الزيارات" label + link to managers of reporting departments, ops_manager and super_admin (`approvableDepartmentIds !== []`), independently of the settings block. Ticket show page (`dashboard/tickets/show.blade.php`): a "رد الإدارة المسؤولة" card with a mandatory note, optional photo and the two buttons "جاري العمل عليها" / "تم الحل" for `canReply` users while the ticket is not `waiting_approval` / `closed` (`$canReply` is passed by `Web\TicketController@show`).

### Notification types added by Plan H

`observation_visit_submitted` (managers of the reporting department, `ticket_id` null, `link` to the web approval page). Approval reuses Plan B's `ticket_new` / `observation_new` / `observation_escalated` / `observation_cc`; the reply reuses `ticket_assigned` and `ticket_approval`.

### Tests (Plan H)

`tests/Feature/Observations/{ApprovalSchemaTest,ApprovalServiceTest,ApprovalApiTest}.php`, `tests/Feature/Observations/Web/ApprovalTest.php`, `tests/Feature/Tickets/ReplyTest.php`. The pre-Plan-H tests that expect tickets at submit (`ObservationSubmitTest`, `ApiTest::test_full_mobile_flow`, `ApiTest::test_submit_returns_422_per_item_without_responsible`) set `config(['observations.manager_approval' => false])` and now expect `visit.status === 'approved'`; `ImportWriterTest` expects `approved`; the `VerificationTest` / `ApiTest` helpers call `approveVisit` after `submitVisit`.

### Deviations from the plan text (Plan H)

- Backfill marks every old `submitted` visit with a `submitted_at` as `approved`, with or without tickets (see deploy checklist).
- Flag off: the visit ends `approved` (by the submitter), not `submitted`, so `approved` always means "tickets exist".
- `classify` response carries an additive `visit` key; `to_approve` ignores `status=`.
- `PUT /observations/{id}` never changes `catalog_item_id` except to unlink it when the category moves; `classify` never writes `catalog_item_id` or `kind` (the manager uses PUT, or the Plan C review queue merges into a catalog item).
- `canReply` also admits super_admin / ops_manager.
- `approve` answers 200 (idempotent) to a supervising manager on an already approved visit instead of 403.

### Hazards for the mobile / Plan D / G

- A department manager who submits their own visit must still approve it (no self-approval shortcut); they are not notified about their own submission.
- The Plan D demo seeder calls `approveVisit` after `submitVisit` to get tickets (done at the merge of Plans D + H; see "Status lifecycle" and the Plan D demo-data bullet); with the flag off `submitVisit` alone is enough and the approve call is a no-op.
- `ObservationItem::make` calls `auth()->user()->can('updateClassification')` per row (no extra query: the policy reads the eager-loaded `visit:id,status` and the user's cached department); when the item is serialized outside a request (`auth()->user()` null) the key is `false`.
- The mobile hides the old fine-grained buttons for observation tickets, but the API still accepts them; `reply` on a `rejected` ticket assigns the actor and walks to `in_progress`.
- `GET /observation-visits?status=submitted` (without `scope`) still means "my visits / my scope with status submitted", i.e. the recorder's own pending visits.
