# Observations Module — Plan B: Service, API and AI classifier

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the business logic (`ObservationService`), the Sanctum API from spec §11, the AI "فحص" classifier, the ticket→observation status sync, and the daily escalation command.

**Architecture:** One service class owns every state change; thin controllers with FormRequests; the classifier sits behind an interface with a `Null` driver so tests and keyless environments work; ticket events from Plan A drive observation status.

**Tech Stack:** Laravel 12, Sanctum, official Anthropic PHP SDK (install and call it per the `claude-api` skill's `php/claude-api/README.md`; do not guess the package or method names), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-22-observations-risk-module-design.md` (§7, §8, §9, §11). **Depends on Plan A** being merged (`docs/superpowers/plans/README-observations.md` lists the fixed names). Branch: `feat/observations-api`.

## Global Constraints

- Same as Plan A. Responses: lists `{data: [...]}`, actions `{message, ...}`; errors `{message}` with 403/422.
- Every write endpoint has a FormRequest in `app/Http/Requests/Observations/`.
- Every endpoint authorizes through the Plan A policies (`$this->authorize(...)` or `Gate`).
- Model default: `config('services.anthropic.model', 'claude-opus-5-5')`; API key `ANTHROPIC_API_KEY`; request timeout 30 s; effort `low`; structured JSON output per the SDK README. Include the server-side `fallbacks: "default"` option the skill describes if the SDK exposes it.
- Never let a classifier failure block saving: endpoints return `{status:'failed', message}` and the client falls back to manual category.

## Review Focus

1. Submitting a visit twice must not create duplicate tickets (Task 3 `test_submit_is_idempotent`).
2. An `issue` observation without a resolvable `responsible_department_id` (free text, agent failed, user picked a category with null responsible) must be rejected at submit with a per-item 422 listing the index (Task 3 `test_submit_rejects_issue_without_responsible_department`).
3. A verification by a user who is neither in the reporting department nor the area's manager must be 403 (Task 5 `test_verify_denied_for_other_department`).
4. Repeat escalation must only count the last 30 days and the same branch (Task 3 `test_repeat_count_window`).
5. Classifier output with an unknown `catalog_item_id` or `risk_level` must be sanitised to null/`medium` rather than crash (Task 4 `test_sanitises_bad_model_output`).

---

### Task 1: ObservationService — visits and drafts

**Files:**
- Create: `app/Services/Observations/ObservationService.php`, `app/Services/Observations/RiskResolver.php`
- Test: `tests/Feature/Observations/ObservationServiceDraftTest.php`

**Interfaces:**
- Produces:
  - `ObservationService::createVisit(User $u, array $data): ObservationVisit` — data keys: `department_id?`, `branch_id?`, `type`, `visit_date`, `checkin_latitude?`, `checkin_longitude?`, `simulated?`, `source` (`mobile|web|import`). Department defaults to `$u->defaultReportingDepartmentId()`. `type=field` requires `branch_id`. Sets `status=draft`, `code=nextCode()`, `started_at=now()`.
  - `ObservationService::upsertObservations(ObservationVisit $v, array $items, User $u): Collection<Observation>` — each item per spec §11 payload; optional `id` updates an existing draft row of this visit; returns saved rows with `status=open` (or `positive`/`closed_no_action` for positive/activity), `classification_status` = `catalog` when `catalog_item_id`, `ai_pending_review` when `ai_classification_id`, else `manual`. Photos: creates `observation_photos` rows `kind=before` from `photos[]` paths. Only while `status=draft`.
  - `RiskResolver::resolve(array $item, ?ObservationCatalogItem $catalog, ?ObservationCategory $category): array{risk_level_id:?int, responsible_department_id:?int, cc_department_ids:array, sla_hours:?int, kind:string}` applying spec §7 order and `escalation_rules` against `extra_values` (`gte`/`lte`/`eq` on numeric fields).
  - `ObservationService::deleteDraftObservation(Observation $o): void`.

- [ ] **Step 1: Write failing tests**: `test_create_field_visit_requires_branch`, `test_create_visit_defaults_department_for_area_manager` (→ operation), `test_upsert_from_catalog_sets_risk_and_responsible`, `test_escalation_rule_raises_risk` (late opening with `minutes_late=35` → high), `test_override_requires_reason` (risk differs from resolved and no `risk_override_reason` → `ValidationException`), `test_upsert_refused_after_submit`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** the two classes. Use DB transactions in `upsertObservations`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): observation service — visits, drafts, risk resolver`

---

### Task 2: AI classifier contract and drivers

**Files:**
- Create: `app/Services/Observations/Ai/ClassifierContract.php`, `NullClassifier.php`, `AnthropicClassifier.php`, `PromptBuilder.php`, `ClassificationResult.php` and `ClassificationBatch.php` (readonly DTOs)
- Modify: `config/services.php` (`anthropic` => `key`, `model`, `timeout`), `app/Providers/AppServiceProvider.php` (bind contract: Anthropic when key present, Null otherwise), `.env.example`
- Test: `tests/Unit/Observations/PromptBuilderTest.php`, `tests/Unit/Observations/NullClassifierTest.php`

**Interfaces:**
- Produces:
  - `ClassifierContract::classify(int $departmentId, ?int $branchId, array $points /* [[index,text],...] */): ClassificationBatch` where `ClassificationBatch{status:'ok'|'failed'|'skipped', results: ClassificationResult[], model:?string, inputTokens:?int, outputTokens:?int, latencyMs:int, error:?string}`.
  - `ClassificationResult{index:int, catalogItemId:?int, categoryId:?int, kind:string, riskLevelSlug:string, responsibleDepartmentId:?int, confidence:float, reason:string}`.
  - `PromptBuilder::system(int $departmentId): string` — builds from DB: categories, catalog items (id, label_ar, label_en, keywords, risk, responsible), risk level definitions, responsible departments list, and the last 40 `ai_classifications` rows of that department with non-null `admin_final` as examples. `PromptBuilder::schema(): array` = JSON schema for the array of results (all fields required, `catalog_item_id` nullable integer, `risk_level` enum of the five slugs, `confidence` number 0..1).
  - `PromptBuilder::sanitise(array $raw, int $departmentId): ClassificationResult[]` — unknown ids → null; unknown risk → `medium`; missing confidence → 0; index must match input.
  - `NullClassifier` returns `status='skipped'`.

- [ ] **Step 1: Write failing tests**: `test_system_prompt_lists_catalog_and_examples` (contains an item label and a previous admin decision text), `test_sanitises_bad_model_output`, `test_null_classifier_skips`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Install the SDK** per the claude-api skill PHP README (`composer require` the official package), then implement `AnthropicClassifier` using the README's client construction and a non-streaming request with structured output; put the stable system prompt in a cached block (`cache_control`) and the points in the user message; parse JSON; call `PromptBuilder::sanitise`. Catch the SDK's typed exceptions and return `status='failed'`.
- [ ] **Step 4: Run** unit tests → PASS. Manual smoke (optional, costs tokens): `php artisan tinker` → `app(ClassifierContract::class)->classify($cctvId, null, [[0,'الموظفة اتأخرت 20 دقيقة عن الفتح']])`.
- [ ] **Step 5: Commit** `feat(obs): AI classifier contract with Anthropic and Null drivers`

---

### Task 3: Submit, tickets, repeat escalation

**Files:**
- Modify: `app/Services/Observations/ObservationService.php`
- Test: `tests/Feature/Observations/ObservationSubmitTest.php`

**Interfaces:**
- Produces: `ObservationService::submitVisit(ObservationVisit $v, User $u, array $options = []): array{visit: ObservationVisit, created_tickets: array}`:
  1. Refuses unless `status=draft` (idempotent: a second call returns the existing tickets without creating).
  2. For each observation: computes `repeat_count` = count of observations in the same branch, same `catalog_item_id` (or same `category_id` when catalog null), `created_at >= now()-30 days`, excluding itself; when ≥ 2 (this one is the third) and risk not null → `escalated_from_risk_level_id = risk`, `risk_level_id = oneAbove()` (if any) and a notification `observation_escalated` to the area manager.
  3. For `kind=issue` (and `assessment` with `rating=poor`, converted to issue): requires `responsible_department_id` and `branch_id`, else per-item 422; creates ONE ticket via `TicketService::createManual([...], $u->id, ['silent' => $options['silent'] ?? false])` with `source='observation'`, `observation_id`, `branch_id`, `department_id=responsible`, `title` (catalog label_ar or first 120 chars), `description` = text + extra values rendered `key: value` lines, `priority` = `riskLevel.ticket_priority`, `sla_hours` = resolver sla, `due_at` = `($options['due_from'] ?? now())->addHours(sla)`, `category` = catalog code or category slug, `created_by=$u->id`. Sets `observation.ticket_id`, `status=open`.
  4. Copies `before` photos onto the ticket via `TicketService::addEvidence($ticket, $path, 'before', $u)`.
  5. Notifies (unless silent): branch manager (`branches.manager_id`) `observation_new`; for high/critical also the area manager; cc departments' managers `observation_cc`.
  6. `catalog_item.usage_count++`; visit `status=submitted`, `submitted_at=now()`.

- [ ] **Step 1: Write failing tests**: `test_submit_creates_one_ticket_per_issue_with_priority_and_due`, `test_submit_is_idempotent`, `test_submit_rejects_issue_without_responsible_department`, `test_activity_and_positive_create_no_ticket`, `test_poor_assessment_becomes_issue`, `test_repeat_count_window` (two prior within 30 days → escalated; one of them 31 days old → not), `test_high_risk_notifies_area_manager`, `test_silent_option_creates_no_notifications`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): submit visits into tickets with repeat escalation`

---

### Task 4: API controllers, requests, routes

**Files:**
- Create: `app/Http/Controllers/Api/ObservationVisitController.php` (`index, store, show, uploadPhoto, aiCheck, upsert, submit`), `app/Http/Controllers/Api/ObservationController.php` (`meta, index, show, destroyDraft, verify, pendingVerification`), FormRequests `StoreObservationVisitRequest`, `UpsertObservationsRequest`, `AiCheckRequest`, `VerifyObservationRequest`, `UploadObservationPhotoRequest`
- Modify: `routes/api.php` (routes exactly as spec §11)
- Test: `tests/Feature/Observations/ApiTest.php`

**Interfaces:**
- Produces: JSON shapes:
  - `meta`: `{reporting_departments:[{id,slug,name}], risk_levels:[{id,slug,name_ar,name_en,color,requires_field_verification}], categories:[{id,slug,name_ar,name_en,default_kind,risk_level_id,responsible_department_id}], catalog_items:[{id,category_id,code,label_ar,label_en,keywords,kind,risk_level_id,responsible_department_id,extra_fields}], responsible_departments:[{id,slug,name}], areas:[{id,name,branch_ids:[]}]}`
  - visit item: `{id,code,type,status,department:{id,name},branch:{id,name},visit_date,observations_count,submitted_at}`
  - observation item: `{id,reference,kind,status,classification_status,title,description,product_or_area,extra_values,risk:{slug,name_ar,color},responsible_department:{id,name},cc_departments:[],branch:{id,name},reporting_department:{id,name},event_date,ticket:{id,reference,status,due_at,is_overdue}|null,photos:[{id,kind,url}],repeat_count,created_by:{id,name},created_at}`
  - `aiCheck` → `{status, results:[{index,catalog_item_id,category_id,kind,risk_level,responsible_department_id,confidence,reason,ai_classification_id}]}`; persists one `ai_classifications` row per point (`observation_visit_id`, `input_text`, `suggested`, `status`, tokens, latency).
  - `upsert` accepts `items[].ai_classification_id` and stores `employee_final` on that row with what the employee kept.
  - Photo upload: stores under `public:evidence/observations/{visitId}` (image ≤ 8 MB), returns `{path, url}`.
  - `GET /api/observations` paginates 30, filters `branch_id,status,risk,department_id,from,to,kind,classification_status,q`; scoped with `ObservationScope::apply`.
  - Also modify `Api\AuthController` (`login`, `me`) to add `reporting_departments: [{id, slug, name}]` (empty array when `!isReportingUser()`) and `is_reporting_user: bool` to the user payload; Flutter (Plan E) reads it to show the Observations tab.

- [ ] **Step 1: Write failing tests** (acting as sanctum users via `Sanctum::actingAs`): `test_meta_returns_user_department_catalog`, `test_full_mobile_flow` (store visit → upload photo → ai-check with Null driver returns skipped → upsert manual item → submit → 1 ticket), `test_upsert_stores_employee_final`, `test_index_is_scoped_for_store_manager`, `test_non_reporting_employee_gets_403`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): observation API endpoints`

---

### Task 5: Verification and ticket→observation sync

**Files:**
- Modify: `app/Services/Observations/ObservationService.php` (`verify`, `syncFromTicket`)
- Create: `app/Listeners/SyncObservationFromTicket.php` (listens to `App\Events\TicketStatusChanged` from Plan A), register in `AppServiceProvider` (`Event::listen`)
- Test: `tests/Feature/Observations/VerificationTest.php`

**Interfaces:**
- Produces:
  - `syncFromTicket(Ticket $t, string $from, string $to)`: `assigned|on_the_way|in_progress` → observation `in_progress`; `closed` → if `riskLevel.requires_field_verification` → `waiting_verification`, else `verified_closed` + auto `observation_verifications` row (`result=resolved`, `verified_by` = closing actor id from the latest `ticket_updates` row, `notes='اعتماد بصورة بعد'`); also copies the ticket's `after` evidence path into `observation_photos` (`kind=after`).
  - `verify(Observation $o, User $u, string $result, ?string $notes, ?string $photoPath, ?int $visitId = null): ObservationVerification`: allowed only when `status=waiting_verification`; `resolved` → `verified_closed`; `not_resolved` → `reopened`, `repeat_count++`, new ticket via `createManual` (same attrs as submit, title prefixed `إعادة فتح: `), link `observation.ticket_id` to the new ticket, escalation check as in Task 3; `partially` → stays `waiting_verification` with the note. Notifies the ticket's department manager on reopen.
  - `pendingVerification(Branch $b, User $u): Collection` = observations `waiting_verification` in that branch visible under scope.
  - `reroute(Observation $o, int $responsibleDepartmentId, User $u, ?string $reason): Observation` — updates observation and, when its ticket is not closed, sets `tickets.department_id`, clears `assigned_to`, logs `status_change` note `إعادة توجيه: {reason}`, notifies the new department manager. Used by Plan C (review update) and Plan D (detail page).
  - `raiseRisk(Observation $o, RiskLevel $to, User $u, string $reason): Observation` — sets `risk_level_id`, `risk_override_reason`, updates open ticket `priority` and `due_at` (`created_at + new sla`), logs `priority`.

- [ ] **Step 1: Write failing tests**: `test_ticket_close_on_low_risk_closes_observation`, `test_ticket_close_on_high_risk_waits_for_verification`, `test_verify_resolved_closes`, `test_verify_not_resolved_reopens_with_new_ticket`, `test_verify_denied_for_other_department`, `test_original_dates_unchanged_after_reopen`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(obs): verification flow and ticket status sync`

---

### Task 6: Daily escalation command

**Files:**
- Create: `app/Console/Commands/ObservationsEscalate.php` (`observations:escalate`)
- Modify: `routes/console.php` (`Schedule::command('observations:escalate')->dailyAt('08:00')`)
- Test: `tests/Feature/Observations/EscalateCommandTest.php`

**Interfaces:**
- Produces: for every open observation whose ticket `isOverdue()` and that has not been flagged in the last 24 h (`ai_classifications` is not the place; add nullable `observations.last_overdue_notified_at` in a new guarded migration `2026_10_22_000005_add_overdue_flag_to_observations.php`): notify area manager and ops managers `observation_overdue`; set the timestamp. Output count.

- [ ] **Step 1: Write failing tests** `test_overdue_notifies_area_and_ops_once_per_day`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5: Commit** `feat(obs): daily overdue escalation command`

---

### Task 7: Hand-off

- [ ] Run `php artisan test` → green. Append to `docs/superpowers/plans/README-observations.md` the JSON shapes from Task 4 (verbatim) under "API contract as built". Commit `docs(obs): API hand-off`.
