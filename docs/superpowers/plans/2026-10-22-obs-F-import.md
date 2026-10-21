# Observations Module — Plan F: Import of the October 2026 control-center data

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `php artisan observations:import` loads the 376 records (plus the 5 original Merchandising visits) from the Excel workbook into observation visits, observations, photos-less tickets, idempotently and silently.

**Architecture:** A command that reads three sheets with PhpSpreadsheet, normalises each row through small mapper classes (branch, department, category/catalog, status/kind), groups rows into visits, and writes through Plan B's `ObservationService` with `silent` and `due_from` options so the same rules apply as live entry.

**Tech Stack:** `phpoffice/phpspreadsheet` (add to composer), Laravel console, PHPUnit.

**Spec:** design doc §13 and §16 (decisions 8, 11, 14, open branch question). **Depends on Plans A and B.** Branch: `feat/observations-import`. Source file: copy `C:\xampp\htdocs\StoresAi\LeVoile_Control_Center_Database_FINAL.xlsx` to `database/data/levoile_control_center_oct2026.xlsx` (commit it; 62 KB).

## Global Constraints

- Idempotent on `(import_source, import_row)`; re-running updates nothing and creates nothing.
- Unknown branch name aborts before any write and prints the distinct unknown names.
- "All branches" rows → desk visit, `branch_id=null`, `kind=activity`, no ticket.
- `created_by` = system user `import@levoile.local` (name "Import", role `super_admin`, `active=false`), created by the command if missing.
- Tickets created with `['silent'=>true, 'due_from'=>$eventDate]`.
- Never touches live data outside the import (no deletes).

## Review Focus

1. Excel serial dates (e.g. `46308`) must convert to real dates and be kept only when inside the row's week; otherwise `event_date` null and the visit is `desk` with the week start (Task 2 `test_raw_serial_date_outside_week_is_dropped`).
2. Composite "Reported By" values and empty ones must not break grouping (Task 3).
3. `Pending Verification` rows must end as `waiting_verification` with a **closed** ticket carrying an `after` placeholder note, not an open one (Task 4 `test_pending_verification_mapping`).
4. Maintenance `Done` tasks must create no tickets (Task 4 `test_done_maintenance_creates_no_ticket`).
5. Running the command twice must produce identical counts (Task 5 `test_import_is_idempotent`).

---

### Task 1: Reader and mappers

**Files:** `app/Services/Observations/Import/WorkbookReader.php` (`rows(string $path): array{issues: Collection, observations: Collection, visits: Collection}`), `BranchMapper.php` (`toBranchId(string $raw): ?int|'ALL'`, throws `UnknownBranch` with the raw name), `DepartmentMapper.php` (`toDepartmentId(string $raw): int`), `KindStatusMapper.php` (`map(string $recordType, string $status, ?string $rating): array{kind, status, rating, creates_ticket:bool, ticket_closed:bool}`), `CatalogMapper.php` (`match(int $departmentId, string $category, string $finding): array{category_id:?int, catalog_item_id:?int}` using exact category name table from spec §14 + keyword match on `keywords`), `DateMapper.php` (`eventDate(?string $verified, ?string $raw, string $weekStart, string $weekEnd): ?Carbon`).
- Tests: `tests/Unit/Observations/Import/MappersTest.php`.

**Interfaces:** the branch alias table is spec §13 verbatim plus the three names the owner decides (ask the session owner before implementing; if unanswered, the mapper lists them as unknown and the command aborts, which is the specified behaviour).

- [ ] Tests for each mapper with the exact strings from the spec (`'سيتي ستارز'`, `'City Stars'`, `'MOE 2'`, `'عباس2'`, `'All branches'`, `'46308'` → 2026-10-13, `'13-10-2026'`). FAIL → `composer require phpoffice/phpspreadsheet` → implement → PASS → Commit `feat(obs-import): workbook reader and mappers`.

### Task 2: Grouping into visits

**Files:** `app/Services/Observations/Import/VisitGrouper.php` (`group(Collection $rows): Collection<array{key, department_id, branch_id|null, type, visit_date, rows[]}>`).
- Key = `department|branch|event_date` for dated branch rows (type `field`); `department|ALL|week_start` for all-branches rows and `department|branch|week_start|undated` for undated branch rows (type `desk`, note "تاريخ غير مؤكد"). The 5 Merchandising visits from the `Original Visits` sheet are matched by branch+date and get `started_at/submitted_at` from Start/End times.

- [ ] Tests `test_groups_by_department_branch_date`, `test_raw_serial_date_outside_week_is_dropped`, `test_merch_visits_get_times`. FAIL → implement → PASS → Commit `feat(obs-import): visit grouping`.

### Task 3: Writer

**Files:** `app/Services/Observations/Import/ImportWriter.php` (`write(array $visitGroup, User $importUser): array{visits:int, observations:int, tickets:int, skipped:int}`), uses `ObservationService::createVisit(source=import)`, `upsertObservations` (sets `import_source`, `import_row`, `event_date`, `classification_status=manual` when no catalog match, `title` = first 120 chars, `description` = Finding + "\n" + Corrective Action + notes), then `submitVisit($visit, $importUser, ['silent'=>true, 'due_from'=>$eventDate ?? $weekEnd])`.
- For `ticket_closed` rows (Pending Verification): after submit, `TicketService::addEvidence($ticket, 'import/placeholder-after.png', 'after', $importUser)` then `changeStatus($ticket,'closed',$importUser,'مستورد: تم الإجراء، بانتظار التحقق')` — `canClose` allows super_admin.
- Skips a row (and counts it) when `(import_source, import_row)` already exists.

- [ ] Tests `test_pending_verification_mapping`, `test_done_maintenance_creates_no_ticket`, `test_all_branches_activity_no_ticket`, `test_skips_existing_rows`. FAIL → implement → PASS → Commit `feat(obs-import): writer`.

### Task 4: Command

**Files:** `app/Console/Commands/ObservationsImport.php` — signature `observations:import {path=database/data/levoile_control_center_oct2026.xlsx} {--dry-run} {--only=}` (`--only=department_slug`). Dry run prints the per-department counts and the unknown branch list without writing. Wraps writing in one transaction per visit group. Prints a summary table.

- [ ] Test `test_import_is_idempotent` (run twice on the real workbook against the test DB seeded with `ObservationSetupSeeder` and the 27 branches from `BranchSeeder` + the owner's three extra branches; counts equal; total observations == 376 + original observations not already present in Central Issues → assert `>= 376`), `test_dry_run_writes_nothing`. FAIL → implement → PASS → Commit `feat(obs-import): import command`.

### Task 5: Run on a copy of live

- [ ] Take a Cloudways backup; download a DB dump; load into local `levoile_branches_livecopy`; run `--dry-run`, review unknowns; run the import; export `php artisan observations:import --dry-run` summary to `docs/superpowers/plans/import-report-2026-10.md`; commit `docs(obs-import): run report`. Do **not** run on live until the owner confirms the report.
