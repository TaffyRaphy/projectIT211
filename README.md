# Inventory System (HTML + JavaScript + PHP)

This project now includes a PHP + HTML + JavaScript implementation (while keeping the original Next.js source for rollback safety) and uses PostgreSQL.

## Implemented Workflow

1. User Login (role-aware workflow mode)
2. Equipment Management (admin)
3. Equipment Request and Allocation (staff + admin)
4. Maintenance Scheduling and Repair Logging (maintenance)

## Important Testing Note

Session-based code is intentionally omitted so the workflow can be checked first.
Role context is currently passed with query string values like `?as=admin`.

## Tech Stack

- PHP (server-side pages + action handlers)
- HTML + vanilla JavaScript
- PostgreSQL
- Plain HTML markup only (no CSS workflow design)

## Project Structure

- `index.php` - login page
- `dashboard.php` - workflow dashboard
- `equipment.php` - equipment management
- `requests.php` - staff requests
- `admin_requests.php` - admin approvals/rejections
- `maintenance.php` - maintenance scheduling/completion
- `reports.php` - summaries
- `actions/*.php` - workflow action handlers
- `includes/bootstrap.php` - shared DB/auth/helpers
- `assets/app.js` - frontend confirmation behavior

## Database Setup

1. Set `DATABASE_URL` to your PostgreSQL connection string.
2. Run schema and seed scripts:

```bash
npm run db:setup
```

Seeded accounts (password for all is `Pass123!`):

- admin@example.com
- staff@example.com
- maintenance@example.com

## Run Locally (PHP App)

```bash
php -S localhost:8000
```

Open `http://localhost:8000`.

## Routes (PHP)

- `/index.php` Login
- `/dashboard.php` Workflow dashboard
- `/equipment.php` Admin equipment management
- `/requests.php` Staff request creation/history
- `/admin_requests.php` Admin approval/allocation
- `/maintenance.php` Maintenance scheduling/completion
- `/reports.php` Query-based reporting

## Vercel Deployment

Before pushing to GitHub (important for your currently deployed Vercel app):

1. In Vercel Project Settings, disable Auto-Deploy (or use a Preview branch only) so production will not instantly switch.
2. Set Framework Preset to **Other** (PHP setup), not Next.js.
3. Keep `DATABASE_URL` in Vercel environment variables.
4. Commit and push, then deploy to Preview first and test all workflows.
5. Promote to Production only after preview passes.
6. Run one-time DB initialization (locally or with the same DB):

```bash
npm run db:setup
```

## Artifact

Detailed implementation artifact is in `WORKFLOW_ARTIFACT.md`.
