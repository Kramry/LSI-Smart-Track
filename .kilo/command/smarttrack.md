---
description: Build or extend the LSI SmartTrack QR attendance and TESDA trainee monitoring system (LavaLust PHP MVC)
argument-hint: <feature or task, e.g. "add a session-level attendance report for instructors">
---

You are extending **LSI SmartTrack** — a QR-enabled attendance and TESDA trainee monitoring system for Mindoro State University, College of Computer Studies (Calapan City, Oriental Mindoro). Three account roles exist: **trainee**, **instructor**, and **administrator/staff**.

Requested work for this run:

$ARGUMENTS

If `$ARGUMENTS` is empty, treat the task as: audit the running system against the design document, report gaps, and implement the highest-value missing piece end to end.

---

## 1. Ground yourself first

Read these before writing any code. Do not guess the framework API, the schema, or the permission model.

- @LavaLust-dev-v4/docs/SMARTTRACK-SYSTEM-DESIGN.md — functional requirements, sitemap, wireframes, ERD, table list, demonstration checklist.
- @LavaLust-dev-v4/app/config/routes.php — every route and its controller action.
- @LavaLust-dev-v4/app/controllers/SmartTrack.php — the single controller; the source of truth for auth, CSRF, validation, permissions, and rendering.
- @LavaLust-dev-v4/app/models/SmartTrack_model.php — the single model; all SQL lives here.
- @LavaLust-dev-v4/app/views/smarttrack.php — the single view; switches on `$page`.
- @LavaLust-dev-v4/public/assets/js/smarttrack.js and @LavaLust-dev-v4/public/assets/css/smarttrack.css — frontend behavior and styling.
- @LavaLust-dev-v4/lsi_smarttrack-database.sql — canonical schema; @LavaLust-dev-v4/lsi_smarttrack-upgrade.sql — additive migration for existing databases.
- @LavaLust-dev-v4/scheme/kernel/Controller.php, @LavaLust-dev-v4/scheme/kernel/Model.php, @LavaLust-dev-v4/scheme/kernel/Router.php — framework core, read only when you need exact base-class behavior.

## 2. Stack constraints (hard rules)

- **Framework:** the in-repo LavaLust v4 MVC in `LavaLust-dev-v4/scheme/`. It is *not* Laravel, CodeIgniter, or Slim. Do not import APIs that do not exist in `scheme/`.
- **No new dependencies.** No Composer packages, no npm, no build step, no bundler, no framework CSS/JS libraries. Frontend is hand-written CSS and plain ES6 in `public/assets/js/`.
- **Single controller, single model, single view.** The existing app deliberately uses one `SmartTrack` controller with a generic `module($section)` + `resourceDefinition()`/`fieldsFor()` pattern, one `SmartTrack_model` with all queries, and one `app/views/smarttrack.php` that branches on `$page`. Extend that pattern instead of adding parallel architecture.
- **PHP 7.4+/8 compatible**, MySQL/MariaDB, `PDO` prepared statements only. Never interpolate user input into SQL.
- **Local-first:** the app must still run on a plain Laragon/XAMPP `public/` document root with `.env` database credentials.

## 3. Framework API actually in use

```php
class SmartTrack extends Controller {
    public function before_action() { /* runs before every action */ }
}
```

- `$this->call->database()` → PDO wrapper; `$this->call->library('session')`; `$this->call->helper('url')`; `$this->call->view('smarttrack', $data)`.
- Constants: `APP_DIR`, `ROOT_DIR`.
- Helpers/functions: `redirect($url)`, `show_404()`.
- `$_POST` / `$_GET` / `$_FILES` are read directly; the controller wraps them in `input($key)`.
- Session: `$this->session->set_userdata()`, `userdata()`, `unset_userdata()`, `set_flashdata()`, `flashdata()`, `sess_destroy()`.

## 4. Data model (12 tables)

`users`, `trainees`, `training_programs`, `training_classes`, `training_schedules`, `class_enrollments`, `attendance_records`, `trainee_documents`, `announcements`, `inquiries`, `notifications`, `activity_logs`.

Key relationships: `trainees.user_id → users.user_id`; `training_classes.instructor_id → users.user_id`; `training_classes.program_id → training_programs.program_id`; `class_enrollments(class_id, trainee_id)`; `attendance_records(trainee_id, class_id, schedule_id, attendance_date, time_in, time_out, status, remarks, recorded_by)`; `trainee_documents(trainee_id, class_id, uploaded_by, document_name, file_path, upload_date, status, access_level)`; `inquiries(trainee_id, subject, message, reply, status)`; `activity_logs(user_id, action, details)`.

- Passwords are hashes (`password_hash` / `password_verify`), never plaintext.
- `trainees.qr_code` is the printable/scannable ID, generated as `LSI-` + 12 uppercase hex chars.
- Schema changes: edit `lsi_smarttrack-database.sql` for fresh installs **and** add idempotent DDL to `lsi_smarttrack-upgrade.sql` for existing databases. Never drop or rewrite existing tables.

## 5. Roles and permissions

| Section | administrator | staff | instructor | trainee |
| --- | --- | --- | --- | --- |
| users | read/write | read/write | – | – |
| trainees | read/write | read/write | read | – |
| programs, classes, schedules, enrollments | read/write | read/write | read | – |
| attendance | read/write | read/write | read/write (own classes) | read (own) |
| documents | read/write | read/write | read/write (own classes) | read/write (own) |
| announcements | read/write | read/write | read/write | read |
| inquiries | read/write | read/write | read/write | create + read (own) |
| activity log | read | read | – | – |

Rules that must hold:
- `canAccess($section)` gates visibility; `canManage($section)` gates writes; `recordIsVisible()` gates individual rows (instructors see only their classes, trainees only their own records).
- Instructors may record attendance only for classes where `training_classes.instructor_id = user_id`.
- Only `administrator` may edit or deactivate another `administrator` account.
- Nobody may deactivate or delete their own account.
- Trainee inquiries are forced to `trainee_id = <own trainee>`; trainees can never set `reply` or `status` themselves.
- Document `access_level` is one of `private`, `class`, `staff`; trainees are always forced to `private`; `class` documents are visible to the class's instructor and enrolled trainees only.

## 6. Controller conventions (mirror these exactly)

- Single controller class `SmartTrack` in `app/controllers/SmartTrack.php`; every action starts with `requireUser()` when authentication is needed and `validCsrf()` on every state-changing POST.
- Render through the private `render($page, $data)` wrapper — it injects `page`, `user`, `csrf`, `navigation`, `notice`, `flash_error`, `setup_required`, `app_url`. Never call the view directly.
- User feedback: `notice($message)` for success, `moduleWithError($section, $message, $id)` for validation failures, `deny($message)` for permission failures (redirects to dashboard). All messages are plain strings shown in the UI; never echo raw exceptions.
- Generic section handling: register a new managed section in **all three** of `resourceDefinition()` (title, table, primary key, display columns), `fieldsFor()` (field definitions: `name`, `label`, `type` ∈ `text|password|textarea|number|select|options|date|time`, `required`, `values`, `options`), and the role maps inside `canAccess()` / `canManage()` / the `navigation` array in `render()`. Missing one of these is an incomplete feature.
- Validation in `save($section)`: required checks, `select` values must be in the allow-list, `options` values must exist in the referenced table, `date` must be exactly `Y-m-d`, `time` must match `HH:MM[:SS]`, per-field max lengths, and cross-field rules (time-out ≥ time-in, end date ≥ start date, session end > session start, trainee must be enrolled in the class, session must belong to that class and date).
- `login()` regenerates the session id and the CSRF token on success; `logout()` is POST-only and requires a valid CSRF token.
- First-run bootstrap: `/setup` creates the initial administrator only while `userCount() === 0`. No default credentials are ever shipped.

## 7. Model conventions

All SQL lives in `app/models/SmartTrack_model.php`:

- `rows($sql, $values)`, `row($sql, $values)`, `execute($sql, $values)` — the only way to touch the database, always with bound parameters.
- `records($section, $user)` returns the rows the user may see; `options($section, $user)` returns `id`/`label` pairs for select fields; `saveRecord($table, $primaryKey, $data, $id)` upserts; `findRecord()`; `deleteRecord($table, $primaryKey, $id, $deactivate)` (users and trainees are deactivated, never hard-deleted); `logActivity($userId, $action, $details)` writes to `activity_logs` for every create, update, delete, upload, attendance scan, and profile change.
- `scanAttendance($qrCode, $action, $classId, $scheduleId, $userId)` is the single source of truth for check-in/check-out; it must be idempotent per day, class, and trainee.
- `attendanceReport($start, $end, $user)` backs the CSV export.

## 8. View and frontend conventions

- `app/views/smarttrack.php` is a single template that branches on `$page` (`login`, `setup`, `register`, `dashboard`, `module`, `profile`) and, for `module`, on `$section`.
- Escape every dynamic value with the `$e()` closure already defined at the top of the view (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`). Never print raw user input.
- Reuse the existing CSS vocabulary: `.portal-shell`, `.topbar`, `.section-nav`, `.workspace`, `.panel`, `.panel-heading`, `.stat-grid`, `.stat`, `.table-scroll`, `.notice success|error`, `.empty-state`, `.button primary|secondary`, `.text-button`, `.inline-link`, `.eyebrow`, `.muted`, `.status`, `.field`, `.form-stack`. Add new rules to `public/assets/css/smarttrack.css`; do not inline `<style>` blocks.
- Every POST form carries `<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">`.
- Interactive behavior lives in `public/assets/js/smarttrack.js` using `data-*` attributes: `data-confirm` (destructive-action confirmation), `data-table-search` (client-side table filter), `data-qr` (QR rendering), `data-print-qr`, `data-print-report`, `data-document-scope`, `data-trainee-field`, `data-start-scanner` (Html5Qrcode camera scan with manual-entry fallback). Follow the same pattern for new behavior — no inline `onclick` handlers.
- Styling intent: institutional and calm — cream/ink palette, serif display headings, uppercase eyebrow labels, generous whitespace. Match the existing look rather than inventing a new one.

## 9. Security requirements (non-negotiable)

- CSRF token on every state-changing request; validate with `hash_equals`.
- `password_hash`/`password_verify` with `PASSWORD_DEFAULT`; minimum 10 characters; usernames `^[A-Za-z0-9_.-]{4,50}$`.
- Uploads: store under `runtime/uploads/private/` (outside the web root), random 20-byte filename, extension derived from the detected MIME type (`finfo`), allow-list PDF/JPG/PNG/DOCX, 10 MB cap, and always resolve the path with `realpath()` and confirm it stays inside the private root before serving or deleting.
- Authorize every request server-side. Never rely on the hidden form field or the navigation to enforce permissions.
- Never log or echo credentials, tokens, or raw SQL errors to the browser.
- Never commit `.env`, real database credentials, or uploaded files.

## 10. Build order for a new feature

1. Schema (if needed) — update `lsi_smarttrack-database.sql` and `lsi_smarttrack-upgrade.sql`.
2. Model methods in `SmartTrack_model.php` (all SQL here).
3. Controller action or a new `resourceDefinition`/`fieldsFor` entry in `SmartTrack.php`, including the permission maps.
4. Route in `app/config/routes.php` if a new URL is needed.
5. View block in `app/views/smarttrack.php` (or a new `$page` branch).
6. CSS in `public/assets/css/smarttrack.css` and JS in `public/assets/js/smarttrack.js`.
7. Update `docs/SMARTTRACK-SYSTEM-DESIGN.md` if the sitemap, permissions, or data model changed.

## 11. Definition of done

- `php -l` passes on every touched PHP file.
- Every new page/form handles the invalid case gracefully (validation message, no partial write, no stack trace).
- Role matrix respected: check the new action as all four roles.
- No regressions in the existing flows: sign in, first-run setup, record CRUD, QR check-in/check-out, CSV export, document upload/download, announcement publish, inquiry reply.
- The demonstration checklist at the end of `docs/SMARTTRACK-SYSTEM-DESIGN.md` still passes.

## 12. Output contract

When you finish, report concisely:
1. What you implemented, in one short paragraph.
2. Files changed, grouped by layer (schema / model / controller / routes / view / css / js / docs).
3. The new routes and the roles allowed to use them.
4. How to verify it (exact steps, plus the SQL import or `.env` change if the database was touched).
5. Anything deliberately left out, and why.
