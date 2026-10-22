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

_Plan B appends the JSON shapes from its Task 4 here._
