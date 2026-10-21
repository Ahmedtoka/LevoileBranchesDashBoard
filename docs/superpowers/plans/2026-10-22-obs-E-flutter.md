# Observations Module — Plan E: Flutter screens

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the observation visit, AI check, branch observations, and verification screens to `BranchesMobileApp`, wired to Plan B's API.

**Architecture:** One `ObservationService` (Dio) + plain Dart models; screens follow the existing pattern (`my_visits_screen.dart` → `visit_detail_screen.dart`), reuse `BranchFilter`, `ui_helpers.dart`, `image_picker` settings (quality 70, maxWidth 1600), and `LocaleProvider.tr(ar, en)` for every string.

**Tech Stack:** Flutter (existing project), dio, provider, image_picker, shared_preferences.

**Spec:** design doc §9.2, §9.5, §10 (Flutter table), §11. **Depends on Plan B's API** (`docs/superpowers/plans/README-observations.md` → "API contract as built"). Until B is live, develop against a local stub: `lib/services/observation_service.dart` gets a `useMock` flag that returns fixtures from `lib/fixtures/observations_mock.dart`. Repo: `C:\xampp\htdocs\LevoileBranches\BranchesMobileApp` (same git root as the dashboard? No: check `git rev-parse`; if not a repo, initialise one on branch `feat/observations`).

## Global Constraints

- Arabic default; every string via `tr(ar, en)`; RTL already handled by the root `Directionality`.
- Camera-first for photos in field visits; gallery allowed.
- Never block on the AI: when `ai-check` returns `status != ok`, show a snackbar and open the manual category picker.
- Keep `api_config.dart` base URL untouched; mock flag defaults to `false`.

## Review Focus

1. Losing connectivity after adding items must not lose them: the draft is upserted after every add and the local list is rebuilt from the server on reopen (Task 3 `test_visit_reopen_restores_server_items` widget test with mock).
2. Submit with a free item lacking a category (AI failed, user skipped) must be stopped client-side with the item highlighted (Task 4).
3. Verification buttons must be disabled while the request is in flight to avoid double reopen (Task 6).
4. Users whose `reporting_departments` list is empty must not see the Observations tab at all (Task 1).
5. Catalog search must match `label_ar`, `label_en` and `keywords` case-insensitively (Task 3 unit test `catalog_search_matches_keywords`).

---

### Task 1: Models, service, home tab

**Files:** `lib/models/observation.dart` (`ObservationMeta, RiskLevel, ObsCategory, CatalogItem, ObservationVisit, Observation, AiResult`), `lib/services/observation_service.dart`, `lib/fixtures/observations_mock.dart`, modify `lib/screens/home_screen.dart`, `lib/models/app_user.dart` (`isReportingUser` getter computed from `/me` extra field `reporting_departments` — ask B to include `reporting_departments:[{id,name}]` in `/api/me` and `/login` user payload; if absent, derive from `meta` call on first open).

**Interfaces:** `ObservationService` methods mirror spec §11: `meta({int? departmentId})`, `visits({String status})`, `createVisit(...)`, `visit(id)`, `uploadPhoto(visitId, File, kind)`, `aiCheck(visitId, List<String> points)`, `upsert(visitId, List<Map> items)`, `deleteDraft(obsId)`, `submit(visitId)`, `observations({filters})`, `observation(id)`, `verify(id, result, notes, photoPath)`, `pendingVerification(branchId)`.

- [ ] Write unit test for JSON parsing of each model from the fixtures. Implement. `flutter test` green. Add "الملاحظات" tab to `home_screen.dart` for every role when `isReportingUser`, and for `department_employee` keep the technician home as first tab. Commit `feat(obs): models, service, home tab`.

### Task 2: ObservationVisitsScreen

**Files:** `lib/screens/observations/observation_visits_screen.dart`

**Interfaces:** tabs مسودات / مرسلة; FAB "زيارة جديدة" → bottom sheet: department (when `meta.reporting_departments.length > 1`), branch (BranchFilter), date default today → `createVisit(type: field, source: mobile)` → opens Task 3 screen.

- [ ] Widget test with mock: list renders codes and counts. Implement. Commit `feat(obs): visits list`.

### Task 3: ObservationVisitScreen + CatalogPickerSheet

**Files:** `lib/screens/observations/observation_visit_screen.dart`, `lib/screens/observations/catalog_picker_sheet.dart`, `lib/screens/observations/extra_fields_form.dart`

**Interfaces:** header with branch/date; check-in button reusing `checkin_screen.dart` logic (geolocator, simulated fallback) → `POST .../checkin` is not in §11, so send coordinates on `createVisit` and show a local "تم الـ check-in" state; list of items (risk pill, title, photo count, classification chip); buttons "من الكتالوج" (sheet: search field, grouped by category, tapping opens `ExtraFieldsForm` + camera, then `upsert`) and "كتابة حرة" (Task 4). Every change calls `upsert`; on open, items come from `visit(id)`.

- [ ] Unit test `catalog_search_matches_keywords`; widget test `test_visit_reopen_restores_server_items`. Implement. Commit `feat(obs): visit screen and catalog picker`.

### Task 4: FreeTextScreen + AiReviewScreen

**Files:** `lib/screens/observations/free_text_screen.dart`, `lib/screens/observations/ai_review_screen.dart`

**Interfaces:** multiline input (one point per line) + photos; "فحص" → `aiCheck` → review list: each row shows suggestion (catalog match "قصدك: …" when `confidence ≥ 0.75` and `catalog_item_id` present, else "مقترح"), risk pill, responsible dept, reason; per-row edit opens pickers (category, risk with reason field, responsible); "تأكيد الكل" → `upsert` with `ai_classification_id` per item → back to visit. On failure: snackbar + manual category picker per line.

- [ ] Widget test: mock returns 3 results → 3 rows, confirm calls `upsert` with 3 items. Implement. Commit `feat(obs): free text and AI review`.

### Task 5: Submit + result

- [ ] In `ObservationVisitScreen`: "إرسال الزيارة" validates (issue items need responsible dept), calls `submit`, shows created tickets list (reference, title, priority) and returns to visits list. Widget test for client-side validation. Commit `feat(obs): submit visit`.

### Task 6: BranchObservationsScreen + VerificationScreen

**Files:** `lib/screens/observations/branch_observations_screen.dart`, `lib/screens/observations/observation_detail_screen.dart`, `lib/screens/observations/verification_screen.dart`

**Interfaces:** branch list (scoped by server), filters status/risk; detail shows before/after photos, ticket status, timeline; `VerificationScreen(branchId)` lists `pendingVerification` with "اتحلت" (optional camera photo) / "لسه" (required reason) → `verify`; buttons disabled while loading; entry points: Observations tab for area_manager/ops/store_manager, and from the visit screen ("متابعة الفرع").

- [ ] Widget tests: reopen requires reason; buttons disabled in flight. Implement. Commit `feat(obs): branch observations and verification`.

### Task 7: Hand-off
- [ ] `flutter analyze` clean, `flutter test` green, build debug APK, smoke on emulator against local server. Commit `chore(obs): polish and strings`.
