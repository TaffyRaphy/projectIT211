# Web-Based Equipment Management System (PHP + PostgreSQL)

This repository contains a role-based equipment management web app built with PHP, PostgreSQL, and vanilla JavaScript/CSS.

The implementation aligns with the project scope in `PROJECT_OVERVIEW.md`:

1. Authentication and role-based access (`admin`, `staff`, `maintenance`)
2. Equipment inventory management
3. Request and allocation workflow
4. Maintenance scheduling and completion
5. Reporting views for inventory, usage, and maintenance history

## Current Security and Access Model

- Login uses database-backed credential validation (`users.password_hash` + `password_verify`)
- Session handling is enabled via PHP sessions
- Authenticated user identity is stored in `$_SESSION['user']`
- Role checks are enforced server-side by `require_role(...)`
- Action handlers derive actor identity from session (not from query/form IDs)
- Logout is handled by `/api/actions/logout.php`

## Tech Stack

- PHP 8+
- PostgreSQL
- Vercel PHP runtime (`vercel-php`)
- Vanilla JavaScript for confirmation UX (`assets/app.js`)
- Shared design system stylesheet (`assets/style.css`)

## Project Structure

- `api/index.php` - login page
- `api/dashboard.php` - role-aware dashboard and navigation
- `api/equipment.php` - equipment inventory management (admin)
- `api/requests.php` - equipment requests/history (staff)
- `api/admin_requests.php` - approval/rejection/allocation (admin)
- `api/maintenance.php` - maintenance scheduling/completion (maintenance)
- `api/reports.php` - reporting summaries (admin)
- `api/actions/*.php` - form/action handlers
- `includes/bootstrap.php` - DB connection, auth/session helpers, utility functions
- `assets/style.css` - design system implementation
- `assets/app.js` - client-side confirm prompts

## Database Setup

1. Set environment variable `DATABASE_URL` (or `POSTGRES_URL`) to your PostgreSQL connection string.
2. Run schema and seed setup:

```bash
npm run db:setup
```

Seeded accounts (password for all):

- `admin@example.com` / `Pass123!`
- `staff@example.com` / `Pass123!`
- `maintenance@example.com` / `Pass123!`

## Local Run

```bash
php -S localhost:8000
```

Open `http://localhost:8000`.

## Route Map

- `/api/index.php` - login
- `/api/dashboard.php` - dashboard (requires login)
- `/api/equipment.php` - equipment (admin)
- `/api/requests.php` - request workflow (staff)
- `/api/admin_requests.php` - request approval/allocation (admin)
- `/api/maintenance.php` - maintenance workflow (maintenance)
- `/api/reports.php` - reports (admin)
- `/api/actions/logout.php` - logout

## DESIGN.md Compliance Check (Current)

Checked against `DESIGN.md` and implemented in `assets/style.css`:

- Tonal dark layering with no hard divider borders: implemented
- Primary/secondary/tertiary/error palette usage: implemented
- Glass/blur panel treatment for main container: implemented
- Typography system (`Manrope`, `Inter`, `Space Grotesk`): implemented
- Rounded, glowing CTA buttons with hover glow behavior: implemented
- Status chips and metric cards with accent glows: implemented
- Asymmetric dashboard metric layout (hero card + support cards): implemented
- Mobile responsiveness: implemented

Known browser limitation:

- Native select popup rendering can vary by OS/browser engine. Dark dropdown styling is applied, but the OS-level popup can still differ slightly.

## Deployment Notes (Vercel)

1. Framework preset: `Other`
2. Keep `DATABASE_URL` in environment variables
3. Deploy preview first, verify login/session and role routes, then promote to production
4. Do not re-run destructive DB setup against production data unless intended
