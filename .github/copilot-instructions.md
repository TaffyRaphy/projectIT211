# Equipment Management System — AI Assistant Instructions

> **Role-Based Equipment Management Web App** | PHP 8+ | PostgreSQL | Vercel Deployment
> For design system details, see [DESIGN.md](../DESIGN.md). For setup, see [README.md](../README.md).

## Quick Start for AI Assistants

### Goal
This is a **form-handler + role-gated dashboard system** for managing equipment inventory, requests, maintenance scheduling, and reports. No frameworks, no ORM—pure PDO, server-side rendering, and vanilla JS/CSS.

### Key Files & Entry Points
- **Login/Auth:** [api/index.php](../api/index.php) (entry point) → [includes/bootstrap.php](../includes/bootstrap.php) (helpers)
- **Dashboard:** [api/dashboard.php](../api/dashboard.php) (role-aware hub)
- **Form Actions:** [api/actions/](../api/actions/) (POST handlers, each file handles one action)
- **Styles:** [assets/style.css](../assets/style.css) (design system CSS variables)
- **Client JS:** [assets/app.js](../assets/app.js) (confirm dialogs, UX enhancements)

### Tech Stack
- **Backend:** PHP 8+ (Vercel `vercel-php@0.7.3`)
- **Database:** PostgreSQL (connection via `DATABASE_URL` env var)
- **Frontend:** Vanilla JavaScript + CSS (no frameworks)
- **Deployment:** Vercel (routes in [vercel.json](../vercel.json))

---

## Architecture & Patterns

### Authentication & Session Model

**Dual-Layer Design** (Session + HMAC-signed Cookie):
```php
// Session-based (server, per-window)
session_start();
$_SESSION['user'] = ['id', 'full_name', 'email', 'role'];

// Cookie-based (persistent, cross-window)
// Format: base64(JSON).HMAC_SHA256(payload) 
// Secret: APP_KEY env var or hash(DATABASE_URL)
```

**Key Functions:**
- `current_user(): ?array` — Returns user from session or restored from cookie
- `require_login(): array` — Aborts 403 if not authenticated
- `require_role(array $roles): string` — Aborts 403 if role not in list
- `login_user(array $user): void` — Sets session + creates signed cookie
- `logout_user(): void` — Clears session & expires cookie

**Security Constraints:**
- Passwords use `password_verify()` with bcrypt support (legacy SHA1/MD5 fallback)
- Session IDs regenerated on login
- Cookies marked `HttpOnly`, `Secure` (HTTPS), `SameSite=Lax`
- Actor identity always derived from `$_SESSION`, never from user input

### Role-Based Access Control

**Three Roles:**

| Role | Permissions | Pages |
|------|------------|-------|
| `admin` | Manage equipment, approve requests, allocate stock, view reports | `/equipment`, `/admin_requests`, `/reports` |
| `staff` | Create equipment requests, view request history | `/requests` |
| `maintenance` | Schedule maintenance, mark tasks complete | `/maintenance` |

**Enforcement Pattern** (at top of every page):
```php
$role = require_role(['admin']);  // Aborts 403 if not admin
// Page content follows
```

### Request Handling: POST → Action → Redirect

**Standard Workflow:**
1. User submits form → POST to `/api/actions/{action_name}.php`
2. Action handler validates input (using `post_int()`, `post_string()` helpers)
3. Derive actor ID from session (never from request data)
4. Execute database operation (or transaction for multi-table changes)
5. `redirect_to('/path?param=value')` → response with status alert

**Input Helpers** (all trim & validate):
```php
post_string('field_name')       // Trimmed string, null if missing
post_int('field_name')          // Validated int or null
post_float('field_name')        // Validated float or null
int_query_param('id')           // From $_GET
query_param('ok')               // String status from $_GET
```

**Response Pattern:**
```php
if ($equipmentId === null || $qtyRequested <= 0) {
    redirect_to('/api/requests.php', ['error' => 'Qty must be > 0']);
}
// Execute operation
db()->prepare('INSERT INTO equipment_requests ...')
    ->execute([':staff_id' => $staffId, ':equipment_id' => $equipmentId, ...]);
redirect_to('/api/requests.php', ['ok' => 'Request submitted']);
```

**Alert Display** (server-side rendered):
```html
<?php if ($ok !== ''): ?>
  <p class="alert alert-success"><?= h($ok) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="alert alert-error">Error: <?= h($error) ?></p>
<?php endif; ?>
```

### Database Patterns

**Connection** ([includes/bootstrap.php](../includes/bootstrap.php#L15-L56)):
- Parses `DATABASE_URL` (or `POSTGRES_URL`, `POSTGRES_PRISMA_URL` fallbacks)
- Single PDO instance (lazy; created on first `db()` call)
- SSL mode: `require` by default
- Fetch mode: associative array

**Query Pattern:**
```php
// Simple query
$rows = db()->query("SELECT * FROM equipment")->fetchAll();

// Parameterized (always for user input)
$stmt = db()->prepare("SELECT * FROM equipment WHERE id = :id");
$stmt->execute(['id' => $equipmentId]);
$item = $stmt->fetch();
```

**Transaction Pattern** (for multi-table operations, see [api/actions/request_approve.php](../api/actions/request_approve.php#L31-L78)):
```php
$pdo = db();
$pdo->beginTransaction();
try {
    // Use FOR UPDATE to lock rows
    $stmt = $pdo->prepare('SELECT ... WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $requestId]);
    
    // Modify related tables
    $pdo->prepare('UPDATE equipment SET qty_available = ...')->execute(...);
    $pdo->prepare('UPDATE equipment_requests SET status = ...')->execute(...);
    
    $pdo->commit();
    redirect_to('/prev_page', ['ok' => 'Approved']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect_to('/prev_page', ['error' => 'Operation failed']);
}
```

**Key Tables** (inferred from usage):
- `users` — id, full_name, email, role, password_hash
- `equipment` — id, code, name, category, status, quantity_total, quantity_available, location, next_maintenance_date
- `equipment_requests` — id, staff_id, equipment_id, qty_requested, purpose, status, requested_at, reviewed_by, reviewed_at
- `allocations` — id, request_id, equipment_id, staff_id, qty_allocated, allocated_by, checkout_date, expected_return_date
- `maintenance_logs` — id, equipment_id, maintenance_type, schedule_date, completed_date, status, cost, notes

---

## Development Conventions

### File Structure & Naming
```
api/
  ├─ index.php              # Login page (entry point)
  ├─ dashboard.php          # Role-aware dashboard & navigation
  ├─ equipment.php          # Admin: manage equipment inventory
  ├─ requests.php           # Staff: create & view requests
  ├─ admin_requests.php     # Admin: approve & allocate requests
  ├─ maintenance.php        # Maintenance: schedule & complete tasks
  ├─ health.php             # Server health check (simple OK response)
  ├─ reports.php            # Admin: statistics & summaries
  └─ actions/
      ├─ login.php          # Validate credentials, set session
      ├─ logout.php         # Clear session, expire cookie
      ├─ equipment_create.php
      ├─ equipment_update.php
      ├─ request_create.php
      ├─ request_approve.php  # Multi-table transaction
      ├─ request_reject.php
      └─ maintenance_*.php

includes/
  └─ bootstrap.php          # DB connection, auth/session helpers, utilities

assets/
  ├─ style.css              # Design system (CSS variables, components)
  └─ app.js                 # Client-side UX (confirm dialogs, pwd visibility)
```

### Code Standards

**Every PHP file must start with:**
```php
<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';  // Adjust path based on depth
// Or (for files one level deep):
require dirname(__DIR__) . '/includes/bootstrap.php';
```

**Type Hints & Declarations:**
```php
function validate_input(string $email, int $qty = 0): ?string {
    return $email && $qty > 0 ? null : 'Invalid input';
}
```

**HTML Output Escaping:**
```php
<?= h($userInput) ?>  // Always escape, never raw output
```

**SQL Security:**
- Use parameterized queries for all user input
- Lock rows when reading for modification (`FOR UPDATE`)
- Validate actor identity from session before operations

**Client-Side Confirmation** ([assets/app.js](../assets/app.js)):
```html
<!-- Add data-confirm attribute to any form or link -->
<form method="post" action="/api/actions/equipment_delete.php" data-confirm="Delete this equipment?">
    <button>Delete</button>
</form>
<!-- JavaScript automatically prevents submission without user confirmation -->
```

---

## Common Development Tasks

### Adding a New Admin Feature

1. **Create page** → [api/new_feature.php](../api/new_feature.php)
   ```php
   <?php declare(strict_types=1);
   require dirname(__DIR__) . '/includes/bootstrap.php';
   $page_role = require_role(['admin']);
   // Query data, render form
   ?>
   ```

2. **Create action handler** → [api/actions/new_feature_create.php](../api/actions/new_feature_create.php)
   ```php
   <?php declare(strict_types=1);
   require dirname(__DIR__, 2) . '/includes/bootstrap.php';
   require_role(['admin']);
   $adminId = (int) require_login()['id'];
   // Validate input, execute SQL, redirect
   ```

3. **Link from dashboard** → [api/dashboard.php](../api/dashboard.php)
   ```php
   <?php if ($role === 'admin'): ?>
     <a href="/api/new_feature.php">New Feature</a>
   <?php endif; ?>
   ```

### Approving Equipment Requests (Multi-Table Transaction Pattern)

See [api/actions/request_approve.php](../api/actions/request_approve.php) for full example:
1. Lock request row with `FOR UPDATE`
2. Validate stock availability
3. Decrement equipment quantity
4. Update request status
5. Insert allocation record
6. Commit or rollback on error

### Adding a Design System Component

Refer to [DESIGN.md](../DESIGN.md) for:
- Color palette (`primary`, `secondary`, `tertiary`, `error`)
- Typography (Manrope, Inter, Space Grotesk)
- Elevation & depth rules (no hard borders, use tonal shifts)
- Component examples (buttons, cards, chips, inputs)

All CSS variables defined in [assets/style.css](../assets/style.css). Use `var(--primary)` in new components.

---

## Deployment & Environment

### Local Development
```bash
# Start PHP server
php -S localhost:8000

# Database setup (seeded with test accounts)
npm run db:setup

# Test accounts (password: Pass123! for all):
# - admin@example.com
# - staff@example.com@example.com
# - maintenance@example.com
```

### Vercel Deployment
- **Framework:** Other
- **Environment Variables:** Set `DATABASE_URL` (PostgreSQL connection string)
- **Routes:** Handled by [vercel.json](../vercel.json)
- **⚠️ CRITICAL:** Only run `npm run db:setup` on fresh databases; it includes destructive operations

### Environment Variables
- `DATABASE_URL` — PostgreSQL connection string (required)
- `APP_KEY` — Custom HMAC secret for signed cookies (optional; defaults to hash of DATABASE_URL)
- `POSTGRES_URL` / `POSTGRES_PRISMA_URL` — Fallback connection strings (Vercel)

---

## Security & Best Practices

✅ **Always Enforce:**
- Parameterized SQL queries (prevent injection)
- Actor identity from `$_SESSION` (never user input)
- `require_role()` at page/action top (prevent unauthorized access)
- HTML escaping with `h()` function
- `declare(strict_types=1)` in every PHP file
- Session regeneration on login (prevents fixation)
- HMAC-signed cookies (prevents tampering)
- Transaction support for multi-table operations

⚠️ **Anti-Patterns (Avoid):**
- Direct `$_GET`/`$_POST` without validation
- Trusting user-provided IDs for actor identity
- Unescaped user output in HTML
- No transaction support for multi-step operations
- Hardcoded credentials or secrets

---

## Testing Checklist

When adding features:
1. **Auth:** Test that unauthenticated users are redirected to login
2. **Roles:** Test that other roles cannot access pages/actions
3. **Input:** Test invalid input (empty, null, negative) — should redirect with error
4. **Data Integrity:** Test concurrent requests don't cause stock conflicts (use transactions)
5. **UI:** Verify status alerts appear after form submission
6. **Design:** Check component uses correct colors, typography, spacing from [DESIGN.md](../DESIGN.md)

---

## Reference Links

- **README.md** — Setup, route map, deployment notes
- **DESIGN.md** — Design system, color palette, component patterns
- **vercel.json** — Route configuration for Vercel
- **includes/bootstrap.php** — All utility functions (auth, DB, input helpers)
- **api/actions/request_approve.php** — Transaction pattern example
- **assets/style.css** — CSS variables & design system implementation
- **assets/app.js** — Client-side confirmation & UX enhancements

---

## Quick Reference: Function Signatures

### Auth Functions
```php
current_user(): ?array                               // Returns user from session/cookie
require_login(): array                               // Aborts 403 if not authenticated
require_role(array $roles): string                   // Aborts 403 if role not in list
login_user(array $user): void                        // Sets session + signed cookie
logout_user(): void                                  // Clears session + cookie
current_role(string $default = ''): string           // Returns role or default
```

### Input Helpers
```php
post_string(string $key, string $default = ''): string    // Trimmed POST string
post_int(string $key, ?int $default = null): ?int         // Validated POST int
post_float(string $key, ?float $default = null): ?float   // Validated POST float
int_query_param(string $key, ?int $default = null): ?int  // From $_GET
query_param(string $key, string $default = ''): string    // From $_GET
h(string $text): string                             // HTML escape (htmlspecialchars)
```

### Database & Redirect
```php
db(): PDO                                            // Lazy PDO singleton
redirect_to(string $path, array $params = []): void // Redirect with GET params (alerts)
```

---

## Questions? Help!

When you encounter something unclear:
1. Check [README.md](../README.md) for setup & deployment
2. Check [DESIGN.md](../DESIGN.md) for UI/styling decisions
3. Examine similar action files (e.g., `request_approve.php` for transactions)
4. Look at [includes/bootstrap.php](../includes/bootstrap.php) for utility implementations
5. Ask the user for clarification on business logic or requirements

---

**Last Updated:** April 2026  
**Project Type:** PHP 8+ REST API + Server-Side Rendered Dashboard  
**Deployment:** Vercel (vercel-php@0.7.3)
