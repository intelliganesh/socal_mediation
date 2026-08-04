# SoCal Mediation Center

Laravel 12 application for managing consultations for two related applications:

- SoCal Mediation Center
- Legal Consultation

The project includes a shared admin panel, REST APIs, Swagger API documentation, booking availability, payment-link handling, Zoom meeting-link handling, and Outlook calendar sync hooks.

## Requirements

- PHP 8.2 or newer
- Composer
- Node.js and npm
- MySQL or MariaDB
- XAMPP 8.2 is supported for local development

## Local Setup

1. Clone or copy the project into your local web directory.

   Example XAMPP path:

   ```bash
   C:\xampp\htdocs\socal_mediation_center
   ```

2. Install PHP dependencies.

   ```bash
   composer install
   ```

3. Install frontend dependencies.

   ```bash
   npm install
   ```

4. Create the environment file.

   ```bash
   copy .env.example .env
   ```

5. Generate the Laravel app key.

   ```bash
   php artisan key:generate
   ```

6. Create the local database.

   Default `.env.example` database settings:

   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3307
   DB_DATABASE=socal_mediation_center
   DB_USERNAME=root
   DB_PASSWORD=
   ```

   If your local MySQL runs on `3306`, update `DB_PORT=3306`.

7. Run migrations and seed sample data.

   ```bash
   php artisan migrate --seed
   ```

8. Generate Swagger documentation.

   ```bash
   php artisan l5-swagger:generate
   ```

9. Build frontend assets.

   For local development with hot reload:

   ```bash
   npm run dev
   ```

   For production-style compiled assets:

   ```bash
   npm run build
   ```

10. Start the Laravel development server.

    ```bash
    php artisan serve
    ```

    The app will usually be available at:

    ```text
    http://127.0.0.1:8000
    ```

## Admin Login

The database seeder creates a local admin user:

```text
URL:      http://127.0.0.1:8000/admin/login
Email:    admin@socal.test
Password: password
```

## Main Local URLs

```text
Admin panel:        http://127.0.0.1:8000/admin
Booking calendar:   http://127.0.0.1:8000/admin/calendar
Swagger docs:       http://127.0.0.1:8000/api/documentation
API base path:      http://127.0.0.1:8000/api/v1
```

## API Endpoints

Common API routes:

```text
GET  /api/v1/consultation-types
GET  /api/v1/legal-services
GET  /api/v1/availability
POST /api/v1/consultations/draft
GET  /api/v1/consultations/{consultation}
POST /api/v1/consultations/{consultation}/complete
POST /api/v1/payments/converge/webhook
```

Draft flow:

- Use `POST /api/v1/consultations/draft` to create or update a consultation draft.
- Use `POST /api/v1/consultations/{consultation}/complete` to schedule the booking, create payment requests, and send payment links.
- Use `GET /api/v1/availability?consultation_type_id=1&date=2026-08-14` to fetch slots for one selected date. The legacy `month=YYYY-MM` query still works for older clients.

## Third-Party Configuration

The application is ready for sandbox and production configuration through `.env`.

Payment gateway:

```env
CONVERGE_ENABLED=false
CONVERGE_MODE=sandbox
CONVERGE_SANDBOX_BASE_URL=https://api.demo.convergepay.com
CONVERGE_PRODUCTION_BASE_URL=https://api.convergepay.com
CONVERGE_SANDBOX_HPP_BASE_URL=https://api.demo.convergepay.com
CONVERGE_PRODUCTION_HPP_BASE_URL=https://api.convergepay.com
CONVERGE_MERCHANT_ID=
CONVERGE_USER_ID=
CONVERGE_PIN=
CONVERGE_WEBHOOK_SECRET=
CONVERGE_HTTP_TIMEOUT_SECONDS=90
CONVERGE_RETURN_URL="${APP_URL}/api/v1/payments/converge/return"
```

Payment emails contain a permanent signed application checkout URL. Opening
that URL requests a fresh one-time Converge session token and immediately posts
the token to the Hosted Payment Page. Tokens are not stored or sent by email.

In both the Converge demo and production accounts, configure the Hosted Payment
Page redirect URL to exactly match `CONVERGE_RETURN_URL`. The return endpoint
does not trust browser-supplied payment status; it queries Converge using the
payment request UUID before marking a payment paid. Scheduled XML polling is
the fallback when the return verification is temporarily unavailable.

To test paid, Zoom, and Outlook flows without contacting Converge, enable the
non-production payment simulation API. It is unavailable in production and
must not be enabled at the same time as Converge.

```env
CONVERGE_ENABLED=false
CONVERGE_PAYMENT_SYNC_ENABLED=false
PAYMENT_SIMULATION_ENABLED=true
PAYMENT_SIMULATION_KEY=use-a-long-random-development-secret
```

Complete one payment share at a time with the payment request UUID returned by
the consultation API:

```http
POST /api/v1/testing/payments/{payment_request_uuid}/complete
X-Payment-Simulation-Key: use-a-long-random-development-secret
```

The final paid share runs the normal booking finalizer, including Zoom meeting
creation and Outlook sync when their integration toggles are enabled. Restore
the real gateway by enabling Converge and disabling payment simulation.

Zoom:

```env
ZOOM_MEETINGS_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_OAUTH_BASE_URL=https://zoom.us
ZOOM_BASE_URL=https://api.zoom.us/v2
ZOOM_JOIN_BASE_URL=https://zoom.us
```

Outlook:

```env
OUTLOOK_SYNC_ENABLED=false
OUTLOOK_TENANT_ID=
OUTLOOK_CLIENT_ID=
OUTLOOK_CLIENT_SECRET=
OUTLOOK_LOGIN_BASE_URL=https://login.microsoftonline.com
OUTLOOK_SOCAL_USER_ID=
OUTLOOK_SOCAL_CALENDAR_ID=
OUTLOOK_LEGAL_USER_ID=
OUTLOOK_LEGAL_CALENDAR_ID=
OUTLOOK_BASE_URL=https://graph.microsoft.com/v1.0
```

The integration toggles default to `false` so local and development environments do not accidentally call production services. Enable them only in the environment that should perform live integration calls.

Required Zoom OAuth app scopes for live meeting generation:

```text
meeting:write:meeting
meeting:write:meeting:admin
```

For Outlook app-only sync, set `OUTLOOK_SOCAL_USER_ID` and `OUTLOOK_LEGAL_USER_ID` to the mailbox user principal name or Microsoft Graph user id that owns each calendar. The calendar id values identify the specific calendars under those users.

Local email defaults to the log mailer:

```env
MAIL_MAILER=log
```

Email content will be written to Laravel logs instead of being sent externally.

## Booking Configuration

Booking behavior is controlled with these `.env` values:

```env
APP_TIMEZONE=America/Los_Angeles
BOOKING_TIMEZONE=America/Los_Angeles
BOOKING_DAY_START=09:00
BOOKING_DAY_END=17:00
```

`APP_TIMEZONE` controls Laravel's default timezone. `BOOKING_TIMEZONE` controls the timezone used for slot generation, Outlook calendar windows, Zoom meeting payloads, and booking emails. Slot spacing is based on consultation type duration. Existing consultations and busy Outlook events are checked to prevent overlap.

## Useful Commands

Run the full test suite:

```bash
php artisan test
```

Clear cached framework files:

```bash
php artisan optimize:clear
```

Rebuild the database with sample data:

```bash
php artisan migrate:fresh --seed
```

Regenerate Swagger docs:

```bash
php artisan l5-swagger:generate
```

Run Laravel server, queue listener, logs, and Vite together:

```bash
composer run dev
```

## Troubleshooting

If the database connection fails, confirm MySQL is running and that `DB_PORT` matches your local MySQL port.

If Swagger docs do not show new API changes, run:

```bash
php artisan l5-swagger:generate
```

If styles look outdated, rebuild assets:

```bash
npm run build
```

If seeded login does not work, refresh the database:

```bash
php artisan migrate:fresh --seed
```
