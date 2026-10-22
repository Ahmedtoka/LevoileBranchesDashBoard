# Observations Module — Plan G: Integration, try-out environment, APK, handover

> **For agentic workers:** Use superpowers:executing-plans. This plan runs after A–F are merged into `master`.

**Goal:** The owner opens the dashboard on the laptop, taps a demo user, and walks the whole flow; connects the phone, installs one APK, taps a demo user, and does the same. Everything verified by hand before handover.

**Spec:** design doc + `README-observations.md` § Demo users. Owner requirements (2026-10-22): "جهز المشروع علشان أفتحه وأجرب، جهز الـ APK، جهز كام يوزر أدخل بيهم من شاشة اللوجين على اللاب والموبايل من غير ما أكتب".

## Global Constraints
- Local only. Nothing is pushed or deployed to Cloudways in this plan.
- `DEMO_LOGIN=true` only in the local `.env`; `EnvLive` untouched.
- The phone and the laptop must be on the same Wi-Fi; the server binds `0.0.0.0:8080`.

---

### Task 1: Merge check and database
- [ ] On `master`: `git log --oneline -20` shows the merges of A–F. `composer install` if `vendor` is stale. `php artisan migrate` on `levoile_branches`. `php artisan db:seed --class=ObservationSetupSeeder`, `--class=TranslationSeeder`, `--class=DemoUsersSeeder`. `php artisan test` → green.
- [ ] Set in `.env`: `DEMO_LOGIN=true`, `APP_URL=http://<LAN-IP>:8080`, `ANTHROPIC_API_KEY=` (ask the owner for the key; without it the Null classifier runs and the UI shows "الفحص غير متاح" — say so in the handover).
- [ ] Run the import on the dev DB: `php artisan observations:import --dry-run` then without `--dry-run` (so dashboards are not empty). Commit nothing (data only).

### Task 2: Serve
- [ ] Add `.claude/launch.json` entry `levoile-dashboard`: `php artisan serve --host=0.0.0.0 --port=8080` (port 8080, cwd BranchesDashboard). Start it with the browser preview tool and open `http://<LAN-IP>:8080/login`.
- [ ] Windows firewall: confirm port 8080 is reachable from the phone (`netsh advfirewall firewall add rule name="LeVoile 8080" dir=in action=allow protocol=TCP localport=8080` only with the owner's OK).

### Task 3: Walk the flow on the web (screenshots into `docs/superpowers/plans/handover/`)
1. `cctv.emp` → `/observations/new` field visit on زايد: one catalog item (late opening, 35 min → High), one free text, فحص, confirm, submit → tickets exist.
2. `operation.manager` → ticket board shows the two tickets; assign to `operation.emp`.
3. `operation.emp` (mobile or web ticket page) → in progress → upload after photo → send for approval.
4. `store.zayed` → approve → observation of medium/low closes; High waits for verification.
5. `area.cairo` → verification page → "لسه" → reopened with new ticket; then "اتحلت" on another.
6. `cctv.manager` → `/observations/review` → approve / merge / promote.
7. `ops` → `/observations/dashboard` numbers move; `/observations/before-after`.
8. `admin` → `/admin/areas`, `/admin/observation-catalog` edits reflected in `/api/observations/meta`.
- [ ] Any defect found → fix on `master` in a small commit (`fix(obs): …`) with a test when the plan already has a test file for that area.

### Task 3b: Wipe and regenerate, by hand (owner requirement)
- [ ] As `admin`: press "مسح كل البيانات" → every observations/tickets screen is empty, catalog and demo users still there, quick-login still works. Press "توليد بيانات تجريبية" → dashboard shows numbers, every status appears in `/observations`, review queue has items, verification page has items. Repeat wipe → generate once more and confirm counts are identical. Record counts in the handover note.

### Task 4: APK
- [ ] In `BranchesMobileApp`: `flutter pub get`; `flutter build apk --debug --dart-define=API_BASE=http://<LAN-IP>:8080/api --dart-define=DEMO_LOGIN=true`; copy to `C:\xampp\htdocs\LevoileBranches\build\levoile-observations-debug.apk`.
- [ ] If a phone is connected (`adb devices`), `adb install -r` it and repeat steps 1, 3, 5 of Task 3 on the phone with the quick-login cards. If no phone, say so in the handover and leave the APK ready.

### Task 5: Handover note
- [ ] Write `C:\xampp\htdocs\LevoileBranches\HOWTO-TRY.md` (Arabic): how to start the server (one command), the URL, the demo users table with what to try for each, where the APK is and how to install it, how to rebuild the APK if the laptop IP changes, what needs the Anthropic key, and what is intentionally not done (push to live, FCM).
- [ ] Commit `docs(obs): try-out guide`.
