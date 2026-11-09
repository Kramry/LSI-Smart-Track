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
| `SMARTTRACK_FRONTEND_URL` | `https://lsi-vue-frontend.onrender.com` |
| `SMARTTRACK_FRONTEND_ORIGIN` | `https://lsi-vue-frontend.onrender.com` |
| `SESSION_COOKIE_SAMESITE` | `None` |
| `SESSION_COOKIE_SECURE` | `true` |
| `XENDIT_SECRET_KEY` | Xendit secret API key (backend environment only) |
| `XENDIT_WEBHOOK_TOKEN` | Callback verification token configured for the Xendit webhook |

Render does not offer a managed MySQL service, so point `DB_*` at an external
MySQL 8 host (Aiven, PlanetScale, DigitalOcean, or a private server). The
schema in `lsi_smarttrack-database.sql` is MySQL specific and is not
PostgreSQL compatible.

## Xendit payment URLs

The hosted checkout return URLs are built from the backend environment variable
`SMARTTRACK_FRONTEND_URL` (frontend origin only). Set it to
`https://lsi-vue-frontend.onrender.com`. Success returns to
`https://lsi-vue-frontend.onrender.com/payments?payment=success` with an
additional `reference_id` query parameter so the signed-in frontend can ask
the backend to verify that specific payment. Cancellation returns to
`https://lsi-vue-frontend.onrender.com/payments?payment=cancel`. The query
string never marks a payment paid.

In the Xendit Dashboard, configure the webhook separately as
`https://lsi-smart-track.onrender.com/api/payments/xendit/webhook` and set the
same callback token in Render's `XENDIT_WEBHOOK_TOKEN`. The backend retrieves
the session and its payment request from Xendit and validates reference,
amount, currency, and successful status before recording a payment. Duplicate
callbacks are safe to retry.

For local development, set `SMARTTRACK_FRONTEND_URL=http://localhost:5176` in
the backend `.env` and set `SMARTTRACK_FRONTEND_ORIGIN=http://localhost:5176`.
Set `API_PROXY_TARGET=http://localhost:8000` in the Vue `.env.development`.
Start the PHP backend on port `8000` and the Vue dev server. Run
`ngrok http 8000` and temporarily configure
`https://<current-ngrok-host>/api/payments/xendit/webhook` in the Xendit
Dashboard. Replace the callback URL whenever ngrok assigns a new host; do not
put a temporary ngrok hostname in source or production configuration. The
Xendit secret key and callback token belong only in the backend environment.

Before deploying this payment update to an existing database, run
`xendit-payment-upgrade.sql` once to add the nullable unique provider
transaction ID column without deleting existing payment records.

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
