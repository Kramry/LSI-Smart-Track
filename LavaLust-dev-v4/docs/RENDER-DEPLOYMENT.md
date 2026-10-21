# Render Deployment

Guide for publishing LSI SmartTrack on Render. The application is a PHP MVC app
(LavaLust v4) with a MySQL database, an Apache front controller, and no build step.

## Why `/login` used to return 404

Three separate problems had to be fixed together:

1. **Apache was not rewriting the request.** The old `Dockerfile` pointed the
   document root at `public/` but left `AllowOverride None` on that directory, so
   `public/.htaccess` was silently ignored and Apache answered every
   non-existent path (such as `/login`) with its own 404 page. The rewrite now
   lives in the virtual host (`docker/apache-smarttrack.conf`) and does not
   depend on `.htaccess` support at all.
2. **`GET /login` was not a route.** Only `POST /login` existed, so even with a
   working rewrite the URL `/login` produced an application 404. A `GET /login`
   route now renders the sign-in page.
3. **A `.env` file inside the image overrode the Render environment.** The
   framework loads `.env` with `putenv()`, so a baked-in `.env` (with
   `DB_HOST=127.0.0.1` and an empty `APP_KEY`) silently replaced the values
   configured in the Render dashboard. The image no longer contains a `.env`.

## Service configuration

Render → **New** → **Web Service** → connect the repository.

| Setting | Value |
| --- | --- |
| Root Directory | `LavaLust-dev-v4` (the folder that holds `Dockerfile`) |
| Runtime | Docker |
| Dockerfile Path | `./Dockerfile` |
| Docker Context | `.` |
| Health Check Path | `/login` |

`render.yaml` encodes the same configuration and can be used as a Blueprint
(**New** → **Blueprint** → select the repository).

## Environment variables

Set these in **Render → Environment** (they are not read from a file):

| Key | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_KEY` | any long random string (required: sessions refuse to start without it) |
| `DB_DRIVER` | `mysql` |
| `DB_HOST` | host of your MySQL 8 server |
| `DB_PORT` | `3306` (or the port your provider gave you) |
| `DB_USER` | database user |
| `DB_PASSWORD` | database password |
| `DB_NAME` | database name |
| `DB_CHARSET` | `utf8mb4` |
| `BASE_URL` | `https://lsi-smart-track.onrender.com` |

Render does not offer a managed MySQL service, so point `DB_*` at an external
MySQL 8 host (Aiven, PlanetScale, DigitalOcean, or a private server). The
schema in `lsi_smarttrack-database.sql` is MySQL specific and is not
PostgreSQL compatible.

## Database

Import `lsi_smarttrack-database.sql` into the target database before the first
request. For an existing database that already contains `training_programs`,
import `lsi_smarttrack-upgrade.sql` instead; it only adds the missing tables.

## Verify the deployment

1. `https://lsi-smart-track.onrender.com/` shows the sign-in page.
2. `https://lsi-smart-track.onrender.com/login` shows the same sign-in page.
3. `https://lsi-smart-track.onrender.com/assets/css/smarttrack.css` returns the
   stylesheet (proves static files are served and not routed to PHP).
4. `https://lsi-smart-track.onrender.com/app/config/routes.php` returns 403.
5. Sign in and walk the dashboard, a management section, an attendance scan, a
   document upload, and a CSV export.

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Apache 404 page | Rewrite not applied | Rebuild from the current `Dockerfile`; check that `docker/apache-smarttrack.conf` was copied into the image |
| Application 404 page | The path has no route | Add the route in `app/config/routes.php` |
| "Session security requires an encryption key" | `APP_KEY` missing | Set `APP_KEY` in the Render environment |
| "could not find driver" | Wrong `DB_DRIVER` or missing PDO driver | Use `DB_DRIVER=mysql`; the image installs `pdo_mysql` |
| Login succeeds then the session is lost | A `.env` file in the image | The image removes `.env`; rebuild after merging the current `Dockerfile` |
| 500 on every page | `APP_ENV` left at `development` with warnings | Set `APP_ENV=production` |

Apache writes its error and access logs to stdout/stderr, so they appear in the
Render dashboard under **Logs**.
