# LeVoile Branches — Laravel Dashboard & API

Branch Audit, Visit, Checklist, Ticketing, Assignment, Notification & Reporting
backend for LeVoile branches. Serves the Blade admin dashboard **and** the REST
API consumed by the Flutter app (`BranchesMobileApp`).

Stack: **Laravel 11 · MySQL · Sanctum (token auth) · Blade + Bootstrap 5**.

---

## Requirements

- PHP 8.2+ (XAMPP ships this)
- Composer
- MySQL (XAMPP)

## Run it

```bash
cd BranchesDashboard

# 1. install dependencies
composer install

# 2. app key (the .env is already provided; edit DB creds if needed)
php artisan key:generate

# 3. create the database, then migrate + seed demo data
#    (create an empty schema named "levoile_branches" in phpMyAdmin first)
php artisan migrate:fresh --seed

# 4. make uploaded evidence publicly served
php artisan storage:link

# 5. serve (bind to 0.0.0.0 so the Android emulator can reach it)
php artisan serve --host=0.0.0.0 --port=8000
```

Dashboard: <http://localhost:8000>  ·  API base: <http://localhost:8000/api>

> The Android emulator reaches your machine at `http://10.0.2.2:8000`.

## Demo logins (password = `password` for everyone)

| Role | Email |
|------|-------|
| Super Admin | `admin@levoile.test` |
| Branch Director | `director@levoile.test` |
| Area Manager (does visits) | `area@levoile.test` |
| Store Manager | `store@levoile.test` |
| VM Manager | `vm@levoile.test` |
| OPS Manager | `ops@levoile.test` |
| Department Manager | `<dept>.manager@levoile.test` (e.g. `maintenance.manager@levoile.test`) |
| Department Employee | `<dept>.emp1@levoile.test` / `<dept>.emp2@levoile.test` |

Departments: `store, merchandise, stock_control, maintenance, operation, purchasing, it, cctv`.

---

## What's inside

- **Migrations** (`database/migrations`): roles, departments, users, branches,
  visit_templates, checklist_sections, checklist_questions, visits, visit_answers,
  visit_answer_evidence, visit_answer_selected_employees, tickets, ticket_updates,
  notifications, plus Sanctum tokens.
- **Models** (`app/Models`): full Eloquent relationships.
- **Business logic**: `app/Services/TicketService.php` (ticket creation from failed
  answers, routing, assignment, status transitions, notifications). Kept out of
  controllers/views.
- **Seeders** (`database/seeders`): 26 real branches, roles, departments, demo
  users for every role + 2 employees per department, three checklist templates
  built from the uploaded Excel sheets (Area Manager / Store Manager / VM scored),
  one **assigned (empty)** visit, one **completed read-only** visit reconstructed
  from `Visit San 27-4-2026.xlsx`, and demo tickets including **15 lamp changes**
  this month plus POS/CCTV/cleaning/stock issues.
- **REST API** (`routes/api.php`, `app/Http/Controllers/Api`): auth, visits flow,
  ticket flow, assignment, approval, department boards, notifications.
- **Dashboard** (`routes/web.php`, `app/Http/Controllers/Web`, `resources/views`):
  overview, branches (+ map preview & repeated problems), visits, ticket list,
  per-department kanban boards with an assign modal, ticket detail with timeline &
  approval, and reports.

## Key API endpoints

```
POST   /api/login                         {email,password,device_name}
GET    /api/me
POST   /api/logout
GET    /api/visits?status=open|old
GET    /api/visits/{id}
POST   /api/visits/{id}/checkin           {latitude,longitude,simulated}
POST   /api/visits/{id}/start
POST   /api/visits/{id}/answers           {checklist_question_id,result,comment,evidence[],employee_ids[]}
POST   /api/visits/{id}/evidence          multipart photo -> {path}
POST   /api/visits/{id}/submit            {general_comments}
GET    /api/tickets/mine
GET    /api/tickets/{id}
PATCH  /api/tickets/{id}/status           {status,note}
POST   /api/tickets/{id}/evidence         multipart photo, kind=before|after
POST   /api/tickets/{id}/send-for-approval
POST   /api/tickets/{id}/approve
POST   /api/tickets/{id}/reject           {note}
POST   /api/tickets/{id}/assign           {employee_id,priority}
GET    /api/departments/tickets?department_id=
GET    /api/departments/employees?department_id=
GET    /api/notifications
POST   /api/notifications/read-all
POST   /api/notifications/{id}/read
```

All except `/login` require `Authorization: Bearer <token>`.
