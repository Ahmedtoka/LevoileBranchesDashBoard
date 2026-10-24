# Observations import — run report (October 2026 workbook)

Plan F (`2026-10-22-obs-F-import.md`), branch `feat/observations-import`. Run on **2026-10-24 22:27** against the local dev database `levoile_branches` (not live). Source: `database/data/levoile_control_center_oct2026.xlsx` (copy of `StoresAi/LeVoile_Control_Center_Database_FINAL.xlsx`), `import_source = levoile_control_center_oct2026.xlsx`, `import_row` = the `Central Issues` Excel row (2..377).

Task 5 of the plan (Cloudways backup, live copy) was replaced by the owner with: dry run on dev, real run once on dev, this report. **Not run on live.** Before running on live: `php artisan migrate` (adds the guarded migration `2026_10_24_000001_make_observation_event_date_nullable`), then `php artisan observations:import --dry-run`, review, then `php artisan observations:import`.

## Dry run (`php artisan observations:import --dry-run`)

```
| Department    | Visits | Observations | Tickets | Skipped (already imported) |
| cctv          | 57     | 124          | 124     | 0                          |
| finance       | 13     | 16           | 16      | 0                          |
| hr            | 1      | 7            | 0       | 0                          |
| it            | 2      | 7            | 0       | 0                          |
| lp            | 2      | 2            | 0       | 0                          |
| maintenance   | 26     | 85           | 6       | 0                          |
| merchandise   | 5      | 45           | 37      | 0                          |
| stock_control | 7      | 90           | 17      | 0                          |
| TOTAL         | 113    | 376          | 200     | 0                          |
Visit groups in the workbook: 113 (field 52, desk 61).
Branches the real run will create (no area): 6th of October - Mall of Egypt 2, Shehab Store, Gamal Abd El Nasser Store, MDS Store
```

No unknown branches or departments. Nothing needed fixing after the dry run.

## Real run (`php artisan observations:import`, exit 0, ~6 s)

```
| Department    | Visits | Observations | Tickets | Skipped | Risk escalated |
| cctv          | 57     | 124          | 124     | 0       | 57             |
| finance       | 13     | 16           | 16      | 0       | 0              |
| hr            | 1      | 7            | 0       | 0       | 0              |
| it            | 2      | 7            | 0       | 0       | 0              |
| lp            | 2      | 2            | 0       | 0       | 0              |
| maintenance   | 26     | 85           | 6       | 0       | 1              |
| merchandise   | 5      | 45           | 37      | 0       | 9              |
| stock_control | 7      | 90           | 17      | 0       | 0              |
| TOTAL         | 113    | 376          | 200     | 0       | 67             |
```

A second run would skip all 376 rows (verified by `ImportCommandTest::test_import_is_idempotent` on the test database; the dev database was deliberately run once).

## What is in the database now (dev)

| Check | Result |
| --- | --- |
| Visits (`source = import`) | 113: 52 field, 61 desk (57 "undated" branch desks + 4 "All branches" desks); 0 drafts; `user_id` = import user |
| Observations | 376: issue/open 195, issue/waiting_verification 5, assessment/closed_no_action 32, activity/closed_no_action 136, positive 8 |
| `event_date` | 194 dated, 182 null (no verified date and raw date outside the week, or no date at all) |
| Classification | 296 `catalog` (catalog item matched, `usage_count` +296), 80 `manual` (category only or "other") |
| "Other" category rows | maintenance 12 (the 8 "أخري" + 4 all-branches plans), merchandise 1 ("Capacity") |
| Tickets (`source = observation`) | 200: 195 open, 5 closed; 0 assigned (silent); 5 with no `due_at` (unfinished maintenance tasks, risk "none"); **190 already overdue** (events 4–17 Oct, SLA counted from the event date / week end, as spec section 13 asks) |
| Tickets by department | operation 173 (critical 11, high 67, medium 55, low 40), vm 13, warehouse 7, maintenance 6, it 1 |
| Risk levels on observations | critical 12, high 71, medium 93, low 51, none 5 (positives / activities carry no risk) |
| Repeat escalations | 67 rows raised one level by the 30-day repeat rule (same catalog item or category in the same branch), e.g. late opening at City Stars (10), Mall of Egypt (9), Saraya (7); VM display handling at Ezzat Salama (6) |
| Late-opening minutes | 57 `CCTV-OPEN-LATE` rows: 52 with `extra_values.minutes_late` parsed (8 of them ≥ 30 → high by the catalog rule), 5 without a figure |
| Pending Verification (5 Merchandising rows) | OB-00021, OB-00023, OB-00027, OB-00039, OB-00045: observation `waiting_verification`, ticket `closed` with an `after` evidence row `import/placeholder-after.png` and the note "مستورد: تم الإجراء، بانتظار التحقق"; no automatic verification was created |
| Notifications | none created by the import (the 6 rows in the dev table date from June 2026) |
| Import user | `import@levoile.local` ("Import", super_admin, `active = 0`, id 32 on dev) |

### Branches created by the command (owner decision 2026-10-24)

| id (dev) | branch_name | area_id | rows |
| --- | --- | --- | --- |
| 27 | 6th of October - Mall of Egypt 2 (spec section 13 target of "MOE 2"; same name as `RealTeamSeeder`; legacy `area` text "6th of October") | null | 9 |
| 28 | Shehab Store (شهاب / Shehab) | null | 14 |
| 29 | Gamal Abd El Nasser Store | null | 1 |
| 30 | MDS Store (mds) | null | 1 |

All four: city set (Giza / Cairo), `checkin_radius 150`, `active 1`, no `area_id`. The admin should place them in areas (Mall of Egypt 2 belongs with area "6th of October"); until then their observations are invisible to area managers (branch scope is by `area_id`).

## Mapping decisions worth knowing

- Branch aliases: spec section 13 verbatim plus the spellings found in the data (`Mokkatm 2`, `theyard`, `Point90`, `ELNozha`, `جنينه مول`, `عباس2`, …). Any other spelling aborts the whole import before any write and lists the names.
- Departments: CCTV→cctv, Stock Control→stock_control, Maintenance→maintenance, Merchandising→merchandise, Finance→finance, IT→it, HR→hr, Loss Prevention→lp.
- Kind / status: Violation, Issue → issue; Training, Activity / Plan → activity; Maintenance Task → activity when Done, **issue (open, ticket to Maintenance) when "جاري العمل" / "لم يتم الحل"** (6 rows; these Arabic statuses occur only on maintenance tasks and spec section 13 maps them to open + ticket); Assessment → assessment with جيد/متوسط/ضعيف = good/average/poor (poor → issue + ticket at submit, service rule); Positive → positive; Reported Finding → issue with a branch, activity without. Rows with "All branches" are always activities (18 rows: IT 7, HR 7, Maintenance 4) in a desk visit without a branch. Stock Control "Issue" rows graded متوسط/ضعيف (17) are open issues that keep the grade in `rating`.
- Pending Verification → `waiting_verification` + closed ticket (see table above).
- Catalog: exact table from the workbook's category names to the seeded categories (the maintenance trades map straight to their items); items otherwise by keyword hits inside the category. Assessments and positives link the category only (no item). No category match → the department's "other", `classification_status = manual`.
- Responsible department for free rows (category without a default): from the workbook's "Responsible Department" (Operations / Store Operations → operation, "Operations / Loss Prevention" and "Finance / Loss Prevention" → operation with cc lp, Maintenance → maintenance), else operation. Catalog defaults win when present.
- Dates: verified date first; else the raw cell (Excel serial, `d-m-Y`, `d / m / Y`) only when inside the row's week; otherwise `event_date` null, the row goes to a desk visit dated the week start with the note "تاريخ غير مؤكد", and the description records the dropped raw date. `due_at` = event date (or week end when undated) + SLA.
- Visits: `department|branch|event_date` (field); `department|ALL|week_start` and `department|branch|week_start|undated` (desk). The 5 Merchandising visits of `Original Visits` give their start / end times and notes to the matching groups (e.g. Gnena 13 Oct 12:30–15:00). Other visits keep the import time as `started_at` / `submitted_at`.
- Description = finding, "الإجراء: …", merch before/target, notes, "المُبلِّغ: …" (no attempt to link reporters to users), then a line with the source file / row / type / status and any import caveats. Title = finding (120 chars). `product_or_area` from `Original Observations` for Merchandising rows.

## Handover notes

1. **Overdue burst.** 190 imported tickets are already overdue. The scheduled `observations:escalate` (08:00 daily) will notify the area managers and active ops managers about them on its first run after the import (once per 24 h per observation). Decide whether that is wanted before running on live, or run the import and let the owner expect the burst.
2. The 5 placeholder after photos point to `storage/app/public/import/placeholder-after.png` (a 1x1 PNG the writer creates when missing); on live make sure `storage:link` exists.
3. `observations.event_date` is now nullable (guarded migration, `down()` is a no-op). Lists filtering by `from`/`to` on `event_date` will not show the 182 undated rows.
4. `ObservationService::needsTicket()` now returns false for rows already `closed_no_action` (imported "Done" findings). Live drafts never carry that status on an issue, so no behaviour change for them.
5. Re-running the command is safe (skips by `import_source` + `import_row`); `--only=<slug>` imports one department; `--dry-run` writes nothing (not even the four branches).
